<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Contracts;

interface PreAnalysisOperationalFailureRecorder
{
    /**
     * Marks only the operational attempt as failed. Implementations must preserve any official
     * Classification, its identity, and inherited Labels.
     */
    public function markFailedWithoutReplacingOfficialResult(int $threadId, string $failureCode): void;
}
