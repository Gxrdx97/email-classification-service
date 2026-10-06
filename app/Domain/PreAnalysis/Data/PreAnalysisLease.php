<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

use DateTimeImmutable;

final readonly class PreAnalysisLease
{
    public function __construct(
        public int $preAnalysisId,
        public PreAnalysisIdentity $identity,
        public DateTimeImmutable $startedAt,
        public int $attemptNumber,
    ) {}
}
