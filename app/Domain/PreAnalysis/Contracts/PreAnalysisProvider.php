<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Contracts;

use App\Domain\PreAnalysis\Data\PreAnalysisProposal;
use App\Domain\PreAnalysis\Data\PreAnalysisSubmission;

interface PreAnalysisProvider
{
    public function isEnabled(): bool;

    public function analyze(PreAnalysisSubmission $submission): PreAnalysisProposal;
}
