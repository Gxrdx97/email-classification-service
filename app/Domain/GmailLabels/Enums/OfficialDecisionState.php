<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Enums;

enum OfficialDecisionState: string
{
    case CATEGORIZED = 'categorized';
    case UNCATEGORIZED = 'uncategorized';
    case INCONCLUSIVE = 'inconclusive';
    case FAILED = 'failed';
}
