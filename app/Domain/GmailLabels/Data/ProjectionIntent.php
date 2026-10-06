<?php

declare(strict_types=1);

namespace App\Domain\GmailLabels\Data;

use InvalidArgumentException;

final readonly class ProjectionIntent
{
    public function __construct(
        public int $outboxId,
        public int $generation,
        public DesiredMessageLabelProjection $desired,
        public string $idempotencyKey,
        public string $desiredHash,
        public int $attemptCount,
    ) {
        if ($outboxId < 1 || $generation < 1 || $attemptCount < 1) {
            throw new InvalidArgumentException('Projection intent identifiers or attempt count are invalid.');
        }
    }
}
