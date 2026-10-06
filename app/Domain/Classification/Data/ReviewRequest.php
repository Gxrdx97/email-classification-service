<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\OfficialOutcome;
use App\Domain\Classification\Enums\ReviewAction;
use App\Domain\Classification\Enums\ReviewScope;

final readonly class ReviewRequest
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
        public string $idempotencyKey,
        public ?OfficialOutcome $outcome = null,
        public array $categoryIds = [],
        public float $confidence = 1.0,
        public ?InconclusiveReason $inconclusiveReason = null,
        public array $evidenceRefs = [],
    ) {}
}
