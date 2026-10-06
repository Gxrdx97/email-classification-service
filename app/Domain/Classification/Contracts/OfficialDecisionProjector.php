<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

interface OfficialDecisionProjector
{
    /** Record the current official Label projection for every Message in the Thread. */
    public function recordForThread(int $threadId): int;

    /** Record the current official Label projection for the Message with a human override. */
    public function recordForMessageOverride(int $threadId, int $messageId): int;
}
