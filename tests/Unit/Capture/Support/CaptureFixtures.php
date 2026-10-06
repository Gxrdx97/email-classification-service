<?php

declare(strict_types=1);

namespace Tests\Unit\Capture\Support;

use App\Domain\Capture\Data\MessagePage;
use App\Domain\Capture\Data\MessageReference;
use App\Domain\Capture\Data\MimePart;
use App\Domain\Capture\Data\ProviderMessage;
use DateTimeImmutable;

final class CaptureFixtures
{
    public static function message(
        string $messageId = 'message-1',
        string $threadId = 'thread-1',
        string $text = 'Olá, esta é uma mensagem de teste.',
        array $labels = ['INBOX'],
        ?array $headers = null,
    ): ProviderMessage {
        return new ProviderMessage(
            providerMessageId: $messageId,
            providerThreadId: $threadId,
            labelIds: $labels,
            headers: $headers ?? [
                'from' => ['Remetente <sender@example.test>'],
                'to' => ['Destinatário <owner@example.test>'],
                'subject' => ['Mensagem sintética'],
                'message-id' => ['<'.$messageId.'@example.test>'],
                'in-reply-to' => [],
                'references' => [],
            ],
            internalDate: new DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            payload: new MimePart(
                mimeType: 'text/plain',
                partId: '0',
                filename: null,
                headers: [],
                bodyData: rtrim(strtr(base64_encode($text), '+/', '-_'), '='),
                attachmentId: null,
                size: strlen($text),
            ),
        );
    }

    public static function page(string $threadId, ?string $nextToken = null): MessagePage
    {
        return new MessagePage([new MessageReference('message-'.$threadId, $threadId)], $nextToken);
    }
}
