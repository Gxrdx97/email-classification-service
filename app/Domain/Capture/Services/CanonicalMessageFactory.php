<?php

declare(strict_types=1);

namespace App\Domain\Capture\Services;

use App\Domain\Capture\Data\AttachmentMetadata;
use App\Domain\Capture\Data\CanonicalMessage;
use App\Domain\Capture\Data\MimePart;
use App\Domain\Capture\Data\ProviderMessage;
use App\Domain\Capture\Exceptions\InvalidMailboxData;

final class CanonicalMessageFactory
{
    public function fromProvider(ProviderMessage $message): CanonicalMessage
    {
        $plainBodies = [];
        $htmlBodies = [];
        $attachments = [];

        $this->collectContent($message->payload, '1', $plainBodies, $htmlBodies, $attachments);

        return new CanonicalMessage(
            providerMessageId: $message->providerMessageId,
            providerThreadId: $message->providerThreadId,
            subject: $this->firstHeader($message->headers, 'subject'),
            rawBodyText: $plainBodies === [] ? null : implode("\n", $plainBodies),
            rawBodyHtml: $htmlBodies === [] ? null : implode("\n", $htmlBodies),
            providerInternalDate: $message->internalDate,
            headers: $message->headers,
            labelIds: array_values(array_unique($message->labelIds)),
            attachments: $attachments,
        );
    }

    /**
     * @param  list<string>  $plainBodies
     * @param  list<string>  $htmlBodies
     * @param  list<AttachmentMetadata>  $attachments
     */
    private function collectContent(
        MimePart $part,
        string $path,
        array &$plainBodies,
        array &$htmlBodies,
        array &$attachments,
    ): void {
        if ($part->isAttachment()) {
            $attachments[] = new AttachmentMetadata(
                fileName: $part->filename,
                mediaType: $part->mimeType !== '' ? $part->mimeType : null,
                sizeBytes: $part->size,
            );

            return;
        }

        if ($part->parts !== []) {
            foreach ($part->parts as $index => $child) {
                $this->collectContent($child, $path.'.'.($index + 1), $plainBodies, $htmlBodies, $attachments);
            }

            return;
        }

        $mimeType = strtolower(trim(explode(';', $part->mimeType, 2)[0]));
        if (! in_array($mimeType, ['text/plain', 'text/html'], true)) {
            return;
        }

        $encoded = $part->bodyData();
        if ($encoded === null || $encoded === '') {
            return;
        }

        $decoded = $this->decodeBase64Url($encoded);
        if ($mimeType === 'text/plain') {
            $plainBodies[] = $decoded;
        } else {
            $htmlBodies[] = $decoded;
        }
    }

    private function decodeBase64Url(string $encoded): string
    {
        $normalized = strtr($encoded, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder !== 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new InvalidMailboxData('O Gmail retornou uma parte MIME inválida.');
        }

        return $decoded;
    }

    /** @param array<string, list<string>> $headers */
    private function firstHeader(array $headers, string $name): ?string
    {
        $value = $headers[$name][0] ?? null;

        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
