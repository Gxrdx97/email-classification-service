<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Services;

use App\Domain\Classification\Contracts\OfficialDecisionProjector;
use App\Domain\GmailLabels\Contracts\OfficialDecisionSource;
use App\Domain\GmailLabels\Contracts\ProjectionIntentDispatcher;
use App\Domain\GmailLabels\Contracts\ProjectionOutboxRepository;
use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use InvalidArgumentException;

final readonly class ProjectCategoryLabels implements OfficialDecisionProjector
{
    public function __construct(
        private OfficialDecisionSource $decisions,
        private MessageLabelProjectionResolver $resolver,
        private ProjectionOutboxRepository $outbox,
        private ProjectionIntentDispatcher $dispatcher,
    ) {}

    /** Call within the official-decision transaction; queue delivery is deferred until its commit. */
    public function projectThread(int $threadId): int
    {
        return $this->project($threadId);
    }

    public function recordForThread(int $threadId): int
    {
        return $this->project($threadId);
    }

    public function recordForMessageOverride(int $threadId, int $messageId): int
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('Message identifier must be positive.');
        }

        return $this->project($threadId, onlyMessageId: $messageId);
    }

    public function reconcileThread(int $threadId, string $reconciliationId): int
    {
        if ($reconciliationId === '' || strlen($reconciliationId) > 128) {
            throw new InvalidArgumentException('A bounded reconciliation identifier is required.');
        }

        return $this->project($threadId, $reconciliationId);
    }

    private function project(
        int $threadId,
        ?string $reconciliationId = null,
        ?int $onlyMessageId = null,
    ): int {
        if ($threadId < 1) {
            throw new InvalidArgumentException('Thread identifier must be positive.');
        }

        $snapshot = $this->decisions->threadSnapshot($threadId);

        if ($snapshot === null) {
            return 0;
        }

        if ($snapshot->threadId !== $threadId) {
            throw new InvalidArgumentException('Decision source returned a different Thread.');
        }

        if ($onlyMessageId !== null) {
            $messageExists = false;

            foreach ($snapshot->messages as $message) {
                if ($message->messageId === $onlyMessageId) {
                    $messageExists = true;
                    break;
                }
            }

            if (! $messageExists) {
                throw new InvalidArgumentException('Message is not present in the Thread projection snapshot.');
            }
        }

        $recorded = 0;

        $projections = $this->resolver->resolve($snapshot);
        if ($onlyMessageId !== null) {
            $projections = array_values(array_filter(
                $projections,
                static fn (DesiredMessageLabelProjection $projection): bool => $projection->messageId === $onlyMessageId,
            ));
        }

        $projectedMessageIds = array_fill_keys(array_map(
            static fn (DesiredMessageLabelProjection $projection): int => $projection->messageId,
            $projections,
        ), true);

        foreach ($snapshot->messages as $message) {
            if ($onlyMessageId !== null && $message->messageId !== $onlyMessageId) {
                continue;
            }

            if (isset($projectedMessageIds[$message->messageId])) {
                continue;
            }

            $effectiveDecision = $message->messageOverride ?? $snapshot->latestOfficialThreadDecision;

            if ($effectiveDecision !== null && ! $effectiveDecision->isActionable()) {
                $this->outbox->supersedeActiveForMessage($message->messageId, 'official_decision_not_actionable');
            }
        }

        usort($projections, static fn (DesiredMessageLabelProjection $left, DesiredMessageLabelProjection $right): int => $left->messageId <=> $right->messageId);

        foreach ($projections as $projection) {
            $receipt = $this->outbox->record(
                $projection,
                $this->idempotencyKey($projection, $reconciliationId),
            );

            $this->dispatcher->dispatchAfterCommit($receipt->outboxId, $receipt->messageId);
            $recorded++;
        }

        return $recorded;
    }

    private function idempotencyKey(DesiredMessageLabelProjection $projection, ?string $reconciliationId): string
    {
        $decision = $projection->decision;

        return hash('sha256', implode('|', [
            'gmail-category-label-projection',
            (string) $projection->accountId,
            (string) $projection->messageId,
            $decision->scope->value,
            (string) $decision->decisionId,
            (string) $decision->reviewId,
            $decision->state->value,
            implode(',', $projection->categoryIds),
            $reconciliationId ?? 'official-decision',
        ]));
    }
}
