<?php

declare(strict_types=1);

namespace App\Domain\Capture\Data;

enum SyncMode: string
{
    case Initial = 'initial';
    case Full = 'full';
    case Incremental = 'incremental';
    case ExpiredHistoryResync = 'expired_history_resync';
}
