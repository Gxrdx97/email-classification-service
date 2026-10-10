<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

final readonly class PreAnalysisProposal
{
    /**
     * @param  list<PreAnalysisSuggestion>  $suggestions
     * @param  array<string, int>  $usage
     */
    public function __construct(
        public array $suggestions,
        public bool $noneApplies,
        public ?string $inconclusiveReason,
        public array $usage = [],
    ) {}
}
