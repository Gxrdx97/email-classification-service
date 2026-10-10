<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\ProposalOutcome;

final readonly class ClassificationProposal
{
    /**
     * @param  list<ProposedCategory>  $categories
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $evidenceRefs
     */
    public function __construct(
        public int $threadId,
        public ProposalOutcome $outcome,
        public array $categories,
        public float $confidence,
        public bool $reviewRequired,
        public array $reasonCodes,
        public array $evidenceRefs,
        public ?InconclusiveReason $inconclusiveReason,
        public string $providerName,
        public ?string $providerModel,
    ) {}
}
