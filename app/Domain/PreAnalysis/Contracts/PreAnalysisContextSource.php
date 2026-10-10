<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Contracts;

use App\Domain\PreAnalysis\Data\PreAnalysisContext;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;

interface PreAnalysisContextSource
{
    /**
     * Loads an immutable snapshot for the lease. Implementations must derive eligibility and
     * active Category versions from trusted local state, and include only Examples authorized
     * for the current active Category versions. Implementations must never load or include
     * attachment content.
     */
    public function load(PreAnalysisLease $lease): ?PreAnalysisContext;
}
