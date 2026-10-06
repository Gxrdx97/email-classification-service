<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

final readonly class CaptureCommit
{
    public function __construct(
        public ?string $committedCursor,
        public int $capturedMessages,
        public int $newMessages,
    ) {}
}
