<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Persistence;

use App\Domain\Privacy\Contracts\ThreadPrivacyConsentRepository;
use App\Domain\Privacy\Enums\PrivacyConsentDecision;
use Illuminate\Support\Facades\DB;

final class PostgresThreadPrivacyConsentRepository implements ThreadPrivacyConsentRepository
{
    public function decisionFor(int $threadId, string $privacyPolicyVersion): ?PrivacyConsentDecision
    {
        $decision = DB::table('thread_privacy_consents')
            ->where('thread_id', $threadId)
            ->where('privacy_policy_version', $privacyPolicyVersion)
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->value('decision');

        return is_string($decision) ? PrivacyConsentDecision::tryFrom($decision) : null;
    }
}
