<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Single-tenant: estas invariantes são impostas por triggers e constraints PostgreSQL; as migrations não habilitam RLS.
beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    DB::table('gmail_accounts')->insert(['id' => 1]);

    $this->threadId = DB::table('threads')->insertGetId([
        'account_id' => 1,
        'gmail_thread_id' => 'thread-category-example-synthetic',
    ]);

    $categoryId = DB::table('categories')->insertGetId(['slug' => 'synthetic-finance']);
    $this->categoryVersionId = DB::table('category_versions')->insertGetId([
        'category_id' => $categoryId,
        'version_number' => 1,
        'name' => 'Financeiro sintético',
        'slug' => 'synthetic-finance',
        'examples_enabled' => true,
    ]);

    DB::table('categories')->where('id', $categoryId)->update([
        'current_version_id' => $this->categoryVersionId,
    ]);

    $this->reviewId = DB::table('reviews')->insertGetId([
        'thread_id' => $this->threadId,
        'target_scope' => 'thread',
        'action' => 'correct',
        'idempotency_key' => 'review-category-example-synthetic',
    ]);

    DB::table('review_category_versions')->insert([
        'review_id' => $this->reviewId,
        'category_version_id' => $this->categoryVersionId,
    ]);

    $this->categoryExampleId = DB::table('category_examples')->insertGetId([
        'category_version_id' => $this->categoryVersionId,
        'review_id' => $this->reviewId,
        'example_version' => 1,
        'examples_enabled' => true,
        'example_text' => 'Exemplo sintético para teste de purge.',
        'content_hash' => str_repeat('a', 64),
    ]);

    $this->preAnalysisId = DB::table('pre_analyses')->insertGetId([
        'account_id' => 1,
        'thread_id' => $this->threadId,
        'thread_snapshot_hash' => str_repeat('b', 64),
        'category_catalog_version' => 'catalog-synthetic-v1',
        'provider_model' => 'jev-synthetic-model',
        'question_version' => 'question-synthetic-v1',
        'schema_version' => 'schema-synthetic-v1',
        'privacy_policy_version' => 'privacy-synthetic-v1',
    ]);

    DB::table('pre_analysis_examples')->insert([
        'pre_analysis_id' => $this->preAnalysisId,
        'category_example_id' => $this->categoryExampleId,
        'category_example_version' => 1,
        'retrieval_channel' => 'semantic',
        'retrieval_rank' => 1,
        'retrieval_score' => 0.91,
    ]);

    DB::insert(
        <<<'SQL'
INSERT INTO category_example_embeddings
    (category_example_id, embedding_model, embedding_revision, embedding)
VALUES (?, ?, ?, '[0.1,0.2]'::vector)
SQL,
        [$this->categoryExampleId, 'synthetic-embedding-model', 'synthetic-revision-1'],
    );

    $this->embeddingId = (int) DB::table('category_example_embeddings')
        ->where('category_example_id', $this->categoryExampleId)
        ->value('id');
});

