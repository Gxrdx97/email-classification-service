<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Persistence;

use App\Domain\PreAnalysis\Contracts\PreAnalysisOutboxRepository;
use App\Domain\PreAnalysis\Data\PreAnalysisOutboxMessage;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostgresPreAnalysisOutboxRepository implements PreAnalysisOutboxRepository
{
    public function claimAvailable(
        int $limit,
        int $leaseSeconds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): array {
        if ($limit < 1 || $limit > 100 || $leaseSeconds < 1 || $maxAttempts < 1) {
            throw new InvalidArgumentException('Outbox claim parameters are outside their supported bounds.');
        }

        $nowValue = $this->timestamp($now);
        $leaseExpiresAt = $this->timestamp($now->modify('+'.$leaseSeconds.' seconds'));

        return DB::transaction(function () use ($limit, $maxAttempts, $nowValue, $leaseExpiresAt): array {
            DB::update(
                <<<'SQL'
UPDATE pre_analysis_dispatch_outbox
SET status = 'dead',
    leased_at = NULL,
    lease_expires_at = NULL,
    last_error_code = 'attempts_exhausted',
    updated_at = :updated_at
WHERE attempt_count >= :max_attempts
  AND (
        (status IN ('pending', 'retryable') AND available_at <= :available_at)
        OR (status = 'processing' AND lease_expires_at <= :expired_at)
  )
SQL,
                [
                    'updated_at' => $nowValue,
                    'max_attempts' => $maxAttempts,
                    'available_at' => $nowValue,
                    'expired_at' => $nowValue,
                ],
            );

            $rows = DB::select(
                <<<'SQL'
WITH candidates AS (
    SELECT id
    FROM pre_analysis_dispatch_outbox
    WHERE attempt_count < :max_attempts
      AND (
            (status IN ('pending', 'retryable') AND available_at <= :available_now)
            OR (status = 'processing' AND lease_expires_at <= :expired_now)
      )
    ORDER BY available_at, id
    FOR UPDATE SKIP LOCKED
    LIMIT :limit
)
UPDATE pre_analysis_dispatch_outbox AS outbox
SET status = 'processing',
    attempt_count = outbox.attempt_count + 1,
    leased_at = :lease_now,
    lease_expires_at = :lease_expires_at,
    last_error_code = NULL,
    updated_at = :updated_now
FROM candidates
WHERE outbox.id = candidates.id
RETURNING outbox.id, outbox.pre_analysis_id, outbox.idempotency_key,
          outbox.attempt_count, outbox.leased_at
SQL,
                [
                    'max_attempts' => $maxAttempts,
                    'available_now' => $nowValue,
                    'expired_now' => $nowValue,
                    'lease_now' => $nowValue,
                    'updated_now' => $nowValue,
                    'limit' => $limit,
                    'lease_expires_at' => $leaseExpiresAt,
                ],
            );

            return array_map(
                fn (object $row): PreAnalysisOutboxMessage => new PreAnalysisOutboxMessage(
                    (int) $row->id,
                    (int) $row->pre_analysis_id,
                    (string) $row->idempotency_key,
                    (int) $row->attempt_count,
                    new DateTimeImmutable((string) $row->leased_at),
                ),
                $rows,
            );
        });
    }

    public function markDispatched(PreAnalysisOutboxMessage $message, DateTimeImmutable $dispatchedAt): bool
    {
        return DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $message->id)
            ->where('idempotency_key', $message->idempotencyKey)
            ->where('status', 'processing')
            ->where('leased_at', $this->timestamp($message->leasedAt))
            ->update([
                'status' => 'dispatched',
                'leased_at' => null,
                'lease_expires_at' => null,
                'dispatched_at' => $this->timestamp($dispatchedAt),
                'last_error_code' => null,
                'updated_at' => $this->timestamp($dispatchedAt),
            ]) === 1;
    }

    public function markFailed(
        PreAnalysisOutboxMessage $message,
        string $errorCode,
        DateTimeImmutable $availableAt,
        bool $terminal,
        DateTimeImmutable $failedAt,
    ): bool {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $errorCode) !== 1) {
            throw new InvalidArgumentException('Outbox error codes must be sanitized identifiers.');
        }

        return DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $message->id)
            ->where('idempotency_key', $message->idempotencyKey)
            ->where('status', 'processing')
            ->where('leased_at', $this->timestamp($message->leasedAt))
            ->update([
                'status' => $terminal ? 'dead' : 'retryable',
                'available_at' => $this->timestamp($availableAt),
                'leased_at' => null,
                'lease_expires_at' => null,
                'dispatched_at' => null,
                'last_error_code' => $errorCode,
                'updated_at' => $this->timestamp($failedAt),
            ]) === 1;
    }

    public function resumeAwaitingConsent(int $threadId, string $privacyPolicyVersion, DateTimeImmutable $now): int
    {
        if ($threadId < 1 || trim($privacyPolicyVersion) === '') {
            return 0;
        }

        return DB::transaction(function () use ($threadId, $privacyPolicyVersion, $now): int {
            $rows = DB::table('pre_analyses as pre_analysis')
                ->join('threads as thread', 'thread.id', '=', 'pre_analysis.thread_id')
                ->join('pre_analysis_dispatch_outbox as dispatch_outbox', 'dispatch_outbox.pre_analysis_id', '=', 'pre_analysis.id')
                ->where('pre_analysis.thread_id', $threadId)
                ->where('pre_analysis.privacy_policy_version', $privacyPolicyVersion)
                ->where('pre_analysis.status', 'blocked')
                ->whereRaw("pre_analysis.usage_metadata->>'block_reason' IN (?, ?)", ['consent_required', 'consent_denied'])
                ->whereNull('thread.latest_official_classification_id')
                ->where('dispatch_outbox.status', 'dispatched')
                ->lockForUpdate()
                ->get(['pre_analysis.id as pre_analysis_id', 'dispatch_outbox.id as outbox_id']);

            foreach ($rows as $row) {
                DB::table('pre_analyses')
                    ->where('id', $row->pre_analysis_id)
                    ->where('status', 'blocked')
                    ->update([
                        'status' => 'queued',
                        'usage_metadata' => DB::raw("usage_metadata - 'block_reason'"),
                        'started_at' => null,
                        'finished_at' => null,
                        'updated_at' => $this->timestamp($now),
                    ]);

                DB::table('pre_analysis_dispatch_outbox')
                    ->where('id', $row->outbox_id)
                    ->where('status', 'dispatched')
                    ->update([
                        'status' => 'retryable',
                        'available_at' => $this->timestamp($now),
                        'dispatched_at' => null,
                        'last_error_code' => 'consent_granted',
                        'updated_at' => $this->timestamp($now),
                    ]);
            }

            return $rows->count();
        });
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
