<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use InvalidArgumentException;

final readonly class ProjectionOutboxReceipt
{
    public function __construct(
        public int $outboxId,
        public int $messageId,
    ) {
        if ($outboxId < 1 || $messageId < 1) {
            throw new InvalidArgumentException('Projection outbox identifiers must be positive.');
        }
    }
}
