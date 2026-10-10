<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Contracts;

use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\PreAnalysis\Data\PreAnalysisProposal;
use DateTimeImmutable;

interface PreAnalysisExecutionRepository
{
    public function claim(int $preAnalysisId, DateTimeImmutable $now, int $staleAfterSeconds): ?PreAnalysisLease;

    /**
     * Persists only advisory suggestions, safe usage counters and Example IDs/versions.
     * It must never write official classifications or operational state.
     *
     * @param  list<array{id: int, version: int, category_version_id: int}>  $exampleProvenance
     */
    public function complete(
        PreAnalysisLease $lease,
        PreAnalysisProposal $proposal,
        array $exampleProvenance,
        DateTimeImmutable $finishedAt,
    ): bool;

    public function block(PreAnalysisLease $lease, string $reasonCode, DateTimeImmutable $finishedAt): bool;

    public function fail(PreAnalysisLease $lease, string $failureCode, DateTimeImmutable $finishedAt): bool;

    public function releaseForRetry(PreAnalysisLease $lease): bool;

    public function failAfterQueueExhaustion(int $preAnalysisId, string $failureCode, DateTimeImmutable $finishedAt): ?PreAnalysisIdentity;
}
