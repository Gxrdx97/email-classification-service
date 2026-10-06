<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use DateTimeImmutable;
use JsonSerializable;

final readonly class CanonicalMessage implements JsonSerializable
{
    public string $contentHash;

    /**
     * @param  list<string>  $labelIds
     * @param  array<string, list<string>>  $headers
     * @param  list<AttachmentMetadata>  $attachments
     */
    public function __construct(
        public string $providerMessageId,
        public string $providerThreadId,
        public ?string $subject,
        public ?string $rawBodyText,
        public ?string $rawBodyHtml,
        public ?DateTimeImmutable $providerInternalDate,
        public array $headers,
        public array $labelIds,
        public array $attachments,
    ) {
        $context = hash_init('sha256');
        $write = static function (?string $field) use ($context): void {
            if ($field === null) {
                hash_update($context, "\x00");

                return;
            }

            hash_update($context, "\x01".pack('N', strlen($field)).$field);
        };

        foreach ([$subject, $rawBodyText, $rawBodyHtml] as $field) {
            $write($field);
        }

        $normalizedHeaders = $headers;
        ksort($normalizedHeaders);
        $write((string) count($normalizedHeaders));
        foreach ($normalizedHeaders as $name => $values) {
            $write($name);
            $write((string) count($values));
            foreach ($values as $value) {
                $write($value);
            }
        }

        $write((string) count($attachments));
        foreach ($attachments as $attachment) {
            $write($attachment->fileName);
            $write($attachment->mediaType);
            $write((string) $attachment->sizeBytes);
        }

        $this->contentHash = hash_final($context);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_message_id' => $this->providerMessageId,
            'provider_thread_id' => $this->providerThreadId,
            'content_hash' => $this->contentHash,
            'labels_count' => count($this->labelIds),
            'attachments_count' => count($this->attachments),
            'content' => '[omitted]',
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
