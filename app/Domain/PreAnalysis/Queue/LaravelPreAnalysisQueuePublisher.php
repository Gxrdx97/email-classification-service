<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Queue;

use App\Domain\PreAnalysis\Contracts\PreAnalysisQueuePublisher;
use App\Domain\PreAnalysis\Data\PreAnalysisOutboxMessage;
use App\Domain\PreAnalysis\Jobs\PreAnalysisOutboxJob;
use Illuminate\Support\Facades\Bus;

final class LaravelPreAnalysisQueuePublisher implements PreAnalysisQueuePublisher
{
    public function publish(PreAnalysisOutboxMessage $message): void
    {
        $job = (new PreAnalysisOutboxJob($message->preAnalysisId, $message->idempotencyKey))
            ->onQueue('pre-analysis');

        Bus::dispatch($job);
    }
}
