<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Contracts;

use App\Domain\Privacy\Enums\PrivacyConsentDecision;

interface ThreadPrivacyConsentRepository
{
    public function decisionFor(int $threadId, string $privacyPolicyVersion): ?PrivacyConsentDecision;
}
