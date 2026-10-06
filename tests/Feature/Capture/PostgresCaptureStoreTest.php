<?php

declare(strict_types=1);

use App\Domain\Capture\Data\AttachmentMetadata;
use App\Domain\Capture\Data\CanonicalMessage;
use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\CapturedMessage;
use App\Domain\Capture\Data\EnrichedMessage;
use App\Domain\Capture\Data\GmailScopes;
use App\Domain\Capture\Data\OAuthConnection;
use App\Domain\Capture\Data\Participant;
use App\Domain\Capture\Data\SyncMode;
use App\Domain\Capture\Exceptions\CaptureFailure;
use App\Infrastructure\Capture\PostgresCaptureStore;
use App\Infrastructure\Capture\PostgresOAuthConnectionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function syntheticCapturedMessage(
    string $messageId = 'synthetic-message-1',
    string $threadId = 'synthetic-thread-1',
    string $body = 'Synthetic body',
    array $labels = ['INBOX'],
    ?array $participants = null,
    array $attachments = [],
    ?string $normalizedText = null,
): CapturedMessage {
    $canonical = new CanonicalMessage(
        providerMessageId: $messageId,
        providerThreadId: $threadId,
        subject: 'Synthetic subject',
        rawBodyText: $body,
        rawBodyHtml: null,
        providerInternalDate: new DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        headers: [],
        labelIds: $labels,
        attachments: $attachments,
    );

    return new CapturedMessage(
        canonical: $canonical,
        enrichment: new EnrichedMessage(
            providerMessageId: $messageId,
            contentHash: $canonical->contentHash,
            normalizedText: $normalizedText ?? $body,
            participants: $participants ?? [
                new Participant('sender@synthetic.test', 'Synthetic Sender', 'from'),
                new Participant('owner@synthetic.test', null, 'to'),
            ],
            direction: 'inbound',
            languageCode: 'en',
            threadPosition: 1,
            attachments: $attachments,
            deterministicFacts: ['fixture' => 'synthetic'],
            extractorName: 'synthetic-extractor',
            extractorVersion: '1',
        ),
    );
}

function seedSyntheticGmailAccount(?string $cursor = null): void
{
    DB::table('gmail_accounts')->insert(['id' => 1]);

    if ($cursor !== null) {
        DB::table('sync_state')->insert([
            'account_id' => 1,
            'history_id' => $cursor,
        ]);
    }
}

function seedSyntheticOfficialThread(string $gmailThreadId): int
{
    $now = now();
    $threadId = (int) DB::table('threads')->insertGetId([
        'account_id' => 1,
        'gmail_thread_id' => $gmailThreadId,
        'operational_state' => 'categorized',
        'created_at' => $now,
        'updated_at' => $now,
    ], 'id');
    $reviewId = (int) DB::table('reviews')->insertGetId([
        'thread_id' => $threadId,
        'target_scope' => 'thread',
        'target_message_id' => null,
        'action' => 'manual',
        'reviewer_subject' => 'synthetic-reviewer',
        'idempotency_key' => 'synthetic-review-key',
        'reviewed_at' => $now,
        'created_at' => $now,
    ], 'id');
    $classificationId = (int) DB::table('thread_classifications')->insertGetId([
        'thread_id' => $threadId,
        'review_id' => $reviewId,
        'review_scope' => 'thread',
        'result_state' => 'categorized',
        'confidence' => 1,
        'provider_name' => 'human_review',
        'evidence_refs' => '[]',
        'classified_at' => $now,
        'created_at' => $now,
    ], 'id');

    DB::table('threads')->where('id', $threadId)->update([
        'latest_official_classification_id' => $classificationId,
    ]);

    return $classificationId;
}

function enableCapturePreAnalysisDispatch(): void
{
    config([
        'preanalysis.provider' => 'jev',
        'preanalysis.enabled' => true,
        'preanalysis.model' => 'jev/synthetic-model',
        'preanalysis.question_version' => 'question-v1',
        'preanalysis.schema_version' => 'schema-v1',
        'preanalysis.privacy_policy_version' => 'policy-v1',
    ]);
}

function seedActiveCaptureCategory(): int
{
    $categoryId = (int) DB::table('categories')->insertGetId(['slug' => 'capture-category']);
    $versionId = (int) DB::table('category_versions')->insertGetId([
        'category_id' => $categoryId,
        'version_number' => 1,
        'name' => 'Capture Category',
        'slug' => 'capture-category',
        'description' => 'Synthetic active category',
        'classification_instructions' => 'Synthetic instructions',
        'examples_enabled' => false,
    ]);
    DB::table('categories')->where('id', $categoryId)->update(['current_version_id' => $versionId]);

    return $categoryId;
}

