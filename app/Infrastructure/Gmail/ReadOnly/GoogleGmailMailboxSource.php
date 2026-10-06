<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\ReadOnly;

use App\Domain\Capture\Contracts\MailboxSource;
use App\Domain\Capture\Data\AccessToken;
use App\Domain\Capture\Data\GmailMailboxProfile;
use App\Domain\Capture\Data\HistoryPage;
use App\Domain\Capture\Data\MessagePage;
use App\Domain\Capture\Data\MessageReference;
use App\Domain\Capture\Data\MimePart;
use App\Domain\Capture\Data\ProviderMessage;
use App\Domain\Capture\Exceptions\GmailReadFailure;
use App\Domain\Capture\Exceptions\HistoryExpired;
use App\Domain\Capture\Exceptions\InvalidMailboxData;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\History;
use Google\Service\Gmail\ListHistoryResponse;
use Google\Service\Gmail\ListMessagesResponse;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\Profile;
use Google\Service\Gmail\Resource\Users;
use Google\Service\Gmail\Resource\UsersHistory;
use Google\Service\Gmail\Resource\UsersMessages;
use Google\Service\Gmail\Resource\UsersThreads;
use Google\Service\Gmail\Thread;
use Throwable;

/** Adapter com superfície restrita: perfil, listagem, histórico e leitura de Threads. */
final readonly class GoogleGmailMailboxSource implements MailboxSource
{
    private Gmail $gmail;

    public function __construct(AccessToken $accessToken, ?Client $client = null)
    {
        if ($accessToken->isExpiredAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            throw new GmailReadFailure('A credencial de leitura Gmail expirou.');
        }

        $client = $client === null ? new Client : clone $client;
        $client->setAccessToken(['access_token' => $accessToken->reveal()]);
        $this->gmail = new Gmail($client);
    }

    public function profile(): GmailMailboxProfile
    {
        $profile = $this->read(fn () => $this->users()->getProfile('me'));
        if (! $profile instanceof Profile) {
            throw new GmailReadFailure('Não foi possível ler o perfil da conta Gmail.');
        }

        return new GmailMailboxProfile(
            emailAddress: (string) $profile->getEmailAddress(),
            historyId: (string) $profile->getHistoryId(),
        );
    }

    public function listMessages(?string $pageToken): MessagePage
    {
        $options = ['maxResults' => 500, 'includeSpamTrash' => false];
        if ($pageToken !== null) {
            $options['pageToken'] = $pageToken;
        }

        $response = $this->read(fn () => $this->messages()->listUsersMessages('me', $options));
        if (! $response instanceof ListMessagesResponse) {
            throw new GmailReadFailure('Não foi possível listar mensagens Gmail.');
        }

        $messages = [];
        foreach ($response->getMessages() ?? [] as $message) {
            $messages[] = new MessageReference(
                providerMessageId: (string) $message->getId(),
                providerThreadId: (string) $message->getThreadId(),
            );
        }

        $nextPageToken = $response->getNextPageToken();

        return new MessagePage($messages, is_string($nextPageToken) && $nextPageToken !== '' ? $nextPageToken : null);
    }

    public function getThreadMessages(string $providerThreadId): array
    {
        $thread = $this->read(
            fn () => $this->threads()->get('me', $providerThreadId, ['format' => 'full']),
            missingResourceIsEmpty: true,
        );
        if ($thread === null) {
            return [];
        }
        if (! $thread instanceof Thread) {
            throw new GmailReadFailure('O Gmail retornou uma Thread inválida.');
        }

        $messages = [];
        foreach ($thread->getMessages() ?? [] as $message) {
            $messages[] = $this->providerMessage($message);
        }

        return $messages;
    }

    public function listHistory(string $startHistoryId, ?string $pageToken): HistoryPage
    {
        $options = ['startHistoryId' => $startHistoryId, 'maxResults' => 500];
        if ($pageToken !== null) {
            $options['pageToken'] = $pageToken;
        }

        $response = $this->read(
            fn () => $this->history()->listUsersHistory('me', $options),
            historyRequest: true,
        );
        if (! $response instanceof ListHistoryResponse) {
            throw new GmailReadFailure('O Gmail retornou uma página de histórico inválida.');
        }

        $deletedMessageIds = [];
        $changedThreadIds = [];
        foreach ($response->getHistory() ?? [] as $history) {
            foreach ($history->getMessagesDeleted() ?? [] as $deleted) {
                $messageId = $deleted->getMessage()?->getId();
                if (is_string($messageId) && $messageId !== '') {
                    $deletedMessageIds[$messageId] = true;
                }
            }

            foreach ($this->changedMessages($history) as $message) {
                $messageId = $message->getId();
                $threadId = $message->getThreadId();
                if (! is_string($messageId) || $messageId === '' || isset($deletedMessageIds[$messageId])) {
                    continue;
                }

                if (! is_string($threadId) || $threadId === '') {
                    throw new InvalidMailboxData('O histórico do Gmail contém uma alteração sem Thread.');
                }

                $changedThreadIds['thread:'.$threadId] = $threadId;
            }
        }

        $historyId = $response->getHistoryId();
        $nextPageToken = $response->getNextPageToken();

        return new HistoryPage(
            changedThreadIds: array_values($changedThreadIds),
            historyId: is_string($historyId) ? $historyId : '',
            nextPageToken: is_string($nextPageToken) && $nextPageToken !== '' ? $nextPageToken : null,
        );
    }

    private function providerMessage(Message $message): ProviderMessage
    {
        $payload = $message->getPayload();
        if (! $payload instanceof MessagePart) {
            throw new GmailReadFailure('O Gmail retornou uma Message sem estrutura MIME.');
        }

        /** @var array<string, list<string>> $headers */
        $headers = [];
        foreach ($payload->getHeaders() ?? [] as $header) {
            $name = strtolower(trim((string) $header->getName()));
            if (! in_array($name, ['from', 'to', 'cc', 'bcc', 'subject', 'message-id', 'in-reply-to', 'references'], true)) {
                continue;
            }
            $headers[$name][] = (string) $header->getValue();
        }

        $internalDate = $message->getInternalDate();
        $date = null;
        if (is_numeric($internalDate)) {
            $milliseconds = (int) $internalDate;
            $date = \DateTimeImmutable::createFromFormat(
                'U.u',
                intdiv($milliseconds, 1000).'.'.str_pad((string) (($milliseconds % 1000) * 1000), 6, '0', STR_PAD_LEFT),
            );
            if ($date === false) {
                throw new InvalidMailboxData('O Gmail retornou uma data interna inválida.');
            }
        }

        return new ProviderMessage(
            providerMessageId: (string) $message->getId(),
            providerThreadId: (string) $message->getThreadId(),
            labelIds: array_values(array_filter($message->getLabelIds() ?? [], 'is_string')),
            headers: $headers,
            internalDate: $date,
            payload: $this->mimePart($payload, '1'),
        );
    }

    private function mimePart(MessagePart $part, string $fallbackPath): MimePart
    {
        /** @var array<string, string> $partHeaders */
        $partHeaders = [];
        foreach ($part->getHeaders() ?? [] as $header) {
            $name = strtolower(trim((string) $header->getName()));
            if ($name === 'content-disposition') {
                $partHeaders[$name] = trim((string) $header->getValue());
            }
        }

        $body = $part->getBody();
        $attachmentId = $body?->getAttachmentId();
        $attachmentId = is_string($attachmentId) && trim($attachmentId) !== '' ? trim($attachmentId) : null;
        $filename = trim((string) $part->getFilename());
        $filename = $filename === '' ? null : $filename;
        $parts = $part->getParts() ?? [];
        $baseMimeType = strtolower(trim(explode(';', (string) $part->getMimeType(), 2)[0]));
        $isTextBody = in_array($baseMimeType, ['text/plain', 'text/html'], true);
        $nonTextLeaf = $parts === [] && ! $isTextBody;
        $contentDisposition = strtolower(trim($partHeaders['content-disposition'] ?? ''));
        $hasAttachmentDisposition = str_starts_with($contentDisposition, 'attachment');
        $isAttachment = $filename !== null
            || $attachmentId !== null
            || $hasAttachmentDisposition
            || $nonTextLeaf;

        if ($attachmentId !== null && $filename === null && ! $hasAttachmentDisposition && ! $nonTextLeaf) {
            // Preserve the fail-closed classification without carrying Gmail's opaque attachment ID into the DTO.
            $partHeaders['content-disposition'] = 'attachment';
        }

        // Não chamar getData() para um Anexo, mesmo quando o provedor embute bytes no payload.
        $bodyData = null;
        if (! $isAttachment && $parts === [] && $isTextBody) {
            $data = $body?->getData();
            $bodyData = is_string($data) ? $data : null;
        }

        $partId = trim((string) $part->getPartId());
        $partId = $partId === '' ? $fallbackPath : $partId;

        $children = [];
        if (! $isAttachment) {
            foreach ($parts as $index => $child) {
                if ($child instanceof MessagePart) {
                    $children[] = $this->mimePart($child, $fallbackPath.'.'.($index + 1));
                }
            }
        }

        return new MimePart(
            mimeType: (string) $part->getMimeType(),
            partId: $partId,
            filename: $filename,
            headers: $partHeaders,
            bodyData: $bodyData,
            attachmentId: null,
            size: max(0, (int) ($body?->getSize() ?? 0)),
            parts: $children,
        );
    }

    /** @return list<Message> */
    private function changedMessages(History $history): array
    {
        $messages = [];
        foreach ($history->getMessages() ?? [] as $message) {
            if ($message instanceof Message) {
                $messages[] = $message;
            }
        }
        foreach ($history->getMessagesAdded() ?? [] as $change) {
            $message = $change->getMessage();
            if ($message instanceof Message) {
                $messages[] = $message;
            }
        }
        foreach ($history->getLabelsAdded() ?? [] as $change) {
            $message = $change->getMessage();
            if ($message instanceof Message) {
                $messages[] = $message;
            }
        }
        foreach ($history->getLabelsRemoved() ?? [] as $change) {
            $message = $change->getMessage();
            if ($message instanceof Message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function users(): Users
    {
        $resource = $this->gmail->users;
        if (! $resource instanceof Users) {
            throw new GmailReadFailure('O recurso de perfil Gmail não está disponível.');
        }

        return $resource;
    }

    private function messages(): UsersMessages
    {
        $resource = $this->gmail->users_messages;
        if (! $resource instanceof UsersMessages) {
            throw new GmailReadFailure('O recurso de leitura de Messages Gmail não está disponível.');
        }

        return $resource;
    }

    private function history(): UsersHistory
    {
        $resource = $this->gmail->users_history;
        if (! $resource instanceof UsersHistory) {
            throw new GmailReadFailure('O recurso de histórico Gmail não está disponível.');
        }

        return $resource;
    }

    private function threads(): UsersThreads
    {
        $resource = $this->gmail->users_threads;
        if (! $resource instanceof UsersThreads) {
            throw new GmailReadFailure('O recurso de leitura de Threads Gmail não está disponível.');
        }

        return $resource;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T|null
     */
    private function read(
        callable $request,
        bool $historyRequest = false,
        bool $missingResourceIsEmpty = false,
    ): mixed {
        $maxAttempts = 5;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                return $request();
            } catch (GoogleServiceException $exception) {
                $status = (int) $exception->getCode();

                if ($historyRequest && $status === 404) {
                    throw new HistoryExpired('O histórico Gmail expirou.');
                }
                if ($missingResourceIsEmpty && $status === 404) {
                    return null;
                }

                if (($status === 429 || ($status >= 500 && $status <= 599)) && $attempt + 1 < $maxAttempts) {
                    $maximumDelay = min(2_000_000, 100_000 * (2 ** $attempt));
                    usleep(random_int((int) ($maximumDelay / 2), $maximumDelay));

                    continue;
                }

                throw new GmailReadFailure('A leitura Gmail falhou.');
            } catch (Throwable) {
                throw new GmailReadFailure('A leitura Gmail falhou.');
            }
        }

        throw new GmailReadFailure('A leitura Gmail excedeu o limite de tentativas.');
    }
}
