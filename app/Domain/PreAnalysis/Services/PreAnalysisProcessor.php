<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Services;

use App\Domain\PreAnalysis\Contracts\PreAnalysisExecutionRepository;
use App\Domain\PreAnalysis\Contracts\PreAnalysisOperationalFailureRecorder;
use App\Domain\PreAnalysis\Contracts\PreAnalysisProvider;
use App\Domain\PreAnalysis\Data\PreAnalysisContext;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\PreAnalysis\Data\PreAnalysisProposal;
use App\Domain\PreAnalysis\Data\PreAnalysisSubmission;
use App\Domain\PreAnalysis\Data\PreAnalysisSuggestion;
use App\Domain\PreAnalysis\Enums\PreAnalysisExecutionOutcome;
use App\Domain\PreAnalysis\Exceptions\InvalidPreAnalysisOutput;
use App\Domain\PreAnalysis\Exceptions\PreAnalysisProviderException;
use App\Domain\PreAnalysis\Exceptions\RetryablePreAnalysisException;
use App\Domain\Privacy\Contracts\ThreadPrivacyConsentRepository;
use App\Domain\Privacy\Data\PrivacyPolicy;
use App\Domain\Privacy\Enums\PrivacyConsentDecision;
use App\Domain\Privacy\Enums\PrivacyOutcome;
use App\Domain\Privacy\Services\PrivacyGate;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PreAnalysisProcessor
{
    public function __construct(
        private PreAnalysisProvider $provider,
        private PreAnalysisExecutionRepository $executionRepository,
        private PreAnalysisOperationalFailureRecorder $failureRecorder,
        private ThreadPrivacyConsentRepository $consents,
        private PrivacyGate $privacyGate,
        private PreAnalysisOutputValidator $outputValidator,
        private LoggerInterface $logger,
    ) {}

    public function process(
        PreAnalysisLease $lease,
        PreAnalysisContext $context,
        int $maxAttempts,
    ): PreAnalysisExecutionOutcome {
        if (! $context->cursorCommitted
            || $context->hasOfficialResult
            || ! $this->sameIdentity($context->identity, $lease->identity)) {
            return $this->block($lease, 'not_eligible');
        }

        if (! $this->provider->isEnabled()) {
            return $this->block($lease, 'provider_disabled');
        }

        $payload = $context->providerPayload();
        $assessment = $this->privacyGate->inspect(
            $payload,
            $context->identity->threadId,
            new PrivacyPolicy($context->identity->privacyPolicyVersion, $context->privacyAction),
        );

        if ($assessment->outcome === PrivacyOutcome::Aborted) {
            return $this->block($lease, 'privacy_abort');
        }

        if ($assessment->outcome === PrivacyOutcome::ConsentRequired) {
            try {
                $consent = $this->consents->decisionFor(
                    $context->identity->threadId,
                    $context->identity->privacyPolicyVersion,
                );
            } catch (Throwable) {
                return $this->block($lease, 'consent_unavailable');
            }

            if ($consent !== PrivacyConsentDecision::Allow) {
                $reason = $consent === PrivacyConsentDecision::Deny ? 'consent_denied' : 'consent_required';

                return $this->block($lease, $reason);
            }

            // Explicit per-Thread consent is required for the flagged, otherwise unchanged payload.
        } elseif ($assessment->outcome === PrivacyOutcome::Redacted) {
            $payload = $assessment->permittedPayload ?? [];
        } else {
            $payload = $assessment->permittedPayload ?? [];
        }

        if ($payload === []) {
            return $this->block($lease, 'privacy_payload_unavailable');
        }

        $submission = new PreAnalysisSubmission(
            $context->identity,
            $context->questionVersion,
            $context->schemaVersion,
            $context->allowedCategoryVersionIds(),
            $payload,
        );

        try {
            $providerProposal = $this->provider->analyze($submission);
            $proposal = $this->validateProposal($providerProposal, $context);
        } catch (InvalidPreAnalysisOutput) {
            return $this->fail($lease, 'invalid_provider_output', false, $maxAttempts);
        } catch (PreAnalysisProviderException $exception) {
            return $this->fail($lease, $exception->errorCode, $exception->retryable, $maxAttempts);
        } catch (Throwable) {
            return $this->fail($lease, 'provider_failure', true, $maxAttempts);
        }

        $completed = $this->executionRepository->complete(
            $lease,
            $proposal,
            $context->exampleProvenance(),
            new DateTimeImmutable('now'),
        );

        return $completed ? PreAnalysisExecutionOutcome::Completed : PreAnalysisExecutionOutcome::Duplicate;
    }

    private function block(PreAnalysisLease $lease, string $reasonCode): PreAnalysisExecutionOutcome
    {
        return $this->executionRepository->block($lease, $reasonCode, new DateTimeImmutable('now'))
            ? PreAnalysisExecutionOutcome::Blocked
            : PreAnalysisExecutionOutcome::Duplicate;
    }

    private function fail(
        PreAnalysisLease $lease,
        string $failureCode,
        bool $retryable,
        int $maxAttempts,
    ): PreAnalysisExecutionOutcome {
        if ($retryable && $lease->attemptNumber < $maxAttempts) {
            if ($this->executionRepository->releaseForRetry($lease)) {
                $this->logger->warning('preanalysis.provider_retry', [
                    'thread_id' => $lease->identity->threadId,
                    'attempt_number' => $lease->attemptNumber,
                    'error_code' => $failureCode,
                ]);

                throw new RetryablePreAnalysisException($failureCode);
            }

            return PreAnalysisExecutionOutcome::Duplicate;
        }

        if ($this->executionRepository->fail($lease, $failureCode, new DateTimeImmutable('now'))) {
            $this->failureRecorder->markFailedWithoutReplacingOfficialResult(
                $lease->identity->threadId,
                $failureCode,
            );
            $this->logger->error('preanalysis.provider_failed', [
                'thread_id' => $lease->identity->threadId,
                'attempt_number' => $lease->attemptNumber,
                'error_code' => $failureCode,
            ]);

            return PreAnalysisExecutionOutcome::Failed;
        }

        return PreAnalysisExecutionOutcome::Duplicate;
    }

    public function handleFailure(
        PreAnalysisLease $lease,
        string $failureCode,
        bool $retryable,
        int $maxAttempts,
    ): PreAnalysisExecutionOutcome {
        return $this->fail($lease, $failureCode, $retryable, $maxAttempts);
    }

    public function recordQueueExhaustion(int $threadId, string $failureCode): void
    {
        $this->failureRecorder->markFailedWithoutReplacingOfficialResult($threadId, $failureCode);
        $this->logger->error('preanalysis.provider_failed', [
            'thread_id' => $threadId,
            'error_code' => $failureCode,
        ]);
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

    private function validateProposal(PreAnalysisProposal $proposal, PreAnalysisContext $context): PreAnalysisProposal
    {
        $suggestions = array_map(
            static fn (PreAnalysisSuggestion $suggestion): array => [
                'category_version_id' => $suggestion->categoryVersionId,
                'probability' => $suggestion->probability,
                'uncertainty_code' => $suggestion->uncertaintyCode,
                'structural_conflict' => $suggestion->structuralConflict,
            ],
            $proposal->suggestions,
        );

        return $this->outputValidator->validate([
            'suggestions' => $suggestions,
            'none_applies' => $proposal->noneApplies,
            'inconclusive_reason' => $proposal->inconclusiveReason,
        ], $context->allowedCategoryVersionIds(), $proposal->usage);
    }
}
