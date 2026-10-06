<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Enums;

enum PreAnalysisExecutionOutcome: string
{
    case Completed = 'completed';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Duplicate = 'duplicate';
}
