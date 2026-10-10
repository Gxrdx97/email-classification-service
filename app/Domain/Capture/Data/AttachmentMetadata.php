<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

use App\Domain\Capture\Exceptions\InvalidMailboxData;
use JsonSerializable;

final readonly class AttachmentMetadata implements JsonSerializable
{
    public function __construct(
        public ?string $fileName,
        public ?string $mediaType,
        public int $sizeBytes,
    ) {
        if ($sizeBytes < 0) {
            throw new InvalidMailboxData('Os metadados do Anexo são inválidos.');
        }
    }

    /** @return array{file_name: string, media_type: string, size_bytes: int} */
    public function jsonSerialize(): array
    {
        return [
            'file_name' => '[redacted]',
            'media_type' => '[redacted]',
            'size_bytes' => $this->sizeBytes,
        ];
    }

    /** @return array{file_name: string, media_type: string, size_bytes: int} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
