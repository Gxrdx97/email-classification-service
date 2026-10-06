<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels;

use App\Domain\GmailLabels\Contracts\ProjectionIntentDispatcher;
use App\Domain\GmailLabels\Jobs\SyncCategoryLabelsJob;

final class LaravelProjectionIntentDispatcher implements ProjectionIntentDispatcher
{
    public function dispatchAfterCommit(int $outboxId, int $messageId): void
    {
        SyncCategoryLabelsJob::dispatch($outboxId, $messageId)->afterCommit();
    }
}
