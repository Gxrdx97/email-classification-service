<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Contracts;

interface ProjectionIntentDispatcher
{
    public function dispatchAfterCommit(int $outboxId, int $messageId): void;
}
