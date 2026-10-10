<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use JsonSerializable;

final readonly class EnrichedMessage implements JsonSerializable
{
    /**
     * @param  list<Participant>  $participants
     * @param  list<AttachmentMetadata>  $attachments
     * @param  array<string, scalar|null|list<string>>  $deterministicFacts
     */
    public function __construct(
        public string $providerMessageId,
        public string $contentHash,
        public string $normalizedText,
        public array $participants,
        public string $direction,
        public string $languageCode,
        public int $threadPosition,
        public array $attachments,
        public array $deterministicFacts,
        public string $extractorName,
        public string $extractorVersion,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_message_id' => $this->providerMessageId,
            'content_hash' => $this->contentHash,
            'text' => '[omitted]',
            'participants_count' => count($this->participants),
            'direction' => $this->direction,
            'language_code' => $this->languageCode,
            'thread_position' => $this->threadPosition,
            'attachments_count' => count($this->attachments),
            'extractor_name' => $this->extractorName,
            'extractor_version' => $this->extractorVersion,
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
