<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Contracts;

use App\Domain\GmailLabels\Data\ThreadProjectionSnapshot;

interface OfficialDecisionSource
{
    /**
     * Returns the latest official human-reviewed Thread decision and current Messages,
     * each with its latest human-reviewed Message-only override, if present. Suggestions,
     * Pré-análise, and operational failures are never decisions. Operational state is
     * informational and must not replace the last official decision.
     */
    public function threadSnapshot(int $threadId): ?ThreadProjectionSnapshot;
}
