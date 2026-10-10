<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

final readonly class SyncResult
{
    public function __construct(
        public SyncMode $mode,
        public string $cursor,
        public int $capturedMessages,
        public int $newMessages,
    ) {}
}
