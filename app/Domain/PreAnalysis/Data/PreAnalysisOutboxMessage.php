<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

use DateTimeImmutable;

final readonly class PreAnalysisOutboxMessage
{
    public function __construct(
        public int $id,
        public int $preAnalysisId,
        public string $idempotencyKey,
        public int $attemptCount,
        public DateTimeImmutable $leasedAt,
    ) {}
}
