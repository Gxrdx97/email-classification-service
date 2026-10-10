<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Persistence;

use App\Domain\PreAnalysis\Contracts\PreAnalysisExecutionRepository;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\PreAnalysis\Data\PreAnalysisProposal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class PostgresPreAnalysisExecutionRepository implements PreAnalysisExecutionRepository
{
    public function claim(int $preAnalysisId, DateTimeImmutable $now, int $staleAfterSeconds): ?PreAnalysisLease
    {
        if ($preAnalysisId < 1 || $staleAfterSeconds < 1 || $staleAfterSeconds > 3600) {
            throw new InvalidArgumentException('Pre-analysis claim parameters are outside their supported bounds.');
        }

        $nowValue = $this->timestamp($now);
        $staleBefore = $this->timestamp($now->modify('-'.$staleAfterSeconds.' seconds'));

        $row = DB::selectOne(
            <<<'SQL'
UPDATE pre_analyses AS pre_analysis
SET status = 'running',
    started_at = :started_now,
    updated_at = :updated_now,
    usage_metadata = jsonb_set(
        COALESCE(pre_analysis.usage_metadata, '{}'::jsonb),
        '{provider_attempt_count}',
        to_jsonb(
            CASE
                WHEN COALESCE(pre_analysis.usage_metadata->>'provider_attempt_count', '0') ~ '^[0-9]{1,6}$'
                    THEN (pre_analysis.usage_metadata->>'provider_attempt_count')::integer + 1
                ELSE 1
            END
        ),
        true
    )
WHERE pre_analysis.id = :pre_analysis_id
  AND (
        pre_analysis.status = 'queued'
        OR (pre_analysis.status = 'running' AND pre_analysis.started_at <= :stale_before)
  )
  AND EXISTS (
        SELECT 1
        FROM pre_analysis_dispatch_outbox AS dispatch_outbox
        WHERE dispatch_outbox.pre_analysis_id = pre_analysis.id
  )
  AND EXISTS (
        SELECT 1
        FROM threads AS thread
        WHERE thread.id = pre_analysis.thread_id
          AND thread.latest_official_classification_id IS NULL
  )
RETURNING pre_analysis.id, pre_analysis.account_id, pre_analysis.thread_id,
          pre_analysis.thread_snapshot_hash, pre_analysis.category_catalog_version,
          pre_analysis.provider_model, pre_analysis.privacy_policy_version,
          pre_analysis.started_at, pre_analysis.usage_metadata
SQL,
            [
                'started_now' => $nowValue,
                'updated_now' => $nowValue,
                'pre_analysis_id' => $preAnalysisId,
                'stale_before' => $staleBefore,
            ],
        );

        if ($row === null) {
            return null;
        }

        $metadata = $this->decodeObject($row->usage_metadata);
        $attemptNumber = $metadata['provider_attempt_count'] ?? 1;

        if (! is_int($attemptNumber) || $attemptNumber < 1) {
            $attemptNumber = 1;
        }

        return new PreAnalysisLease(
            (int) $row->id,
            new PreAnalysisIdentity(
                (int) $row->account_id,
                (int) $row->thread_id,
                (string) $row->thread_snapshot_hash,
                (string) $row->category_catalog_version,
                (string) $row->provider_model,
                (string) $row->privacy_policy_version,
            ),
            new DateTimeImmutable((string) $row->started_at),
            $attemptNumber,
        );
    }

    public function complete(
        PreAnalysisLease $lease,
        PreAnalysisProposal $proposal,
        array $exampleProvenance,
        DateTimeImmutable $finishedAt,
    ): bool {
        return DB::transaction(function () use ($lease, $proposal, $exampleProvenance, $finishedAt): bool {
            $current = $this->leasedRow($lease);

            if ($current === null) {
                return false;
            }

            foreach ($proposal->suggestions as $suggestion) {
                DB::table('category_suggestions')->insert([
                    'pre_analysis_id' => $lease->preAnalysisId,
                    'category_version_id' => $suggestion->categoryVersionId,
                    'probability' => $suggestion->probability,
                    'uncertainty_code' => $suggestion->uncertaintyCode,
                    'structural_conflict' => $suggestion->structuralConflict,
                    'evidence_refs' => '[]',
                ]);
            }

            $metadata = $this->decodeObject($current->usage_metadata);
            $metadata['usage'] = $proposal->usage;
            $metadata['none_applies'] = $proposal->noneApplies;
            $metadata['inconclusive_reason'] = $proposal->inconclusiveReason;
            $metadata['retrieved_examples'] = $exampleProvenance;

            return DB::table('pre_analyses')
                ->where('id', $lease->preAnalysisId)
                ->where('status', 'running')
                ->where('started_at', $this->timestamp($lease->startedAt))
                ->update([
                    'status' => 'completed',
                    'usage_metadata' => $this->encodeObject($metadata),
                    'finished_at' => $this->timestamp($finishedAt),
                    'updated_at' => $this->timestamp($finishedAt),
                ]) === 1;
        });
    }

    public function block(PreAnalysisLease $lease, string $reasonCode, DateTimeImmutable $finishedAt): bool
    {
        $this->assertCode($reasonCode);

        return DB::transaction(function () use ($lease, $reasonCode, $finishedAt): bool {
            $current = $this->leasedRow($lease);

            if ($current === null) {
                return false;
            }

            $metadata = $this->decodeObject($current->usage_metadata);
            $metadata['block_reason'] = $reasonCode;

            return DB::table('pre_analyses')
                ->where('id', $lease->preAnalysisId)
                ->where('status', 'running')
                ->where('started_at', $this->timestamp($lease->startedAt))
                ->update([
                    'status' => 'blocked',
                    'usage_metadata' => $this->encodeObject($metadata),
                    'finished_at' => $this->timestamp($finishedAt),
                    'updated_at' => $this->timestamp($finishedAt),
                ]) === 1;
        });
    }

    public function fail(PreAnalysisLease $lease, string $failureCode, DateTimeImmutable $finishedAt): bool
    {
        $this->assertCode($failureCode);

        return DB::table('pre_analyses')
            ->where('id', $lease->preAnalysisId)
            ->where('status', 'running')
            ->where('started_at', $this->timestamp($lease->startedAt))
            ->update([
                'status' => 'failed',
                'failure_code' => $failureCode,
                'finished_at' => $this->timestamp($finishedAt),
                'updated_at' => $this->timestamp($finishedAt),
            ]) === 1;
    }

    public function releaseForRetry(PreAnalysisLease $lease): bool
    {
        return DB::table('pre_analyses')
            ->where('id', $lease->preAnalysisId)
            ->where('status', 'running')
            ->where('started_at', $this->timestamp($lease->startedAt))
            ->update([
                'status' => 'queued',
                'started_at' => null,
                'updated_at' => $this->timestamp(new DateTimeImmutable('now')),
            ]) === 1;
    }

    public function failAfterQueueExhaustion(
        int $preAnalysisId,
        string $failureCode,
        DateTimeImmutable $finishedAt,
    ): ?PreAnalysisIdentity {
        $this->assertCode($failureCode);

        $row = DB::selectOne(
            <<<'SQL'
UPDATE pre_analyses AS pre_analysis
SET status = 'failed',
    failure_code = :failure_code,
    finished_at = :finished_at,
    updated_at = :updated_at
WHERE pre_analysis.id = :pre_analysis_id
  AND pre_analysis.status IN ('queued', 'running')
  AND EXISTS (
        SELECT 1
        FROM threads AS thread
        WHERE thread.id = pre_analysis.thread_id
          AND thread.latest_official_classification_id IS NULL
  )
RETURNING pre_analysis.account_id, pre_analysis.thread_id, pre_analysis.thread_snapshot_hash,
          pre_analysis.category_catalog_version, pre_analysis.provider_model,
          pre_analysis.privacy_policy_version
SQL,
            [
                'failure_code' => $failureCode,
                'finished_at' => $this->timestamp($finishedAt),
                'updated_at' => $this->timestamp($finishedAt),
                'pre_analysis_id' => $preAnalysisId,
            ],
        );

        if ($row === null) {
            return null;
        }

        return new PreAnalysisIdentity(
            (int) $row->account_id,
            (int) $row->thread_id,
            (string) $row->thread_snapshot_hash,
            (string) $row->category_catalog_version,
            (string) $row->provider_model,
            (string) $row->privacy_policy_version,
        );
    }

    private function leasedRow(PreAnalysisLease $lease): ?object
    {
        return DB::table('pre_analyses')
            ->where('id', $lease->preAnalysisId)
            ->where('status', 'running')
            ->where('started_at', $this->timestamp($lease->startedAt))
            ->lockForUpdate()
            ->first();
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $value */
    private function encodeObject(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new RuntimeException('Pre-analysis metadata could not be encoded.');
        }
    }

    private function assertCode(string $code): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw new InvalidArgumentException('Pre-analysis reason codes must be sanitized identifiers.');
        }
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
