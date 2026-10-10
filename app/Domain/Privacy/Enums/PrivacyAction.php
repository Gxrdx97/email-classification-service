<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Enums;

enum PrivacyAction: string
{
    case Abort = 'abort';
    case Flag = 'flag';
    case Redact = 'redact';
}
