<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Jobs;

use App\Domain\PreAnalysis\Services\PreAnalysisOutboxConsumer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PreAnalysisOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 55;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(
        public readonly int $preAnalysisId,
        public readonly string $idempotencyKey,
    ) {}

    public function handle(PreAnalysisOutboxConsumer $consumer): void
    {
        $consumer->consume($this->preAnalysisId, $this->idempotencyKey);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(PreAnalysisOutboxConsumer::class)->failAfterQueueExhaustion($this->preAnalysisId);
        } catch (Throwable) {
            Log::error('preanalysis.queue_failed', [
                'pre_analysis_id' => $this->preAnalysisId,
                'error_code' => 'queue_attempts_exhausted',
            ]);
        }
    }
}
