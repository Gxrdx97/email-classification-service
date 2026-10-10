<?php

declare(strict_types=1);

use App\Domain\PreAnalysis\Data\PreAnalysisContext;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\Privacy\Data\PrivacyPolicy;
use App\Domain\Privacy\Enums\PrivacyAction;
use App\Domain\Privacy\Enums\PrivacyConsentDecision;
use App\Domain\Privacy\Persistence\PostgresThreadPrivacyConsentRepository;
use App\Infrastructure\PreAnalysis\PostgresPreAnalysisContextSource;
use App\Infrastructure\PreAnalysis\PostgresPreAnalysisOperationalFailureRecorder;
use App\Infrastructure\PreAnalysis\PreAnalysisDispatchIntentWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('gmail_accounts')->insert(['id' => 1]);
});

describe('adapters de persistência da Pré-análise', function () {
    it('carrega somente a Thread enriquecida, Categorias ativas e Exemplos opt-in ainda utilizáveis', function () {
        $threadId = seedPreAnalysisAdapterThread('context-source');
        DB::table('sync_state')->insert([
            'account_id' => 1,
            'history_id' => '19001',
            'cursor_generation' => 1,
        ]);

        $categoryId = DB::table('categories')->insertGetId(['slug' => 'active-opt-in']);
        $oldVersionId = DB::table('category_versions')->insertGetId([
            'category_id' => $categoryId,
            'version_number' => 1,
            'name' => 'Versão anterior',
            'slug' => 'active-opt-in',
            'examples_enabled' => true,
        ]);
        $activeVersionId = DB::table('category_versions')->insertGetId([
            'category_id' => $categoryId,
            'version_number' => 2,
            'name' => 'Categoria ativa',
            'slug' => 'active-opt-in',
            'description' => 'Descrição sintética',
            'classification_instructions' => 'Instrução sintética',
            'examples_enabled' => true,
        ]);
        DB::table('categories')->where('id', $categoryId)->update(['current_version_id' => $activeVersionId]);

        $disabledCategoryId = DB::table('categories')->insertGetId(['slug' => 'active-no-opt-in']);
        $disabledVersionId = DB::table('category_versions')->insertGetId([
            'category_id' => $disabledCategoryId,
            'version_number' => 1,
            'name' => 'Categoria sem opt-in',
            'slug' => 'active-no-opt-in',
            'examples_enabled' => false,
        ]);
        DB::table('categories')->where('id', $disabledCategoryId)->update(['current_version_id' => $disabledVersionId]);

        $archivedCategoryId = DB::table('categories')->insertGetId(['slug' => 'archived-category']);
        $archivedVersionId = DB::table('category_versions')->insertGetId([
            'category_id' => $archivedCategoryId,
            'version_number' => 1,
            'name' => 'Categoria arquivada',
            'slug' => 'archived-category',
            'examples_enabled' => true,
        ]);
        DB::table('categories')->where('id', $archivedCategoryId)->update([
            'current_version_id' => $archivedVersionId,
            'archived_at' => now(),
        ]);

        $eligibleExampleId = seedPreAnalysisAdapterExample($threadId, $activeVersionId, 'eligible');
        seedPreAnalysisAdapterExample($threadId, $oldVersionId, 'old-version');
        $revokedExampleId = seedPreAnalysisAdapterExample($threadId, $activeVersionId, 'revoked');
        DB::table('category_examples')->where('id', $revokedExampleId)->update(['revoked_at' => DB::raw('statement_timestamp()')]);
        $purgedExampleId = seedPreAnalysisAdapterExample($threadId, $activeVersionId, 'purged');
        DB::table('category_examples')->where('id', $purgedExampleId)->update(['purged_at' => DB::raw('statement_timestamp()')]);
        seedPreAnalysisAdapterExample($threadId, $archivedVersionId, 'archived');

        $messageId = DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-context-source',
        ]);
        $messageVersionId = DB::table('message_versions')->insertGetId([
            'message_id' => $messageId,
            'version_number' => 1,
            'content_hash' => str_repeat('d', 64),
            'subject' => 'Synthetic subject',
            'raw_body_text' => 'RAW_BODY_PRIVATE_SENTINEL',
            'raw_body_html' => '<p>RAW_HTML_PRIVATE_SENTINEL</p>',
            'provider_internal_date' => '2026-10-05T12:30:00+00:00',
        ]);
        $enrichmentId = DB::table('message_enrichments')->insertGetId([
            'message_version_id' => $messageVersionId,
            'extractor_name' => 'gmail-deterministic',
            'extractor_version' => '1',
            'normalized_text' => 'Normalized synthetic permitted body',
            'direction' => 'inbound',
            'thread_position' => 1,
        ]);
        DB::table('enrichment_participants')->insert([
            'enrichment_id' => $enrichmentId,
            'normalized_email' => 'sender@example.test',
            'display_name' => 'Synthetic Sender',
            'role' => 'from',
        ]);
        DB::table('message_version_attachments')->insert([
            'message_version_id' => $messageVersionId,
            'provider_attachment_id' => 'synthetic-attachment-reference',
            'file_name' => 'synthetic-private.pdf',
            'media_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);

        [$identity, $lease] = seedPreAnalysisAdapterLease($threadId);
        $source = new PostgresPreAnalysisContextSource(
            new PrivacyPolicy('policy-v1', PrivacyAction::Abort),
            'question-v1',
            'schema-v1',
            'Choose only an active Category version.',
        );

        $context = $source->load($lease);

        expect($context)->toBeInstanceOf(PreAnalysisContext::class);
        if (! $context instanceof PreAnalysisContext) {
            return;
        }

        expect($context->identity->threadId)->toBe($identity->threadId)
            ->and($context->cursorCommitted)->toBeTrue()
            ->and($context->hasOfficialResult)->toBeFalse()
            ->and(array_map(static fn ($category): int => $category->id, $context->categoryVersions))
            ->toBe([$activeVersionId, $disabledVersionId])
            ->and(array_map(static fn ($example): int => $example->id, $context->examples))
            ->toBe([$eligibleExampleId]);

        $payload = $context->providerPayload();
        $providerMessage = $payload['thread']['messages'][0];

        expect(array_keys($providerMessage))->toBe(['from', 'subject', 'sent_at', 'body'])
            ->and($providerMessage['from'])->toBe('Synthetic Sender <sender@example.test>')
            ->and($providerMessage['subject'])->toBe('Synthetic subject')
            ->and($providerMessage['body'])->toBe('Normalized synthetic permitted body')
            ->and($payload['examples'][0]['text'])->toBe('Eligible example text')
            ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain(
                'RAW_BODY_PRIVATE_SENTINEL',
                'RAW_HTML_PRIVATE_SENTINEL',
                'synthetic-private.pdf',
                'synthetic-attachment-reference',
                'Revoked example text',
                'Purged example text',
                'Old-version example text',
                'Archived example text',
            );
    });

    it('falha fechada quando a versão da política, pergunta ou schema não corresponde à intenção', function () {
        $threadId = seedPreAnalysisAdapterThread('context-version-mismatch');
        DB::table('sync_state')->insert(['account_id' => 1, 'history_id' => '19002', 'cursor_generation' => 1]);
        [, $lease] = seedPreAnalysisAdapterLease($threadId);

        $wrongPolicy = new PostgresPreAnalysisContextSource(
            new PrivacyPolicy('policy-v2', PrivacyAction::Abort),
            'question-v1',
            'schema-v1',
            'Question.',
        );
        $wrongQuestion = new PostgresPreAnalysisContextSource(
            new PrivacyPolicy('policy-v1', PrivacyAction::Abort),
            'question-v2',
            'schema-v1',
            'Question.',
        );
        $wrongSchema = new PostgresPreAnalysisContextSource(
            new PrivacyPolicy('policy-v1', PrivacyAction::Abort),
            'question-v1',
            'schema-v2',
            'Question.',
        );

        expect($wrongPolicy->load($lease))->toBeNull()
            ->and($wrongQuestion->load($lease))->toBeNull()
            ->and($wrongSchema->load($lease))->toBeNull();
    });

    it('retorna contexto sem conteúdo quando o Cursor ainda não foi confirmado', function () {
        $threadId = seedPreAnalysisAdapterThread('cursor-not-committed');
        [$identity, $lease] = seedPreAnalysisAdapterLease($threadId);
        $source = new PostgresPreAnalysisContextSource(
            new PrivacyPolicy('policy-v1', PrivacyAction::Abort),
            'question-v1',
            'schema-v1',
            'Question.',
        );

        $context = $source->load($lease);

        expect($context)->toBeInstanceOf(PreAnalysisContext::class);
        if (! $context instanceof PreAnalysisContext) {
            return;
        }

        expect($context->identity->threadId)->toBe($identity->threadId)
            ->and($context->cursorCommitted)->toBeFalse()
            ->and($context->providerPayload())->toBe([]);
    });

    it('grava uma única falha operacional sem alterar classificação oficial nem Labels herdados', function () {
        $threadId = seedPreAnalysisAdapterThread('operational-failure');
        $categoryId = DB::table('categories')->insertGetId(['slug' => 'failure-category']);
        $categoryVersionId = DB::table('category_versions')->insertGetId([
            'category_id' => $categoryId,
            'version_number' => 1,
            'name' => 'Categoria da decisão',
            'slug' => 'failure-category',
        ]);
        DB::table('categories')->where('id', $categoryId)->update(['current_version_id' => $categoryVersionId]);
        $reviewId = DB::table('reviews')->insertGetId([
            'thread_id' => $threadId,
            'target_scope' => 'thread',
            'action' => 'manual',
            'idempotency_key' => 'review-operational-failure',
        ]);
        $classificationId = DB::table('thread_classifications')->insertGetId([
            'thread_id' => $threadId,
            'review_id' => $reviewId,
            'review_scope' => 'thread',
            'result_state' => 'categorized',
            'confidence' => 0.91,
            'provider_name' => 'human_review',
            'category_catalog_version' => 'catalog-v1',
            'evidence_refs' => '[]',
        ]);
        $messageId = DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-operational-failure',
        ]);
        DB::table('threads')->where('id', $threadId)->update([
            'operational_state' => 'categorized',
            'latest_official_classification_id' => $classificationId,
        ]);
        DB::table('message_category_label_assignments')->insert([
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'category_id' => $categoryId,
            'source_scope' => 'thread',
            'source_classification_id' => $classificationId,
        ]);

        (new PostgresPreAnalysisOperationalFailureRecorder)
            ->markFailedWithoutReplacingOfficialResult($threadId, 'provider_failure');

        $thread = DB::table('threads')->where('id', $threadId)->first();
        $assignment = DB::table('message_category_label_assignments')->where('message_id', $messageId)->first();

        expect($thread->operational_state)->toBe('failed')
            ->and((int) $thread->latest_official_classification_id)->toBe($classificationId)
            ->and((int) $assignment->category_id)->toBe($categoryId)
            ->and((int) $assignment->source_classification_id)->toBe($classificationId)
            ->and(DB::table('thread_classifications')->where('id', $classificationId)->exists())->toBeTrue();
    });

    it('insere Pré-análise e outbox idempotentemente na transação ambiente', function () {
        $threadId = seedPreAnalysisAdapterThread('dispatch-intent');
        $identity = new PreAnalysisIdentity(
            1,
            $threadId,
            hash('sha256', 'snapshot-dispatch-intent'),
            'catalog-v1',
            'jev/synthetic-model',
            'policy-v1',
        );
        $writer = new PreAnalysisDispatchIntentWriter;
        $transactionLevel = DB::connection()->transactionLevel();

        $firstId = $writer->write($identity, 'question-v1', 'schema-v1');
        $secondId = $writer->write($identity, 'question-v1', 'schema-v1');

        $outbox = DB::table('pre_analysis_dispatch_outbox')->where('pre_analysis_id', $firstId)->first();

        expect($secondId)->toBe($firstId)
            ->and(DB::connection()->transactionLevel())->toBe($transactionLevel)
            ->and(DB::table('pre_analyses')->where('thread_id', $threadId)->count())->toBe(1)
            ->and(DB::table('pre_analysis_dispatch_outbox')->where('pre_analysis_id', $firstId)->count())->toBe(1)
            ->and($outbox->idempotency_key)->toBe('pre-analysis:'.$firstId)
            ->and(DB::table('pre_analyses')->where('id', $firstId)->value('status'))->toBe('queued')
            ->and(DB::table('threads')->where('id', $threadId)->value('operational_state'))->toBe('pending');
    });

    it('reverte Pré-análise e outbox junto com o rollback controlado pelo CaptureStore', function () {
        $threadId = seedPreAnalysisAdapterThread('dispatch-rollback');
        $identity = new PreAnalysisIdentity(
            1,
            $threadId,
            hash('sha256', 'snapshot-dispatch-rollback'),
            'catalog-v1',
            'jev/synthetic-model',
            'policy-v1',
        );
        $writer = new PreAnalysisDispatchIntentWriter;

        expect(static fn () => DB::transaction(function () use ($writer, $identity): void {
            $writer->write($identity, 'question-v1', 'schema-v1');

            throw new RuntimeException('synthetic transaction rollback');
        }))->toThrow(RuntimeException::class, 'synthetic transaction rollback');

        expect(DB::table('pre_analyses')->where('thread_id', $threadId)->exists())->toBeFalse()
            ->and(DB::table('pre_analysis_dispatch_outbox')->exists())->toBeFalse();
    });

    it('rejeita reuso da identidade com versões de pergunta ou schema diferentes', function () {
        $threadId = seedPreAnalysisAdapterThread('dispatch-version-conflict');
        $identity = new PreAnalysisIdentity(
            1,
            $threadId,
            hash('sha256', 'snapshot-dispatch-version-conflict'),
            'catalog-v1',
            'jev/synthetic-model',
            'policy-v1',
        );
        $writer = new PreAnalysisDispatchIntentWriter;
        $preAnalysisId = $writer->write($identity, 'question-v1', 'schema-v1');

        expect(fn () => $writer->write($identity, 'question-v2', 'schema-v1'))
            ->toThrow(LogicException::class)
            ->and(DB::table('pre_analyses')->where('thread_id', $threadId)->count())->toBe(1)
            ->and(DB::table('pre_analysis_dispatch_outbox')->where('pre_analysis_id', $preAnalysisId)->count())->toBe(1);
    });

    it('lê consentimento somente para a versão exata da política solicitada', function () {
        $threadId = seedPreAnalysisAdapterThread('consent-policy-version');
        DB::table('thread_privacy_consents')->insert([
            'thread_id' => $threadId,
            'decision' => 'deny',
            'privacy_policy_version' => 'policy-v1',
            'idempotency_key' => 'consent-policy-v1',
            'decided_at' => '2026-10-05T12:00:00+00:00',
        ]);
        DB::table('thread_privacy_consents')->insert([
            'thread_id' => $threadId,
            'decision' => 'deny',
            'privacy_policy_version' => 'policy-v2',
            'idempotency_key' => 'consent-policy-v2',
            'decided_at' => '2026-10-05T12:01:00+00:00',
        ]);
        DB::table('thread_privacy_consents')->insert([
            'thread_id' => $threadId,
            'decision' => 'allow',
            'privacy_policy_version' => 'policy-v2',
            'idempotency_key' => 'consent-policy-v2-latest',
            'decided_at' => '2026-10-05T12:02:00+00:00',
        ]);

        $consents = new PostgresThreadPrivacyConsentRepository;

        expect($consents->decisionFor($threadId, 'policy-v1'))->toBe(PrivacyConsentDecision::Deny)
            ->and($consents->decisionFor($threadId, 'policy-v2'))->toBe(PrivacyConsentDecision::Allow)
            ->and($consents->decisionFor($threadId, 'policy-v3'))->toBeNull();
    });
});

