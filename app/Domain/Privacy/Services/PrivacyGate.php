<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Services;

use App\Domain\Privacy\Data\PrivacyAssessment;
use App\Domain\Privacy\Data\PrivacyPolicy;
use App\Domain\Privacy\Enums\PrivacyAction;
use App\Domain\Privacy\Enums\PrivacyOutcome;
use Psr\Log\LoggerInterface;

final readonly class PrivacyGate
{
    public function __construct(
        private DlpScanner $dlpScanner,
        private PromptInjectionDetector $injectionDetector,
        private PayloadRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    /**
     * The supplied payload must be the complete dynamic representation about to be sent,
     * including the whole Thread, active Categories and retrieved Examples.
     *
     * @param  array<string, mixed>  $payload
     */
    public function inspect(array $payload, int $threadId, PrivacyPolicy $policy): PrivacyAssessment
    {
        $findings = $this->dlpScanner->findingCodes($payload);

        if ($this->injectionDetector->detected($payload)) {
            $findings[] = 'prompt_injection';
        }

        $findings = array_values(array_unique($findings));

        if ($findings === []) {
            return new PrivacyAssessment(PrivacyOutcome::Allowed, [], $payload);
        }

        $this->logger->notice('privacy_gate.content_flagged', [
            'thread_id' => $threadId,
            'policy_version' => $policy->version,
            'action' => $policy->action->value,
            'finding_codes' => $findings,
        ]);

        if (in_array('unsupported_payload_value', $findings, true)) {
            return new PrivacyAssessment(PrivacyOutcome::Aborted, $findings, null);
        }

        return match ($policy->action) {
            PrivacyAction::Abort => new PrivacyAssessment(PrivacyOutcome::Aborted, $findings, null),
            PrivacyAction::Flag => new PrivacyAssessment(PrivacyOutcome::ConsentRequired, $findings, null),
            PrivacyAction::Redact => $this->redactedAssessment($payload, $threadId, $policy, $findings),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $findings
     */
    private function redactedAssessment(
        array $payload,
        int $threadId,
        PrivacyPolicy $policy,
        array $findings,
    ): PrivacyAssessment {
        $redacted = $this->redactor->redact($payload);
        $residualFindings = $this->dlpScanner->findingCodes($redacted);

        if ($this->injectionDetector->detected($redacted)) {
            $residualFindings[] = 'prompt_injection';
        }

        if ($residualFindings !== []) {
            $this->logger->warning('privacy_gate.redaction_incomplete', [
                'thread_id' => $threadId,
                'policy_version' => $policy->version,
                'finding_codes' => array_values(array_unique($residualFindings)),
            ]);

            return new PrivacyAssessment(
                PrivacyOutcome::Aborted,
                ['redaction_incomplete'],
                null,
            );
        }

        return new PrivacyAssessment(PrivacyOutcome::Redacted, $findings, $redacted);
    }
}
