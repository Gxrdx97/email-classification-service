<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

enum OfficialOutcome: string
{
    case Categorized = 'categorized';
    case Uncategorized = 'uncategorized';
    case Inconclusive = 'inconclusive';
}
