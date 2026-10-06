<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Contracts;

use App\Domain\PreAnalysis\Data\PreAnalysisOutboxMessage;
use DateTimeImmutable;

interface PreAnalysisOutboxRepository
{
    /** @return list<PreAnalysisOutboxMessage> */
    public function claimAvailable(
        int $limit,
        int $leaseSeconds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): array;

    public function markDispatched(PreAnalysisOutboxMessage $message, DateTimeImmutable $dispatchedAt): bool;

    public function markFailed(
        PreAnalysisOutboxMessage $message,
        string $errorCode,
        DateTimeImmutable $availableAt,
        bool $terminal,
        DateTimeImmutable $failedAt,
    ): bool;

    public function resumeAwaitingConsent(int $threadId, string $privacyPolicyVersion, DateTimeImmutable $now): int;
}
