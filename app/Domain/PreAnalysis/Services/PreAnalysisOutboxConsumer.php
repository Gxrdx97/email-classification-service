<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Services;

use App\Domain\PreAnalysis\Contracts\PreAnalysisContextSource;
use App\Domain\PreAnalysis\Contracts\PreAnalysisExecutionRepository;
use App\Domain\PreAnalysis\Data\PreAnalysisDispatchPolicy;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\PreAnalysis\Enums\PreAnalysisExecutionOutcome;
use DateTimeImmutable;

final readonly class PreAnalysisOutboxConsumer
{
    public function __construct(
        private PreAnalysisExecutionRepository $executionRepository,
        private PreAnalysisContextSource $contextSource,
        private PreAnalysisProcessor $processor,
        private PreAnalysisDispatchPolicy $policy = new PreAnalysisDispatchPolicy,
    ) {}

    public function consume(int $preAnalysisId, string $idempotencyKey): PreAnalysisExecutionOutcome
    {
        if ($preAnalysisId < 1 || $idempotencyKey !== 'pre-analysis:'.$preAnalysisId) {
            return PreAnalysisExecutionOutcome::Duplicate;
        }

        $lease = $this->executionRepository->claim(
            $preAnalysisId,
            new DateTimeImmutable('now'),
            $this->policy->leaseSeconds,
        );

        if ($lease === null) {
            return PreAnalysisExecutionOutcome::Duplicate;
        }

        try {
            $context = $this->contextSource->load($lease);
        } catch (\Throwable) {
            return $this->processor->handleFailure($lease, 'context_unavailable', true, $this->policy->maxProviderAttempts);
        }

        if ($context === null) {
            return $this->block($lease, 'context_unavailable');
        }

        if (! $context->cursorCommitted
            || $context->hasOfficialResult
            || ! $this->sameIdentity($context->identity, $lease->identity)) {
            return $this->block($lease, 'not_eligible');
        }

        if (! $this->policy->isEnabled()) {
            return $this->block($lease, 'provider_disabled');
        }

        return $this->processor->process($lease, $context, $this->policy->maxProviderAttempts);
    }

    public function failAfterQueueExhaustion(int $preAnalysisId): void
    {
        $identity = $this->executionRepository->failAfterQueueExhaustion(
            $preAnalysisId,
            'queue_attempts_exhausted',
            new DateTimeImmutable('now'),
        );

        if ($identity !== null) {
            $this->processor->recordQueueExhaustion($identity->threadId, 'queue_attempts_exhausted');
        }
    }

    private function sameIdentity(PreAnalysisIdentity $left, PreAnalysisIdentity $right): bool
    {
        return $left->accountId === $right->accountId
            && $left->threadId === $right->threadId
            && $left->threadSnapshotHash === $right->threadSnapshotHash
            && $left->categoryCatalogVersion === $right->categoryCatalogVersion
            && $left->providerModel === $right->providerModel
            && $left->privacyPolicyVersion === $right->privacyPolicyVersion;
    }

    private function block(PreAnalysisLease $lease, string $reasonCode): PreAnalysisExecutionOutcome
    {
        return $this->executionRepository->block($lease, $reasonCode, new DateTimeImmutable('now'))
            ? PreAnalysisExecutionOutcome::Blocked
            : PreAnalysisExecutionOutcome::Duplicate;
    }
}