describe('PostgresCaptureStore', function () {
    it('persists an intermediate page without advancing its Cursor', function () {
        seedSyntheticGmailAccount('10');
        $receipt = (new PostgresCaptureStore)->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '10',
            cursorAfter: null,
            messages: [syntheticCapturedMessage()],
        ));

        expect($receipt->committedCursor)->toBe('10')
            ->and($receipt->newMessages)->toBe(1)
            ->and(DB::table('messages')->count())->toBe(1)
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('10');
    });

    it('rejects a stale Cursor without persisting any part of the page', function () {
        seedSyntheticGmailAccount('10');
        $batch = new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '9',
            cursorAfter: '11',
            messages: [syntheticCapturedMessage()],
        );

        expect(fn () => (new PostgresCaptureStore)->commitBatch($batch))
            ->toThrow(CaptureFailure::class);

        expect(DB::table('threads')->count())->toBe(0)
            ->and(DB::table('messages')->count())->toBe(0)
            ->and(DB::table('message_versions')->count())->toBe(0)
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('10');
    });

    it('deduplicates Messages and Versões, updates Labels, and preserves the official Thread pointer', function () {
        seedSyntheticGmailAccount();
        $classificationId = seedSyntheticOfficialThread('synthetic-thread-1');
        $attachments = [
            new AttachmentMetadata('attachment-a.txt', 'text/plain', 24),
            new AttachmentMetadata('attachment-b.pdf', 'application/pdf', 1024),
        ];
        $initial = syntheticCapturedMessage(
            labels: ['INBOX', 'CATEGORY_PERSONAL', 'custom-synthetic-label'],
            attachments: $attachments,
        );
        $expectedAttachmentRows = [
            [
                'provider_attachment_id' => null,
                'file_name' => 'attachment-a.txt',
                'media_type' => 'text/plain',
                'size_bytes' => 24,
            ],
            [
                'provider_attachment_id' => null,
                'file_name' => 'attachment-b.pdf',
                'media_type' => 'application/pdf',
                'size_bytes' => 1024,
            ],
        ];
        $readAttachmentRows = static fn (): array => DB::table('message_version_attachments')
            ->get(['provider_attachment_id', 'file_name', 'media_type', 'size_bytes'])
            ->map(static fn (object $attachment): array => [
                'provider_attachment_id' => $attachment->provider_attachment_id,
                'file_name' => $attachment->file_name,
                'media_type' => $attachment->media_type,
                'size_bytes' => (int) $attachment->size_bytes,
            ])
            ->sortBy('file_name')
            ->values()
            ->all();

        $first = (new PostgresCaptureStore)->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Initial,
            expectedCursor: null,
            cursorAfter: '100',
            messages: [$initial],
        ));

        expect($first->capturedMessages)->toBe(1)
            ->and($first->newMessages)->toBe(1)
            ->and(DB::table('messages')->count())->toBe(1)
            ->and(DB::table('message_versions')->count())->toBe(1)
            ->and(DB::table('message_enrichments')->count())->toBe(1)
            ->and(DB::table('enrichment_participants')->count())->toBe(2)
            ->and($readAttachmentRows())->toBe($expectedAttachmentRows)
            ->and(DB::table('message_captured_labels')->where('gmail_label_id', 'INBOX')->value('label_type'))->toBe('system')
            ->and(DB::table('message_captured_labels')->where('gmail_label_id', 'custom-synthetic-label')->value('label_type'))->toBe('user')
            ->and(DB::table('message_enrichments')->value('normalized_text'))->toBe('Synthetic body')
            ->and(DB::table('enrichment_participants')->where('normalized_email', 'sender@synthetic.test')->value('role'))->toBe('from')
            ->and(DB::table('threads')->value('operational_state'))->toBe('pending')
            ->and((int) DB::table('threads')->value('latest_official_classification_id'))->toBe($classificationId)
            ->and(DB::table('sync_state')->value('history_id'))->toBe('100');

        $labelsOnly = syntheticCapturedMessage(labels: ['SENT'], attachments: $attachments);
        $second = (new PostgresCaptureStore)->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '100',
            cursorAfter: '100',
            messages: [$labelsOnly],
        ));

        expect($second->newMessages)->toBe(0)
            ->and(DB::table('messages')->count())->toBe(1)
            ->and(DB::table('message_versions')->count())->toBe(1)
            ->and($readAttachmentRows())->toBe($expectedAttachmentRows)
            ->and(DB::table('message_captured_labels')->orderBy('gmail_label_id')->pluck('gmail_label_id')->all())->toBe(['SENT']);

        $newContent = syntheticCapturedMessage(body: 'Synthetic revised body', labels: ['SENT']);
        $third = (new PostgresCaptureStore)->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '100',
            cursorAfter: '101',
            messages: [$newContent],
        ));

        expect($third->newMessages)->toBe(0)
            ->and(DB::table('messages')->count())->toBe(1)
            ->and(DB::table('message_versions')->where('message_id', DB::table('messages')->value('id'))->count())->toBe(2);

        $sameContentDifferentMessage = syntheticCapturedMessage(
            messageId: 'synthetic-message-2',
            body: 'Synthetic body',
            labels: ['INBOX'],
            attachments: [$attachments[0]],
        );
        $fourth = (new PostgresCaptureStore)->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '101',
            cursorAfter: '102',
            messages: [$sameContentDifferentMessage],
        ));

        expect($fourth->newMessages)->toBe(1)
            ->and(DB::table('messages')->count())->toBe(2)
            ->and(DB::table('message_versions')->count())->toBe(3)
            ->and((int) DB::table('threads')->value('latest_official_classification_id'))->toBe($classificationId);
    });

    it('cria intenções somente no batch final para todas as Threads das páginas e é idempotente', function () {
        enableCapturePreAnalysisDispatch();
        seedSyntheticGmailAccount('10');
        seedActiveCaptureCategory();
        $store = new PostgresCaptureStore;

        // O Cursor, e não apenas a presença de IDs alterados, controla a criação da intenção.
        $store->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '10',
            cursorAfter: null,
            messages: [syntheticCapturedMessage('message-page-one', 'thread-page-one')],
            touchedThreadIds: ['thread-page-one'],
        ));

        expect(DB::table('pre_analyses')->count())->toBe(0)
            ->and(DB::table('pre_analysis_dispatch_outbox')->count())->toBe(0)
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('10');

        $finalBatch = new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '10',
            cursorAfter: '11',
            messages: [syntheticCapturedMessage('message-page-two', 'thread-page-two')],
            touchedThreadIds: ['thread-page-one', 'thread-page-two'],
        );
        $store->commitBatch($finalBatch);

        $preAnalysisRows = DB::table('pre_analyses')->orderBy('thread_id')->get();
        expect($preAnalysisRows)->toHaveCount(2)
            ->and(DB::table('pre_analysis_dispatch_outbox')->count())->toBe(2)
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('11')
            ->and($preAnalysisRows[0]->category_catalog_version)->toBe($preAnalysisRows[1]->category_catalog_version)
            ->and(DB::table('threads')->whereNull('thread_snapshot_hash')->count())->toBe(0);

        $store->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '11',
            cursorAfter: '11',
            messages: [syntheticCapturedMessage('message-page-two', 'thread-page-two')],
            touchedThreadIds: ['thread-page-one', 'thread-page-two'],
        ));

        expect(DB::table('pre_analyses')->count())->toBe(2)
            ->and(DB::table('pre_analysis_dispatch_outbox')->count())->toBe(2);
    });

    it('cria Pré-análise somente para Thread pending sem resultado oficial', function () {
        enableCapturePreAnalysisDispatch();
        seedSyntheticGmailAccount('20');
        seedActiveCaptureCategory();
        $store = new PostgresCaptureStore;

        $store->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '20',
            cursorAfter: null,
            messages: [
                syntheticCapturedMessage('eligible-message', 'eligible-thread'),
                syntheticCapturedMessage('nonpending-message', 'nonpending-thread'),
            ],
        ));
        DB::table('threads')->where('gmail_thread_id', 'nonpending-thread')->update([
            'operational_state' => 'categorized',
        ]);
        $officialClassificationId = seedSyntheticOfficialThread('official-thread');

        $store->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '20',
            cursorAfter: '21',
            messages: [syntheticCapturedMessage('official-message', 'official-thread')],
            touchedThreadIds: ['eligible-thread', 'nonpending-thread', 'official-thread'],
        ));

        $eligibleThreadId = (int) DB::table('threads')->where('gmail_thread_id', 'eligible-thread')->value('id');
        $officialThread = DB::table('threads')->where('gmail_thread_id', 'official-thread')->first();

        expect(DB::table('pre_analyses')->pluck('thread_id')->map(static fn (mixed $id): int => (int) $id)->all())
            ->toBe([$eligibleThreadId])
            ->and($officialThread->operational_state)->toBe('pending')
            ->and((int) $officialThread->latest_official_classification_id)->toBe($officialClassificationId);
    });

    it('mantém o snapshot estável em replay e o altera quando conteúdo ou enriquecimento mudam', function () {
        config([
            'preanalysis.provider' => 'none',
            'preanalysis.enabled' => false,
            'preanalysis.model' => null,
        ]);
        seedSyntheticGmailAccount('30');
        $store = new PostgresCaptureStore;

        $firstBatch = static fn (string $expectedCursor, string $cursorAfter, array $messages): CaptureBatch => new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: $expectedCursor,
            cursorAfter: $cursorAfter,
            messages: $messages,
        );
        $firstMessages = [
            syntheticCapturedMessage('snapshot-message-one', 'snapshot-thread', body: 'First body'),
            syntheticCapturedMessage('snapshot-message-two', 'snapshot-thread', body: 'Second body'),
        ];

        $store->commitBatch($firstBatch('30', '31', $firstMessages));
        $threadId = (int) DB::table('threads')->where('gmail_thread_id', 'snapshot-thread')->value('id');
        $firstHash = rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash'));

        $store->commitBatch($firstBatch('31', '32', [
            syntheticCapturedMessage('snapshot-message-two', 'snapshot-thread', body: 'Second body', labels: ['SENT']),
            syntheticCapturedMessage('snapshot-message-one', 'snapshot-thread', body: 'First body', labels: ['UNREAD']),
        ]));
        $replayedHash = rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash'));

        $store->commitBatch($firstBatch('32', '33', [
            syntheticCapturedMessage(
                'snapshot-message-one',
                'snapshot-thread',
                body: 'First body',
                normalizedText: 'First body with changed deterministic enrichment',
            ),
        ]));
        $enrichmentHash = rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash'));

        $store->commitBatch($firstBatch('33', '34', [
            syntheticCapturedMessage('snapshot-message-one', 'snapshot-thread', body: 'Revised first body'),
        ]));
        $contentHash = rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash'));

        expect($replayedHash)->toBe($firstHash)
            ->and($enrichmentHash)->not->toBe($replayedHash)
            ->and($contentHash)->not->toBe($enrichmentHash)
            ->and(DB::table('message_versions')->count())->toBe(3)
            ->and(DB::table('pre_analyses')->count())->toBe(0);
    });

    it('reverte Cursor, snapshot, Pré-análise e outbox no rollback da transação ambiente', function () {
        enableCapturePreAnalysisDispatch();
        seedSyntheticGmailAccount('40');
        seedActiveCaptureCategory();
        $store = new PostgresCaptureStore;
        $store->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '40',
            cursorAfter: null,
            messages: [syntheticCapturedMessage('rollback-message', 'rollback-thread', body: 'Before rollback')],
        ));
        $threadId = (int) DB::table('threads')->where('gmail_thread_id', 'rollback-thread')->value('id');
        $snapshotBefore = rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash'));
        $observedInsideTransaction = [];

        expect(static function () use ($store, $threadId, &$observedInsideTransaction): void {
            DB::transaction(function () use ($store, $threadId, &$observedInsideTransaction): void {
                $store->commitBatch(new CaptureBatch(
                    accountId: 1,
                    mode: SyncMode::Incremental,
                    expectedCursor: '40',
                    cursorAfter: '41',
                    messages: [syntheticCapturedMessage('rollback-message', 'rollback-thread', body: 'After rollback')],
                    touchedThreadIds: ['rollback-thread'],
                ));
                $observedInsideTransaction = [
                    'cursor' => DB::table('sync_state')->where('account_id', 1)->value('history_id'),
                    'snapshot' => rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash')),
                    'pre_analyses' => DB::table('pre_analyses')->count(),
                    'outbox' => DB::table('pre_analysis_dispatch_outbox')->count(),
                ];

                throw new RuntimeException('rollback after successful capture commit');
            });
        })->toThrow(RuntimeException::class, 'rollback after successful capture commit');

        expect($observedInsideTransaction['cursor'])->toBe('41')
            ->and($observedInsideTransaction['snapshot'])->not->toBe($snapshotBefore)
            ->and($observedInsideTransaction['pre_analyses'])->toBe(1)
            ->and($observedInsideTransaction['outbox'])->toBe(1)
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('40')
            ->and(rtrim((string) DB::table('threads')->where('id', $threadId)->value('thread_snapshot_hash')))->toBe($snapshotBefore)
            ->and(DB::table('message_versions')->count())->toBe(1)
            ->and(DB::table('pre_analyses')->count())->toBe(0)
            ->and(DB::table('pre_analysis_dispatch_outbox')->count())->toBe(0);
    });

    it('falha fechado e reverte o batch quando a configuração habilitada está incompleta', function () {
        enableCapturePreAnalysisDispatch();
        config(['preanalysis.question_version' => '']);
        seedSyntheticGmailAccount('50');
        seedActiveCaptureCategory();

        expect(fn () => (new PostgresCaptureStore)->commitBatch(new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '50',
            cursorAfter: '51',
            messages: [syntheticCapturedMessage('misconfigured-message', 'misconfigured-thread')],
            touchedThreadIds: ['misconfigured-thread'],
        )))->toThrow(CaptureFailure::class);

        expect(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('50')
            ->and(DB::table('messages')->count())->toBe(0)
            ->and(DB::table('threads')->whereNotNull('thread_snapshot_hash')->count())->toBe(0)
            ->and(DB::table('pre_analyses')->count())->toBe(0)
            ->and(DB::table('pre_analysis_dispatch_outbox')->count())->toBe(0);
    });

    it('rolls back messages, metadata, enrichment, and Cursor when a page write fails', function () {
        seedSyntheticGmailAccount('4');
        $captured = syntheticCapturedMessage(
            participants: [new Participant('invalid@synthetic.test', null, 'invalid-role')],
            attachments: [new AttachmentMetadata('synthetic.txt', 'text/plain', 24)],
        );
        $batch = new CaptureBatch(
            accountId: 1,
            mode: SyncMode::Incremental,
            expectedCursor: '4',
            cursorAfter: '5',
            messages: [$captured],
        );

        expect(fn () => (new PostgresCaptureStore)->commitBatch($batch))
            ->toThrow(CaptureFailure::class);

        expect(DB::table('threads')->count())->toBe(0)
            ->and(DB::table('messages')->count())->toBe(0)
            ->and(DB::table('message_versions')->count())->toBe(0)
            ->and(DB::table('message_captured_labels')->count())->toBe(0)
            ->and(DB::table('message_version_attachments')->count())->toBe(0)
            ->and(DB::table('message_enrichments')->count())->toBe(0)
            ->and(DB::table('enrichment_participants')->count())->toBe(0)
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('4');
    });
});

