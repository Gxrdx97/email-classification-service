<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Enums;

enum PrivacyConsentDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
