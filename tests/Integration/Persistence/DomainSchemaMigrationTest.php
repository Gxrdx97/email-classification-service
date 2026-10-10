<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('migrations PostgreSQL do schema de domínio', function () {
    it('cria as relações da feature em PostgreSQL 17 com pgvector sem dimensão fixada', function () {
        expect(DB::connection()->getDriverName())->toBe('pgsql');

        $serverVersion = (int) DB::selectOne(
            "SELECT current_setting('server_version_num')::integer AS version_num"
        )->version_num;

        expect($serverVersion)->toBeGreaterThanOrEqual(170000);
        expect(DB::table('pg_extension')->where('extname', 'vector')->exists())->toBeTrue();

        $expectedTables = [
            'gmail_accounts',
            'sync_state',
            'sync_runs',
            'threads',
            'messages',
            'message_versions',
            'message_captured_labels',
            'message_enrichments',
            'enrichment_participants',
            'message_version_attachments',
            'categories',
            'category_versions',
            'reviews',
            'review_category_versions',
            'thread_classifications',
            'thread_classification_categories',
            'message_category_overrides',
            'thread_privacy_consents',
            'pre_analyses',
            'category_suggestions',
            'category_examples',
            'category_example_embeddings',
            'pre_analysis_examples',
            'message_category_label_assignments',
            'gmail_label_projection_states',
            'gmail_label_projection_outbox',
            'gmail_label_projection_outbox_categories',
            'idempotency_records',
        ];

        $actualTables = DB::select(
            <<<'SQL'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = current_schema()
  AND table_type = 'BASE TABLE'
SQL
        );
        $actualTableNames = array_map(static fn (object $row): string => $row->table_name, $actualTables);

        expect($actualTableNames)->toContain(...$expectedTables);

        $embeddingColumn = DB::selectOne(
            <<<'SQL'
SELECT format_type(attribute.atttypid, attribute.atttypmod) AS column_type
FROM pg_attribute AS attribute
JOIN pg_class AS relation ON relation.oid = attribute.attrelid
JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
WHERE namespace.nspname = current_schema()
  AND relation.relname = 'category_example_embeddings'
  AND attribute.attname = 'embedding'
  AND attribute.attnum > 0
  AND NOT attribute.attisdropped
SQL
        );

        expect($embeddingColumn?->column_type)->toBe('vector');

        $hasDimensionBoundApproximateIndex = DB::table('pg_indexes')
            ->where('schemaname', DB::selectOne('SELECT current_schema() AS schema_name')->schema_name)
            ->where('tablename', 'category_example_embeddings')
            ->whereRaw("indexdef ~* '(hnsw|ivfflat)'")
            ->exists();

        expect($hasDimensionBoundApproximateIndex)->toBeFalse();
    });

    it('persiste Anexo sem identificador do provedor', function () {
        DB::table('gmail_accounts')->insert(['id' => 1]);

        $threadId = DB::table('threads')->insertGetId([
            'account_id' => 1,
            'gmail_thread_id' => 'thread-attachment-metadata',
        ]);
        $messageId = DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-attachment-metadata',
        ]);
        $messageVersionId = DB::table('message_versions')->insertGetId([
            'message_id' => $messageId,
            'version_number' => 1,
            'content_hash' => str_repeat('c', 64),
            'raw_body_text' => 'Texto sintético',
        ]);

        $column = DB::selectOne(<<<'SQL'
SELECT is_nullable
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'message_version_attachments'
  AND column_name = 'provider_attachment_id'
SQL);

        expect($column?->is_nullable)->toBe('YES');

        $attachmentId = DB::table('message_version_attachments')->insertGetId([
            'message_version_id' => $messageVersionId,
            'file_name' => 'documento-sintetico.pdf',
            'media_type' => 'application/pdf',
            'size_bytes' => 128,
        ]);

        $attachment = DB::table('message_version_attachments')->where('id', $attachmentId)->first();

        expect($attachment->provider_attachment_id)->toBeNull()
            ->and($attachment->file_name)->toBe('documento-sintetico.pdf')
            ->and($attachment->media_type)->toBe('application/pdf')
            ->and((int) $attachment->size_bytes)->toBe(128);
    });

    it('preserva o último resultado oficial quando uma nova mensagem torna a Thread pending', function () {
        DB::table('gmail_accounts')->insert(['id' => 1]);

        $threadId = DB::table('threads')->insertGetId([
            'account_id' => 1,
            'gmail_thread_id' => 'thread-synthetic-1',
        ]);

        DB::table('messages')->insert([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-synthetic-1',
        ]);

        $categoryId = DB::table('categories')->insertGetId(['slug' => 'finance']);
        $categoryVersionId = DB::table('category_versions')->insertGetId([
            'category_id' => $categoryId,
            'version_number' => 1,
            'name' => 'Financeiro',
            'slug' => 'finance',
        ]);

        DB::table('categories')
            ->where('id', $categoryId)
            ->update(['current_version_id' => $categoryVersionId]);

        $reviewId = DB::table('reviews')->insertGetId([
            'thread_id' => $threadId,
            'target_scope' => 'thread',
            'action' => 'correct',
            'idempotency_key' => 'review-synthetic-1',
        ]);

        DB::table('review_category_versions')->insert([
            'review_id' => $reviewId,
            'category_version_id' => $categoryVersionId,
        ]);

        $classificationId = DB::table('thread_classifications')->insertGetId([
            'thread_id' => $threadId,
            'review_id' => $reviewId,
            'result_state' => 'categorized',
            'confidence' => 0.91,
            'provider_name' => 'rules',
        ]);

        DB::table('thread_classification_categories')->insert([
            'thread_classification_id' => $classificationId,
            'category_version_id' => $categoryVersionId,
        ]);

        DB::table('threads')
            ->where('id', $threadId)
            ->update([
                'operational_state' => 'categorized',
                'latest_official_classification_id' => $classificationId,
            ]);

        DB::table('messages')->insert([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-synthetic-2',
        ]);

        DB::table('threads')
            ->where('id', $threadId)
            ->update(['operational_state' => 'pending']);

        $thread = DB::table('threads')->where('id', $threadId)->first();

        expect($thread->operational_state)->toBe('pending')
            ->and((int) $thread->latest_official_classification_id)->toBe($classificationId)
            ->and(DB::table('thread_classifications')->where('id', $classificationId)->exists())->toBeTrue();
    });

    it('registra a Revisão humana como única autora da decisão oficial', function () {
        DB::table('gmail_accounts')->insert(['id' => 1]);

        $threadId = DB::table('threads')->insertGetId([
            'account_id' => 1,
            'gmail_thread_id' => 'thread-human-authority',
        ]);

        $reviewId = DB::table('reviews')->insertGetId([
            'thread_id' => $threadId,
            'target_scope' => 'thread',
            'action' => 'manual',
            'idempotency_key' => 'review-human-authority',
        ]);

        $classificationId = DB::table('thread_classifications')->insertGetId([
            'thread_id' => $threadId,
            'review_id' => $reviewId,
            'result_state' => 'uncategorized',
            'confidence' => 0.9,
            'provider_name' => 'jev',
            'provider_model' => 'model-synthetic',
        ]);

        expect(DB::table('thread_classifications')
            ->where('id', $classificationId)
            ->value('decision_author'))->toBe('human_review');

        expect(fn () => DB::table('thread_classifications')->insert([
            'thread_id' => $threadId,
            'review_id' => $reviewId,
            'result_state' => 'uncategorized',
            'confidence' => 0.9,
            'provider_name' => 'jev',
            'decision_author' => 'jev',
        ]))->toThrow(QueryException::class);
    });

    it('impede a criação de uma segunda conta Gmail singleton', function () {
        expect(fn () => DB::table('gmail_accounts')->insert(['id' => 2]))
            ->toThrow(QueryException::class);
    });

    it('impede slugs de Categoria reservados ao Estado operacional', function () {
        expect(fn () => DB::table('categories')->insert(['slug' => 'pending']))
            ->toThrow(QueryException::class);
    });

    it('permite apagar corpo por retenção sem permitir reescrever uma Versão da mensagem', function () {
        DB::table('gmail_accounts')->insert(['id' => 1]);

        $threadId = DB::table('threads')->insertGetId([
            'account_id' => 1,
            'gmail_thread_id' => 'thread-synthetic-retention',
        ]);
        $messageId = DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-synthetic-retention',
        ]);
        $versionId = DB::table('message_versions')->insertGetId([
            'message_id' => $messageId,
            'version_number' => 1,
            'content_hash' => str_repeat('a', 64),
            'subject' => 'Assunto sintético',
            'raw_body_text' => 'Corpo sintético para teste',
        ]);

        DB::table('message_versions')->where('id', $versionId)->update(['raw_body_text' => null]);

        expect(DB::table('message_versions')->where('id', $versionId)->value('raw_body_text'))->toBeNull();
        expect(fn () => DB::table('message_versions')
            ->where('id', $versionId)
            ->update(['content_hash' => str_repeat('b', 64)]))
            ->toThrow(QueryException::class);
    });
});