describe('PostgresOAuthConnectionStore', function () {
    it('stores and returns only the encrypted refresh token and keeps account references on disconnect', function () {
        $store = new PostgresOAuthConnectionStore;
        $ciphertext = 'synthetic-encrypted-refresh-ciphertext';
        $connectedAt = new DateTimeImmutable('2026-10-01T12:00:00+00:00');
        $connection = new OAuthConnection(
            gmailUserId: 'synthetic-gmail-user',
            emailAddress: 'owner@synthetic.test',
            grantedScopes: GmailScopes::required(),
            refreshTokenCiphertext: $ciphertext,
            connectedAt: $connectedAt,
        );

        $store->save($connection);
        $stored = DB::selectOne(<<<'SQL'
SELECT gmail_user_id,
       email_address,
       to_json(granted_scopes)::text AS granted_scopes_json,
       encode(refresh_token_ciphertext, 'base64') AS refresh_token_ciphertext_base64
FROM gmail_accounts
WHERE id = 1
SQL);
        $restored = $store->get();

        expect($stored->gmail_user_id)->toBe('synthetic-gmail-user')
            ->and($stored->email_address)->toBe('owner@synthetic.test')
            ->and($stored->refresh_token_ciphertext_base64)->toBe(base64_encode($ciphertext))
            ->and(json_decode($stored->granted_scopes_json, true, 512, JSON_THROW_ON_ERROR))->toBe(GmailScopes::required())
            ->and($restored)->not->toBeNull()
            ->and($restored->refreshTokenCiphertext())->toBe($ciphertext)
            ->and($restored->grantedScopes)->toBe(GmailScopes::required());

        DB::table('sync_state')->insert(['account_id' => 1, 'history_id' => '7']);
        $store->delete();

        expect($store->get())->toBeNull()
            ->and(DB::table('gmail_accounts')->where('id', 1)->exists())->toBeTrue()
            ->and(DB::table('sync_state')->where('account_id', 1)->value('history_id'))->toBe('7');
    });
});