function seedPreAnalysisAdapterThread(string $suffix): int
{
    return (int) DB::table('threads')->insertGetId([
        'account_id' => 1,
        'gmail_thread_id' => 'gmail-thread-'.$suffix,
        'thread_snapshot_hash' => hash('sha256', 'snapshot-'.$suffix),
    ]);
}

/** @return array{PreAnalysisIdentity, PreAnalysisLease} */
function seedPreAnalysisAdapterLease(int $threadId): array
{
    $snapshotHash = (string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash');
    $identity = new PreAnalysisIdentity(1, $threadId, $snapshotHash, 'catalog-v1', 'jev/synthetic-model', 'policy-v1');
    $startedAt = new DateTimeImmutable('2026-10-05T12:00:00+00:00');
    $preAnalysisId = (int) DB::table('pre_analyses')->insertGetId([
        'account_id' => 1,
        'thread_id' => $threadId,
        'thread_snapshot_hash' => $snapshotHash,
        'category_catalog_version' => $identity->categoryCatalogVersion,
        'provider' => 'jev',
        'provider_model' => $identity->providerModel,
        'question_version' => 'question-v1',
        'schema_version' => 'schema-v1',
        'privacy_policy_version' => $identity->privacyPolicyVersion,
        'status' => 'running',
        'started_at' => $startedAt,
    ]);
    DB::table('pre_analysis_dispatch_outbox')->insert(['pre_analysis_id' => $preAnalysisId]);

    return [$identity, new PreAnalysisLease($preAnalysisId, $identity, $startedAt, 1)];
}

function seedPreAnalysisAdapterExample(int $threadId, int $categoryVersionId, string $suffix): int
{
    $reviewId = DB::table('reviews')->insertGetId([
        'thread_id' => $threadId,
        'target_scope' => 'thread',
        'action' => 'correct',
        'idempotency_key' => 'review-example-'.$suffix,
    ]);
    DB::table('review_category_versions')->insert([
        'review_id' => $reviewId,
        'category_version_id' => $categoryVersionId,
    ]);

    return (int) DB::table('category_examples')->insertGetId([
        'category_version_id' => $categoryVersionId,
        'review_id' => $reviewId,
        'example_version' => 1,
        'examples_enabled' => true,
        'example_text' => ucfirst($suffix).' example text',
        'content_hash' => hash('sha256', 'example-'.$suffix),
    ]);
}
