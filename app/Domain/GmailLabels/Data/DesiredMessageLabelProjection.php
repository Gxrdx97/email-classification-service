<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use InvalidArgumentException;

final readonly class DesiredMessageLabelProjection
{
    /** @var list<int> */
    public array $categoryIds;

    /**
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public int $accountId,
        public int $messageId,
        public int $threadId,
        public string $gmailMessageId,
        public OfficialDecision $decision,
        array $categoryIds,
    ) {
        if ($accountId < 1 || $messageId < 1 || $threadId < 1 || $gmailMessageId === '') {
            throw new InvalidArgumentException('Desired projection identifiers are invalid.');
        }

        if (
            ! $decision->isActionable()
            || $decision->threadId !== $threadId
            || ($decision->scope === DecisionScope::MESSAGE && $decision->targetMessageId !== $messageId)
        ) {
            throw new InvalidArgumentException('Only actionable official decisions can be projected.');
        }

        foreach ($categoryIds as $categoryId) {
            if (! is_int($categoryId) || $categoryId < 1) {
                throw new InvalidArgumentException('Category identifiers must be positive integers.');
            }
        }

        $normalizedCategoryIds = array_values(array_unique($categoryIds));
        sort($normalizedCategoryIds);
        $this->categoryIds = $normalizedCategoryIds;

        if (
            ($decision->state === OfficialDecisionState::CATEGORIZED && $this->categoryIds !== $decision->categoryIds)
            || ($decision->state === OfficialDecisionState::UNCATEGORIZED && $this->categoryIds !== [])
        ) {
            throw new InvalidArgumentException('Desired Categories must match the official decision.');
        }
    }
}
