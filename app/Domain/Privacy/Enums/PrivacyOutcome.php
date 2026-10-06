<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Enums;

enum PrivacyOutcome: string
{
    case Allowed = 'allowed';
    case Redacted = 'redacted';
    case ConsentRequired = 'consent_required';
    case Aborted = 'aborted';
}
