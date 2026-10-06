<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use InvalidArgumentException;

final readonly class OfficialDecision
{
    /** @var list<int> */
    public array $categoryIds;

    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public int $decisionId,
        public ?int $reviewId,
        public int $threadId,
        public DecisionScope $scope,
        public ?int $targetMessageId,
        public OfficialDecisionState $state,
        array $categoryIds,
        public string $decisionAuthor,
    ) {
        if ($decisionId < 1 || $threadId < 1 || ($reviewId !== null && $reviewId < 1)) {
            throw new InvalidArgumentException('Official decision identifiers must be positive.');
        }

        if ($decisionAuthor !== 'human_review') {
            throw new InvalidArgumentException('Only human-reviewed decisions can drive Gmail Labels.');
        }

        if (
            ($scope === DecisionScope::THREAD && $targetMessageId !== null)
            || ($scope === DecisionScope::MESSAGE
                && ($targetMessageId === null || $targetMessageId < 1 || $reviewId === null))
        ) {
            throw new InvalidArgumentException('Official decision target does not match its scope.');
        }

        foreach ($categoryIds as $categoryId) {
            if (! is_int($categoryId) || $categoryId < 1) {
                throw new InvalidArgumentException('Category identifiers must be positive integers.');
            }
        }

        $normalizedCategoryIds = array_values(array_unique($categoryIds));
        sort($normalizedCategoryIds);
        $this->categoryIds = $normalizedCategoryIds;

        if ($state === OfficialDecisionState::CATEGORIZED && $this->categoryIds === []) {
            throw new InvalidArgumentException('A categorized decision must reference at least one Category.');
        }

        if ($state === OfficialDecisionState::UNCATEGORIZED && $this->categoryIds !== []) {
            throw new InvalidArgumentException('An uncategorized decision cannot reference Categories.');
        }
    }

    public function isActionable(): bool
    {
        return in_array($this->state, [
            OfficialDecisionState::CATEGORIZED,
            OfficialDecisionState::UNCATEGORIZED,
        ], true);
    }
}
