<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Jobs;

use App\Domain\GmailLabels\Contracts\ProjectionOutboxRepository;
use App\Domain\GmailLabels\Services\ConsumeCategoryLabelIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class SyncCategoryLabelsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly int $outboxId,
        public readonly int $messageId,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('gmail-category-label-message-'.$this->messageId))
                ->releaseAfter(15)
                ->expireAfter(120),
        ];
    }

    public function handle(ConsumeCategoryLabelIntent $consumer): void
    {
        $consumer->consume($this->outboxId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProjectionOutboxRepository::class)->markDead($this->outboxId, 'queue_retry_limit');
    }
}
