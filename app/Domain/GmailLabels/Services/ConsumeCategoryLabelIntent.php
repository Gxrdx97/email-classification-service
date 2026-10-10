<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Services;

use App\Domain\GmailLabels\Contracts\CategoryLabelGateway;
use App\Domain\GmailLabels\Contracts\CategoryLabelMappingRepository;
use App\Domain\GmailLabels\Contracts\OfficialDecisionSource;
use App\Domain\GmailLabels\Contracts\ProjectionOutboxRepository;
use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use App\Domain\GmailLabels\Data\ProjectionIntent;
use App\Domain\GmailLabels\Exceptions\ProjectionDeliveryException;
use DateTimeImmutable;
use Throwable;

final readonly class ConsumeCategoryLabelIntent
{
    private const MAX_DELIVERY_ATTEMPTS = 5;

    public function __construct(
        private ProjectionOutboxRepository $outbox,
        private CategoryLabelMappingRepository $mappings,
        private CategoryLabelGateway $gmail,
        private OfficialDecisionSource $decisions,
        private MessageLabelProjectionResolver $resolver,
    ) {}

    public function consume(int $outboxId): void
    {
        $intent = $this->outbox->claim($outboxId);

        if ($intent === null) {
            return;
        }

        try {
            if (! $this->isCurrent($intent)) {
                $this->outbox->supersede($intent, 'official_decision_changed');

                return;
            }

            $desiredLabelIds = [];

            foreach ($intent->desired->categoryIds as $categoryId) {
                $mapping = $this->mappings->find($categoryId);

                if ($mapping === null) {
                    $displayName = $this->mappings->displayName($categoryId);

                    if ($displayName === null) {
                        throw new \RuntimeException('Category Label mapping source is unavailable.');
                    }

                    if (! $this->isCurrent($intent)) {
                        $this->outbox->supersede($intent, 'official_decision_changed');

                        return;
                    }

                    $mapping = $this->gmail->ensureCategoryLabel($categoryId, $displayName);
                }

                $desiredLabelIds[] = $mapping->providerLabelId;
            }

            if (! $this->isCurrent($intent)) {
                $this->outbox->supersede($intent, 'official_decision_changed');

                return;
            }

            $this->gmail->reconcileMessageAssociations(
                $intent->desired->gmailMessageId,
                array_values(array_unique($desiredLabelIds)),
            );

            $this->outbox->markApplied($intent);
        } catch (Throwable) {
            if ($intent->attemptCount >= self::MAX_DELIVERY_ATTEMPTS) {
                $this->outbox->markDead($intent->outboxId, 'delivery_attempt_limit');

                return;
            }

            $this->outbox->markRetryable(
                $intent,
                'gmail_label_projection_failed',
                $this->retryAt($intent->attemptCount),
            );

            throw new ProjectionDeliveryException;
        }
    }

    /** @phpstan-impure */
    private function isCurrent(ProjectionIntent $intent): bool
    {
        $desired = $intent->desired;
        $snapshot = $this->decisions->threadSnapshot($desired->threadId);

        if ($snapshot === null || $snapshot->threadId !== $desired->threadId) {
            return false;
        }

        foreach ($this->resolver->resolve($snapshot) as $current) {
            if ($current->messageId === $desired->messageId) {
                return $this->sameProjection($current, $desired);
            }
        }

        return false;
    }

    private function sameProjection(
        DesiredMessageLabelProjection $current,
        DesiredMessageLabelProjection $desired,
    ): bool {
        return $current->accountId === $desired->accountId
            && $current->messageId === $desired->messageId
            && $current->threadId === $desired->threadId
            && $current->gmailMessageId === $desired->gmailMessageId
            && $current->decision->decisionId === $desired->decision->decisionId
            && $current->decision->reviewId === $desired->decision->reviewId
            && $current->decision->scope === $desired->decision->scope
            && $current->decision->targetMessageId === $desired->decision->targetMessageId
            && $current->decision->state === $desired->decision->state
            && $current->categoryIds === $desired->categoryIds;
    }

    private function retryAt(int $attemptCount): DateTimeImmutable
    {
        $delays = [30, 120, 600, 1800];
        $delay = $delays[min(max($attemptCount - 1, 0), count($delays) - 1)];

        return (new DateTimeImmutable)->modify(sprintf('+%d seconds', $delay));
    }
}
