<?php

declare(strict_types=1);

namespace App\Domain\Capture\Services;

use App\Domain\Capture\Data\CanonicalMessage;

final class MessageVersionPolicy
{
    /** @param list<string> $storedContentHashes */
    public function shouldAppend(CanonicalMessage $message, array $storedContentHashes): bool
    {
        return ! in_array($message->contentHash, $storedContentHashes, true);
    }
}
