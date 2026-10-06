<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Services;

use App\Domain\PreAnalysis\Contracts\PreAnalysisOutboxRepository;
use App\Domain\Privacy\Contracts\ThreadPrivacyConsentRepository;
use App\Domain\Privacy\Enums\PrivacyConsentDecision;
use DateTimeImmutable;

final readonly class PreAnalysisConsentWakeup
{
    public function __construct(
        private ThreadPrivacyConsentRepository $consents,
        private PreAnalysisOutboxRepository $outbox,
    ) {}

    /** Call after persisting an explicit consent decision for the Thread. */
    public function resumeAllowedThread(int $threadId, string $privacyPolicyVersion): int
    {
        if ($threadId < 1
            || $this->consents->decisionFor($threadId, $privacyPolicyVersion) !== PrivacyConsentDecision::Allow) {
            return 0;
        }

        return $this->outbox->resumeAwaitingConsent(
            $threadId,
            $privacyPolicyVersion,
            new DateTimeImmutable('now'),
        );
    }
}
