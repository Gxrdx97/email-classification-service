<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\OfficialOutcome;
use App\Domain\Classification\Enums\ReviewAction;
use App\Domain\Classification\Enums\ReviewScope;

final readonly class ReviewDecision
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<string>  $evidenceRefs
     */
    public function __construct(
        public int $threadId,
        public ReviewScope $scope,
        public ?int $targetMessageId,
        public ReviewAction $action,
        public ?string $reviewerSubject,
        public string $idempotencyKey,
        public ?OfficialOutcome $outcome,
        public array $categoryIds,
        public float $confidence,
        public ?InconclusiveReason $inconclusiveReason,
        public array $evidenceRefs,
    ) {}
}
