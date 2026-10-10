<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Services;

use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use App\Domain\GmailLabels\Data\OfficialDecision;
use App\Domain\GmailLabels\Data\ProjectionMessage;
use App\Domain\GmailLabels\Data\ThreadProjectionSnapshot;
use App\Domain\GmailLabels\Enums\DecisionScope;

final class MessageLabelProjectionResolver
{
    /** @return list<DesiredMessageLabelProjection> */
    public function resolve(ThreadProjectionSnapshot $snapshot): array
    {
        $projections = [];

        foreach ($snapshot->messages as $message) {
            $decision = $message->messageOverride ?? $snapshot->latestOfficialThreadDecision;

            if ($decision === null || ! $decision->isActionable()) {
                continue;
            }

            if (
                $decision->scope === DecisionScope::MESSAGE
                && $decision->targetMessageId !== $message->messageId
            ) {
                continue;
            }

            $projections[] = $this->forMessage($message, $decision);
        }

        return $projections;
    }

    private function forMessage(ProjectionMessage $message, OfficialDecision $decision): DesiredMessageLabelProjection
    {
        return new DesiredMessageLabelProjection(
            accountId: $message->accountId,
            messageId: $message->messageId,
            threadId: $message->threadId,
            gmailMessageId: $message->gmailMessageId,
            decision: $decision,
            categoryIds: $decision->categoryIds,
        );
    }
}
