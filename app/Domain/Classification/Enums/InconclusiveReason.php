<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

enum InconclusiveReason: string
{
    case LowConfidence = 'low_confidence';
    case Conflict = 'conflict';
    case NoMatch = 'no_match';
    case InsufficientContent = 'insufficient_content';
}
