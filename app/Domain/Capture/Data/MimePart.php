<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use JsonSerializable;

final readonly class MimePart implements JsonSerializable
{
    /**
     * @param  array<string, string>  $headers  Cabeçalhos desta parte, com nomes em minúsculas.
     * @param  list<MimePart>  $parts
     */
    public function __construct(
        public string $mimeType,
        public string $partId,
        public ?string $filename,
        public array $headers,
        private ?string $bodyData,
        public ?string $attachmentId,
        public int $size,
        public array $parts = [],
    ) {}

    public function bodyData(): ?string
    {
        return $this->bodyData;
    }

    public function isAttachment(): bool
    {
        $baseMimeType = strtolower(trim(explode(';', $this->mimeType, 2)[0]));
        $nonTextLeaf = $this->parts === []
            && ! in_array($baseMimeType, ['text/plain', 'text/html'], true)
            && ! str_starts_with($baseMimeType, 'multipart/');

        return ($this->filename !== null && trim($this->filename) !== '')
            || $this->attachmentId !== null
            || str_starts_with(strtolower($this->headers['content-disposition'] ?? ''), 'attachment')
            || $nonTextLeaf;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'mime_type' => $this->mimeType,
            'part_id' => $this->partId,
            'filename' => $this->filename === null ? null : '[metadata]',
            'body_data' => '[omitted]',
            'attachment_id' => $this->attachmentId === null ? null : '[metadata]',
            'size' => $this->size,
            'parts_count' => count($this->parts),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
