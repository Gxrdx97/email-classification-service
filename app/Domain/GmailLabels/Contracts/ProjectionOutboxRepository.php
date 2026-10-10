<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Contracts;

use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use App\Domain\GmailLabels\Data\ProjectionIntent;
use App\Domain\GmailLabels\Data\ProjectionOutboxReceipt;
use DateTimeImmutable;

interface ProjectionOutboxRepository
{
    public function record(DesiredMessageLabelProjection $projection, string $idempotencyKey): ProjectionOutboxReceipt;

    public function claim(int $outboxId): ?ProjectionIntent;

    public function supersede(ProjectionIntent $intent, string $reasonCode): void;

    public function supersedeActiveForMessage(int $messageId, string $reasonCode): void;

    public function markApplied(ProjectionIntent $intent): void;

    public function markRetryable(ProjectionIntent $intent, string $errorCode, DateTimeImmutable $availableAt): void;

    public function markDead(int $outboxId, string $errorCode): void;
}
