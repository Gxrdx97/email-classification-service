<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

enum ReviewAction: string
{
    case ConfirmSuggestion = 'confirm_suggestion';
    case Correct = 'correct';
    case RejectSuggestion = 'reject_suggestion';
    case Manual = 'manual';
}
