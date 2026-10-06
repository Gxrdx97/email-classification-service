<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

describe('migration PostgreSQL do outbox de despacho da Pré-análise', function () {
    it('cria a relação, a chave derivada e o índice de claim', function () {
        $columns = DB::select(
            <<<'SQL'
SELECT column_name, is_generated
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'pre_analysis_dispatch_outbox'
SQL
        );
        $columnNames = array_map(static fn (object $column): string => $column->column_name, $columns);
        $idempotencyColumn = array_values(array_filter(
            $columns,
            static fn (object $column): bool => $column->column_name === 'idempotency_key'
        ))[0] ?? null;

        expect($columnNames)->toContain(
            'pre_analysis_id',
            'idempotency_key',
            'status',
            'attempt_count',
            'available_at',
            'leased_at',
            'lease_expires_at',
            'dispatched_at',
            'last_error_code'
        )
            ->and($columnNames)->not->toContain('account_id', 'thread_id', 'raw_payload', 'payload')
            ->and($idempotencyColumn?->is_generated)->toBe('ALWAYS');

        $foreignKey = DB::selectOne(
            <<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'pre_analysis_dispatch_outbox'::regclass
  AND contype = 'f'
SQL
        );

        expect($foreignKey?->definition)->toMatch(
            '/FOREIGN KEY \(pre_analysis_id\) REFERENCES pre_analyses\(id\) ON DELETE RESTRICT/'
        );

        $uniqueDefinitions = array_map(
            static fn (object $constraint): string => $constraint->definition,
            DB::select(
                <<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'pre_analysis_dispatch_outbox'::regclass
  AND contype = 'u'
SQL
            )
        );

        expect($uniqueDefinitions)->toContain('UNIQUE (pre_analysis_id)', 'UNIQUE (idempotency_key)');

        $preAnalysisIdentityDefinitions = array_map(
            static fn (object $constraint): string => $constraint->definition,
            DB::select(
                <<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'pre_analyses'::regclass
  AND contype = 'u'
SQL
            )
        );

        expect($preAnalysisIdentityDefinitions)->toContain(
            'UNIQUE (account_id, thread_id, thread_snapshot_hash, category_catalog_version, provider_model, privacy_policy_version)'
        );

        $claimIndex = DB::table('pg_indexes')
            ->where('schemaname', DB::selectOne('SELECT current_schema() AS schema_name')->schema_name)
            ->where('tablename', 'pre_analysis_dispatch_outbox')
            ->where('indexname', 'pre_analysis_dispatch_outbox_claim_idx')
            ->value('indexdef');

        expect($claimIndex)->toMatch('/\(status, available_at, id\)$/');
    });

    it('mantém uma única intenção por Pré-análise com chave estável sem conteúdo bruto', function () {
        $firstPreAnalysisId = createSyntheticPreAnalysisForDispatchOutboxMigration('unique-first');
        $secondPreAnalysisId = createSyntheticPreAnalysisForDispatchOutboxMigration('unique-second');

        DB::table('pre_analysis_dispatch_outbox')->insert([
            'pre_analysis_id' => $firstPreAnalysisId,
        ]);

        $firstOutbox = DB::table('pre_analysis_dispatch_outbox')
            ->where('pre_analysis_id', $firstPreAnalysisId)
            ->first();

        expect($firstOutbox->idempotency_key)->toBe('pre-analysis:'.$firstPreAnalysisId)
            ->and($firstOutbox->status)->toBe('pending')
            ->and((int) $firstOutbox->attempt_count)->toBe(0)
            ->and($firstOutbox->available_at)->not->toBeNull()
            ->and($firstOutbox->leased_at)->toBeNull()
            ->and($firstOutbox->lease_expires_at)->toBeNull()
            ->and($firstOutbox->dispatched_at)->toBeNull();

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')->insert([
            'pre_analysis_id' => $firstPreAnalysisId,
        ]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')->insert([
            'pre_analysis_id' => $secondPreAnalysisId,
            'idempotency_key' => 'synthetic@example.invalid',
        ]));

        DB::table('pre_analysis_dispatch_outbox')->insert([
            'pre_analysis_id' => $secondPreAnalysisId,
        ]);

        $secondOutbox = DB::table('pre_analysis_dispatch_outbox')
            ->where('pre_analysis_id', $secondPreAnalysisId)
            ->first();

        expect($secondOutbox->idempotency_key)->toBe('pre-analysis:'.$secondPreAnalysisId)
            ->and($secondOutbox->idempotency_key)->not->toBe($firstOutbox->idempotency_key);
    });

    it('rejeita referências inexistentes e combinações inválidas de estado, lease e erro', function () {
        $preAnalysisId = createSyntheticPreAnalysisForDispatchOutboxMigration('constraints');
        $outboxId = DB::table('pre_analysis_dispatch_outbox')->insertGetId([
            'pre_analysis_id' => $preAnalysisId,
        ]);

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')->insert([
            'pre_analysis_id' => PHP_INT_MAX,
        ]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update(['status' => 'queued']));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update(['attempt_count' => -1]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'status' => 'retryable',
                'last_error_code' => 'Connection failed: raw exception details',
            ]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update(['status' => 'retryable']));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'status' => 'processing',
                'leased_at' => '2026-10-04T12:00:00+00:00',
            ]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'status' => 'processing',
                'leased_at' => '2026-10-04T12:00:00+00:00',
                'lease_expires_at' => '2026-10-04T12:00:00+00:00',
            ]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'leased_at' => '2026-10-04T12:00:00+00:00',
                'lease_expires_at' => '2026-10-04T12:01:00+00:00',
            ]));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update(['status' => 'dispatched']));

        expectDispatchOutboxConstraintViolation(static fn () => DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update(['dispatched_at' => '2026-10-04T12:00:00+00:00']));

        DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'status' => 'retryable',
                'attempt_count' => 1,
                'last_error_code' => 'queue_unavailable',
            ]);

        DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'status' => 'processing',
                'last_error_code' => null,
                'leased_at' => '2026-10-04T12:00:00+00:00',
                'lease_expires_at' => '2026-10-04T12:01:00+00:00',
            ]);

        DB::table('pre_analysis_dispatch_outbox')
            ->where('id', $outboxId)
            ->update([
                'status' => 'dispatched',
                'leased_at' => null,
                'lease_expires_at' => null,
                'dispatched_at' => '2026-10-04T12:02:00+00:00',
            ]);

        expect(DB::table('pre_analysis_dispatch_outbox')->where('id', $outboxId)->value('status'))
            ->toBe('dispatched');

        $deadPreAnalysisId = createSyntheticPreAnalysisForDispatchOutboxMigration('constraints-dead');
        DB::table('pre_analysis_dispatch_outbox')->insert([
            'pre_analysis_id' => $deadPreAnalysisId,
            'status' => 'dead',
            'last_error_code' => 'attempts_exhausted',
        ]);

        expect(DB::table('pre_analysis_dispatch_outbox')
            ->where('pre_analysis_id', $deadPreAnalysisId)
            ->value('status'))->toBe('dead');
    });

    it('reverte Cursor, Pré-análise e outbox juntos quando a transação falha', function () {
        DB::table('gmail_accounts')->insert(['id' => 1]);
        DB::table('sync_state')->insert(['account_id' => 1]);

        $snapshotHash = hash('sha256', 'snapshot-atomic-rollback');
        $threadId = DB::table('threads')->insertGetId([
            'account_id' => 1,
            'gmail_thread_id' => 'thread-synthetic-atomic-rollback',
            'thread_snapshot_hash' => $snapshotHash,
        ]);

        expect(static fn () => DB::transaction(function () use ($snapshotHash, $threadId): void {
            $preAnalysisId = DB::table('pre_analyses')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'thread_snapshot_hash' => $snapshotHash,
                'category_catalog_version' => 'catalog-synthetic-atomic-rollback',
                'provider_model' => 'model-synthetic-v1',
                'question_version' => 'question-v1',
                'schema_version' => 'schema-v1',
                'privacy_policy_version' => 'policy-synthetic-atomic-rollback',
            ]);

            DB::table('sync_state')->where('account_id', 1)->update([
                'history_id' => 'cursor-synthetic-after',
                'cursor_generation' => 1,
            ]);

            DB::table('pre_analysis_dispatch_outbox')->insert([
                'pre_analysis_id' => $preAnalysisId,
            ]);

            throw new RuntimeException('rollback atomicity proof');
        }))->toThrow(RuntimeException::class, 'rollback atomicity proof');

        $syncState = DB::table('sync_state')->where('account_id', 1)->first();

        expect($syncState->history_id)->toBeNull()
            ->and((int) $syncState->cursor_generation)->toBe(0)
            ->and(DB::table('pre_analyses')->where('thread_id', $threadId)->count())->toBe(0)
            ->and(DB::table('pre_analysis_dispatch_outbox')->count())->toBe(0);
    });
});

function createSyntheticPreAnalysisForDispatchOutboxMigration(string $suffix): int
{
    if (! DB::table('gmail_accounts')->where('id', 1)->exists()) {
        DB::table('gmail_accounts')->insert(['id' => 1]);
    }

    $snapshotHash = hash('sha256', 'snapshot-'.$suffix);
    $threadId = DB::table('threads')->insertGetId([
        'account_id' => 1,
        'gmail_thread_id' => 'thread-synthetic-'.$suffix,
        'thread_snapshot_hash' => $snapshotHash,
    ]);

    return (int) DB::table('pre_analyses')->insertGetId([
        'account_id' => 1,
        'thread_id' => $threadId,
        'thread_snapshot_hash' => $snapshotHash,
        'category_catalog_version' => 'catalog-'.$suffix,
        'provider_model' => 'model-synthetic-v1',
        'question_version' => 'question-v1',
        'schema_version' => 'schema-v1',
        'privacy_policy_version' => 'policy-'.$suffix,
    ]);
}

function expectDispatchOutboxConstraintViolation(Closure $attempt): void
{
    expect(static fn () => DB::transaction($attempt))->toThrow(QueryException::class);
}
