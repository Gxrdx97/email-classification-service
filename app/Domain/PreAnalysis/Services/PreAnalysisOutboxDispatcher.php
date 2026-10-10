<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Services;

use App\Domain\PreAnalysis\Contracts\PreAnalysisOutboxRepository;
use App\Domain\PreAnalysis\Contracts\PreAnalysisQueuePublisher;
use App\Domain\PreAnalysis\Data\PreAnalysisDispatchPolicy;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PreAnalysisOutboxDispatcher
{
    public function __construct(
        private PreAnalysisOutboxRepository $outbox,
        private PreAnalysisQueuePublisher $queue,
        private PreAnalysisDispatchPolicy $policy = new PreAnalysisDispatchPolicy,
        private ?LoggerInterface $logger = null,
    ) {}

    public function dispatchAvailable(?DateTimeImmutable $now = null): int
    {
        if (! $this->policy->isEnabled()) {
            return 0;
        }

        $now ??= new DateTimeImmutable('now');
        $messages = $this->outbox->claimAvailable(
            $this->policy->batchSize,
            $this->policy->leaseSeconds,
            $this->policy->maxDispatchAttempts,
            $now,
        );
        $dispatched = 0;

        foreach ($messages as $message) {
            try {
                // Publish only after claimAvailable has committed its short lease transaction.
                $this->queue->publish($message);
                $this->outbox->markDispatched($message, $now);
                $dispatched++;
            } catch (Throwable) {
                $terminal = $message->attemptCount >= $this->policy->maxDispatchAttempts;
                $errorCode = $terminal ? 'attempts_exhausted' : 'queue_publish_failed';
                $availableAt = $now->modify('+'.$this->policy->backoffSeconds($message->attemptCount).' seconds');

                $this->outbox->markFailed($message, $errorCode, $availableAt, $terminal, $now);
                $this->logger?->warning('preanalysis_outbox.publish_failed', [
                    'outbox_id' => $message->id,
                    'attempt_count' => $message->attemptCount,
                    'error_code' => $errorCode,
                ]);
            }
        }

        return $dispatched;
    }
}