describe('migration PostgreSQL do ciclo de vida dos Exemplos', function () {
    it('permite criar Exemplo ativo e embedding local', function () {
        expect(DB::table('category_examples')->where('id', $this->categoryExampleId)->exists())->toBeTrue()
            ->and(DB::table('category_example_embeddings')->where('id', $this->embeddingId)->exists())->toBeTrue();
    });

    it('purga conteúdo e vetor mantendo tombstone e proveniência da Pré-análise', function () {
        $before = DB::table('category_examples')->where('id', $this->categoryExampleId)->first();

        DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['example_text' => null]);

        $after = DB::table('category_examples')->where('id', $this->categoryExampleId)->first();
        $preAnalysisExample = DB::table('pre_analysis_examples')
            ->where('pre_analysis_id', $this->preAnalysisId)
            ->where('category_example_id', $this->categoryExampleId)
            ->first();

        expect($after)->not->toBeNull()
            ->and($after->id)->toBe($before->id)
            ->and($after->review_id)->toBe($before->review_id)
            ->and($after->category_version_id)->toBe($before->category_version_id)
            ->and($after->example_version)->toBe($before->example_version)
            ->and($after->created_at)->toBe($before->created_at)
            ->and($after->example_text)->toBeNull()
            ->and($after->content_hash)->toBeNull()
            ->and($after->revoked_at)->not->toBeNull()
            ->and($after->purged_at)->not->toBeNull()
            ->and($after->revoked_at)->toBe($after->purged_at)
            ->and(DB::selectOne(
                "SELECT search_document = ''::tsvector AS is_empty FROM category_examples WHERE id = ?",
                [$this->categoryExampleId],
            )->is_empty)->toBeTrue()
            ->and(DB::table('category_example_embeddings')->where('category_example_id', $this->categoryExampleId)->count())->toBe(0)
            ->and($preAnalysisExample)->not->toBeNull()
            ->and($preAnalysisExample->category_example_version)->toBe(1)
            ->and($preAnalysisExample->retrieval_channel)->toBe('semantic');
    });

    it('torna o purge idempotente sem alterar os timestamps do tombstone', function () {
        DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['purged_at' => DB::raw('statement_timestamp()')]);

        $firstPurge = DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->first(['revoked_at', 'purged_at']);

        DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['purged_at' => DB::raw('COALESCE(purged_at, statement_timestamp())')]);

        $secondPurge = DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->first(['revoked_at', 'purged_at']);

        expect($secondPurge->revoked_at)->toBe($firstPurge->revoked_at)
            ->and($secondPurge->purged_at)->toBe($firstPurge->purged_at)
            ->and(DB::table('category_examples')->where('id', $this->categoryExampleId)->count())->toBe(1)
            ->and(DB::table('category_example_embeddings')->where('category_example_id', $this->categoryExampleId)->count())->toBe(0);
    });

    it('recusa reidratação, reversão de revoke ou purge, mutação de proveniência e DELETE comum', function () {
        DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['purged_at' => DB::raw('statement_timestamp()')]);

        expect(fn () => DB::transaction(fn () => DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['example_text' => 'conteúdo reidratado'])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('category_examples')
                ->where('id', $this->categoryExampleId)
                ->update(['content_hash' => str_repeat('c', 64)])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('category_examples')
                ->where('id', $this->categoryExampleId)
                ->update(['revoked_at' => null])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('category_examples')
                ->where('id', $this->categoryExampleId)
                ->update(['purged_at' => null])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('category_examples')
                ->where('id', $this->categoryExampleId)
                ->update(['created_at' => DB::raw("created_at + interval '1 second'")])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('category_examples')
                ->where('id', $this->categoryExampleId)
                ->delete()))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('pre_analysis_examples')
                ->where('pre_analysis_id', $this->preAnalysisId)
                ->where('category_example_id', $this->categoryExampleId)
                ->update(['retrieval_rank' => 2])))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('pre_analysis_examples')
                ->where('pre_analysis_id', $this->preAnalysisId)
                ->where('category_example_id', $this->categoryExampleId)
                ->delete()))->toThrow(QueryException::class);
    });

    it('recusa apagar embedding antes do purge e atualizar ou reinserir vetor para Exemplo revogado', function () {
        expect(fn () => DB::transaction(fn () => DB::table('category_example_embeddings')
            ->where('id', $this->embeddingId)
            ->delete()))->toThrow(QueryException::class);

        DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['revoked_at' => DB::raw('statement_timestamp()')]);

        expect(fn () => DB::transaction(fn () => DB::update(
            "UPDATE category_example_embeddings SET embedding = '[0.3,0.4]'::vector WHERE id = ?",
            [$this->embeddingId],
        )))->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::insert(
                <<<'SQL'
INSERT INTO category_example_embeddings
    (category_example_id, embedding_model, embedding_revision, embedding)
VALUES (?, ?, ?, '[0.3,0.4]'::vector)
SQL,
                [$this->categoryExampleId, 'synthetic-embedding-model-2', 'synthetic-revision-2'],
            )))->toThrow(QueryException::class)
            ->and(DB::table('category_example_embeddings')->where('id', $this->embeddingId)->exists())->toBeTrue();
    });

    it('recusa reinserir embedding depois do purge', function () {
        DB::table('category_examples')
            ->where('id', $this->categoryExampleId)
            ->update(['purged_at' => DB::raw('statement_timestamp()')]);

        expect(fn () => DB::transaction(fn () => DB::insert(
            <<<'SQL'
INSERT INTO category_example_embeddings
    (category_example_id, embedding_model, embedding_revision, embedding)
VALUES (?, ?, ?, '[0.5,0.6]'::vector)
SQL,
            [$this->categoryExampleId, 'synthetic-embedding-model-3', 'synthetic-revision-3'],
        )))->toThrow(QueryException::class)
            ->and(DB::table('category_example_embeddings')->where('category_example_id', $this->categoryExampleId)->count())->toBe(0);
    });
});
