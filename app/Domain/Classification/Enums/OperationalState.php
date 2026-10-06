<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

enum OperationalState: string
{
    case Pending = 'pending';
    case Categorized = 'categorized';
    case Uncategorized = 'uncategorized';
    case Inconclusive = 'inconclusive';
    case Failed = 'failed';
}
