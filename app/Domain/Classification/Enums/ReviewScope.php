<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

enum ReviewScope: string
{
    case Thread = 'thread';
    case Message = 'message';
}
