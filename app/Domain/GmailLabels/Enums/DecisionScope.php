<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Enums;

enum DecisionScope: string
{
    case THREAD = 'thread';
    case MESSAGE = 'message';
}
