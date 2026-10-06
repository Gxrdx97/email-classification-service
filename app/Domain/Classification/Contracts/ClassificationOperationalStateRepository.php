<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

interface ClassificationOperationalStateRepository
{
    public function markPending(int $threadId): void;

    /** Changes only operational_state; the official classification and pointer are immutable here. */
    public function markFailed(int $threadId): void;
}
