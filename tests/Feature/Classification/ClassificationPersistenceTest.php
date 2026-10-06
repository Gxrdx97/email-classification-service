<?php

declare(strict_types=1);

use App\Domain\Classification\Contracts\EvidenceReferenceVerifier;
use App\Domain\Classification\Contracts\OfficialDecisionProjector;
use App\Domain\Classification\Contracts\ReviewActorProvider;
use App\Domain\Classification\Data\ReviewActor;
use App\Domain\Classification\Data\ReviewDecision;
use App\Domain\Classification\Data\ReviewRequest;
use App\Domain\Classification\Enums\InconclusiveReason;
use App\Domain\Classification\Enums\OfficialOutcome;
use App\Domain\Classification\Enums\ReviewAction;
use App\Domain\Classification\Enums\ReviewScope;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\InvalidReviewDecision;
use App\Domain\Classification\Exceptions\ReviewIdempotencyConflict;
use App\Domain\Classification\Repositories\PostgresCategoryRepository;
use App\Domain\Classification\Repositories\PostgresClassificationOperationalStateRepository;
use App\Domain\Classification\Repositories\PostgresClassificationReviewRepository;
use App\Domain\Classification\Services\CategoryService;
use App\Domain\Classification\Services\ClassificationReviewService;
use App\Domain\GmailLabels\Contracts\ProjectionIntentDispatcher;
use App\Domain\GmailLabels\Services\MessageLabelProjectionResolver;
use App\Domain\GmailLabels\Services\ProjectCategoryLabels;
use App\Infrastructure\Gmail\Labels\PostgresGmailLabelProjectionRepository;
use App\Infrastructure\Gmail\Labels\PostgresOfficialDecisionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

$categoryService = static fn (): CategoryService => new CategoryService(new PostgresCategoryRepository);

$evidenceVerifier = static fn (): EvidenceReferenceVerifier => new class implements EvidenceReferenceVerifier
{
    public function isVerifiedLocalReference(int $threadId, string $reference): bool
    {
        return str_starts_with($reference, 'local:');
    }
};

$noOpProjector = static fn (): OfficialDecisionProjector => new class implements OfficialDecisionProjector
{
    public function recordForThread(int $threadId): int
    {
        return 0;
    }

    public function recordForMessageOverride(int $threadId, int $messageId): int
    {
        return 0;
    }
};

$reviewService = static fn (?OfficialDecisionProjector $projector = null): ClassificationReviewService => new ClassificationReviewService(
    new PostgresClassificationReviewRepository($evidenceVerifier(), $projector ?? $noOpProjector()),
    new class implements ReviewActorProvider
    {
        public function currentActor(): ReviewActor
        {
            return new ReviewActor('user@example.test');
        }
    },
    $evidenceVerifier(),
);

$projectCategoryLabels = static fn (ProjectionIntentDispatcher $dispatcher): ProjectCategoryLabels => new ProjectCategoryLabels(
    new PostgresOfficialDecisionSource,
    new MessageLabelProjectionResolver,
    new PostgresGmailLabelProjectionRepository,
    $dispatcher,
);

$createThread = static function (string $gmailThreadId): int {
    return (int) DB::table('threads')->insertGetId([
        'account_id' => 1,
        'gmail_thread_id' => $gmailThreadId,
    ]);
};

beforeEach(function (): void {
    DB::table('gmail_accounts')->insert(['id' => 1]);
});

describe('persistência de Classification', function () use ($categoryService, $evidenceVerifier, $reviewService, $createThread, $projectCategoryLabels, $noOpProjector) {
    it('cria snapshots append-only e mantém versões anteriores endereçáveis', function () use ($categoryService) {
        $categories = $categoryService();
        $first = $categories->create('finance', 'Financeiro', 'Despesas e pagamentos');
        $second = $categories->revise($first->id, 'finance', 'Financeiro pessoal', 'Despesas pessoais');

        expect($second->currentVersion->versionNumber)->toBe(2)
            ->and($categories->version($first->currentVersion->id)->name)->toBe('Financeiro')
            ->and(DB::table('category_versions')->where('category_id', $first->id)->count())->toBe(2)
            ->and((int) DB::table('categories')->where('id', $first->id)->value('current_version_id'))
            ->toBe($second->currentVersion->id);
    });

    it('persiste decisão oficial apenas após Revisão humana e repete a mesma chave idempotentemente', function () use ($categoryService, $reviewService, $createThread) {
        $threadId = $createThread('thread-official-review');
        $category = $categoryService()->create('finance', 'Financeiro');
        $request = new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::ConfirmSuggestion,
            idempotencyKey: 'review-official-1',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
            confidence: 0.91,
            evidenceRefs: ['local:message:10'],
        );
        $reviews = $reviewService();

        $first = $reviews->submit($request);
        $categoryService()->archive($category->id);
        $replay = $reviews->submit($request);
        $classification = DB::table('thread_classifications')->where('id', $first->officialClassificationId)->first();

        expect($replay->idempotentReplay)->toBeTrue()
            ->and($replay->reviewId)->toBe($first->reviewId)
            ->and(DB::table('reviews')->where('thread_id', $threadId)->count())->toBe(1)
            ->and(DB::table('thread_classifications')->where('thread_id', $threadId)->count())->toBe(1)
            ->and($classification->decision_author)->toBe('human_review')
            ->and($classification->provider_name)->toBe('human_review')
            ->and((int) DB::table('threads')->where('id', $threadId)->value('latest_official_classification_id'))
            ->toBe($first->officialClassificationId);
    });

    it('rejeita a reutilização de uma chave idempotente para outra decisão', function () use ($categoryService, $reviewService, $createThread) {
        $threadId = $createThread('thread-idempotency-conflict');
        $category = $categoryService()->create('finance', 'Financeiro');
        $reviews = $reviewService();
        $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-key-reused',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));

        expect(fn () => $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-key-reused',
            outcome: OfficialOutcome::Uncategorized,
        )))->toThrow(ReviewIdempotencyConflict::class);
    });

    it('revalida a Categoria ativa dentro do repositório antes de persistir a Revisão', function () use ($categoryService, $createThread, $evidenceVerifier, $noOpProjector) {
        $threadId = $createThread('thread-stale-category-version');
        $categories = $categoryService();
        $category = $categories->create('finance', 'Financeiro');
        $categories->archive($category->id);
        $decision = new ReviewDecision(
            $threadId,
            ReviewScope::Thread,
            null,
            ReviewAction::Manual,
            'user@example.test',
            'review-stale-category-version',
            OfficialOutcome::Categorized,
            [$category->id],
            1.0,
            null,
            [],
        );

        expect(fn () => (new PostgresClassificationReviewRepository($evidenceVerifier(), $noOpProjector()))->record($decision))
            ->toThrow(CategoryNotActive::class);

        expect(DB::table('reviews')->where('thread_id', $threadId)->count())->toBe(0)
            ->and(DB::table('thread_classifications')->where('thread_id', $threadId)->count())->toBe(0);
    });

    it('registra divergência de Revisão Message-only sem mover o resultado oficial da Thread', function () use ($categoryService, $reviewService, $createThread) {
        $threadId = $createThread('thread-message-divergence');
        $messageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-divergent-1',
        ]);
        $categories = $categoryService();
        $threadCategory = $categories->create('finance', 'Financeiro');
        $messageCategory = $categories->create('personal', 'Pessoal');
        $reviews = $reviewService();
        $threadReview = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-thread-source',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$threadCategory->id],
        ));

        $messageReview = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Message,
            targetMessageId: $messageId,
            action: ReviewAction::Correct,
            idempotencyKey: 'review-message-override',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$messageCategory->id],
        ));

        $override = DB::table('message_category_overrides')->where('message_id', $messageId)->first();
        $overrideCategoryVersionId = DB::table('review_category_versions')
            ->where('review_id', $messageReview->reviewId)
            ->value('category_version_id');

        expect($messageReview->officialClassificationId)->toBeNull()
            ->and((int) $override->review_id)->toBe($messageReview->reviewId)
            ->and($override->result_state)->toBe('categorized')
            ->and((int) $overrideCategoryVersionId)->toBe($messageCategory->currentVersion->id)
            ->and((int) DB::table('threads')->where('id', $threadId)->value('latest_official_classification_id'))
            ->toBe($threadReview->officialClassificationId)
            ->and(DB::table('thread_classifications')->where('thread_id', $threadId)->count())->toBe(1);
    });

    it('rejeita override Message-only que repete a decisão oficial sem divergência', function () use ($categoryService, $reviewService, $createThread) {
        $threadId = $createThread('thread-message-same-result');
        $messageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'message-same-result-1',
        ]);
        $category = $categoryService()->create('finance', 'Financeiro');
        $reviews = $reviewService();
        $official = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-same-thread-result',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));

        expect(fn () => $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Message,
            targetMessageId: $messageId,
            action: ReviewAction::Correct,
            idempotencyKey: 'review-no-message-divergence',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        )))->toThrow(InvalidReviewDecision::class);

        expect(DB::table('reviews')->where('thread_id', $threadId)->count())->toBe(1)
            ->and(DB::table('message_category_overrides')->where('message_id', $messageId)->exists())->toBeFalse()
            ->and((int) DB::table('threads')->where('id', $threadId)->value('latest_official_classification_id'))
            ->toBe($official->officialClassificationId);
    });

    it('preserva a última decisão oficial quando o Estado operacional passa por pending ou failed', function () use ($categoryService, $reviewService, $createThread) {
        $threadId = $createThread('thread-operational-state');
        $category = $categoryService()->create('finance', 'Financeiro');
        $review = $reviewService()->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-before-failure',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));
        $operationalState = new PostgresClassificationOperationalStateRepository;

        $operationalState->markPending($threadId);
        $pendingThread = DB::table('threads')->where('id', $threadId)->first();
        $operationalState->markFailed($threadId);
        $failedThread = DB::table('threads')->where('id', $threadId)->first();

        expect($pendingThread->operational_state)->toBe('pending')
            ->and((int) $pendingThread->latest_official_classification_id)->toBe($review->officialClassificationId)
            ->and($failedThread->operational_state)->toBe('failed')
            ->and((int) $failedThread->latest_official_classification_id)->toBe($review->officialClassificationId)
            ->and(DB::table('thread_classifications')->where('id', $review->officialClassificationId)->exists())->toBeTrue();
    });

    it('registra rejeição sem substituir a decisão oficial existente', function () use ($categoryService, $reviewService, $createThread) {
        $threadId = $createThread('thread-review-rejection');
        $category = $categoryService()->create('finance', 'Financeiro');
        $reviews = $reviewService();
        $official = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-initial-decision',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));

        $rejection = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::RejectSuggestion,
            idempotencyKey: 'review-reject-suggestion',
        ));

        expect($rejection->officialClassificationId)->toBeNull()
            ->and((int) DB::table('threads')->where('id', $threadId)->value('latest_official_classification_id'))
            ->toBe($official->officialClassificationId)
            ->and(DB::table('thread_classifications')->where('thread_id', $threadId)->count())->toBe(1);
    });

    it('persiste uma intenção por Message na transação da Revisão e não duplica intenções no replay', function () use ($categoryService, $reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-review-projection-atomicity');
        $messageIds = [
            (int) DB::table('messages')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'projection-message-1',
            ]),
            (int) DB::table('messages')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'projection-message-2',
            ]),
        ];
        $category = $categoryService()->create('work', 'Trabalho');
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            /** @var list<array{outbox_id: int, message_id: int, transaction_level: int}> */
            public array $scheduled = [];

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->scheduled[] = [
                    'outbox_id' => $outboxId,
                    'message_id' => $messageId,
                    'transaction_level' => DB::transactionLevel(),
                ];
            }
        };
        $reviews = $reviewService($projectCategoryLabels($dispatcher));
        $request = new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-project-all-messages',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        );

        $first = $reviews->submit($request);
        $replay = $reviews->submit($request);
        $intents = DB::table('gmail_label_projection_outbox')->orderBy('message_id')->get();

        expect($replay->idempotentReplay)->toBeTrue()
            ->and($replay->reviewId)->toBe($first->reviewId)
            ->and($intents)->toHaveCount(2)
            ->and($intents->pluck('message_id')->map(static fn (mixed $id): int => (int) $id)->all())->toBe($messageIds)
            ->and(DB::table('gmail_label_projection_states')->count())->toBe(2)
            ->and(DB::table('gmail_label_projection_outbox')->where('generation', 1)->count())->toBe(2)
            ->and($dispatcher->scheduled)->toHaveCount(4)
            ->and(collect($dispatcher->scheduled)->every(static fn (array $dispatch): bool => $dispatch['transaction_level'] > 0))->toBeTrue();
    });

    it('persiste uma intenção vazia por Message para decisão oficial uncategorized', function () use ($reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-uncategorized-label-projection');
        $messageIds = [
            (int) DB::table('messages')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'uncategorized-message-1',
            ]),
            (int) DB::table('messages')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'uncategorized-message-2',
            ]),
        ];
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            /** @var list<int> */
            public array $messageIds = [];

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->messageIds[] = $messageId;
            }
        };

        $reviewService($projectCategoryLabels($dispatcher))->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-uncategorized-label-projection',
            outcome: OfficialOutcome::Uncategorized,
        ));

        expect(DB::table('gmail_label_projection_outbox')->count())->toBe(2)
            ->and(DB::table('gmail_label_projection_outbox')->pluck('desired_state')->unique()->all())->toBe(['uncategorized'])
            ->and(DB::table('gmail_label_projection_outbox_categories')->count())->toBe(0)
            ->and($dispatcher->messageIds)->toBe($messageIds);
    });

    it('limita a nova projeção do override humano à Message alvo', function () use ($categoryService, $reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-review-message-projection');
        $inheritedMessageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'projection-inherited-message',
        ]);
        $overriddenMessageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'projection-overridden-message',
        ]);
        $uncategorizedMessageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'projection-uncategorized-message',
        ]);
        $categories = $categoryService();
        $threadCategory = $categories->create('thread-work', 'Trabalho da Thread');
        $messageCategory = $categories->create('message-personal', 'Pessoal da Message');
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            /** @var list<array{outbox_id: int, message_id: int, transaction_level: int}> */
            public array $scheduled = [];

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->scheduled[] = [
                    'outbox_id' => $outboxId,
                    'message_id' => $messageId,
                    'transaction_level' => DB::transactionLevel(),
                ];
            }
        };
        $reviews = $reviewService($projectCategoryLabels($dispatcher));

        $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-project-inheritance',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$threadCategory->id],
        ));
        $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Message,
            targetMessageId: $overriddenMessageId,
            action: ReviewAction::Correct,
            idempotencyKey: 'review-project-message-override',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$messageCategory->id],
        ));
        $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Message,
            targetMessageId: $uncategorizedMessageId,
            action: ReviewAction::Correct,
            idempotencyKey: 'review-project-message-uncategorized-override',
            outcome: OfficialOutcome::Uncategorized,
        ));

        $messageIntents = DB::table('gmail_label_projection_outbox')
            ->where('message_id', $overriddenMessageId)
            ->orderBy('generation')
            ->get();
        $latestOverrideIntent = $messageIntents->last();
        $uncategorizedIntents = DB::table('gmail_label_projection_outbox')
            ->where('message_id', $uncategorizedMessageId)
            ->orderBy('generation')
            ->get();

        expect($messageIntents)->toHaveCount(2)
            ->and(DB::table('gmail_label_projection_outbox')->where('message_id', $inheritedMessageId)->count())->toBe(1)
            ->and($uncategorizedIntents)->toHaveCount(2)
            ->and(DB::table('gmail_label_projection_outbox')->count())->toBe(5)
            ->and($latestOverrideIntent->source_scope)->toBe('message')
            ->and(DB::table('gmail_label_projection_outbox_categories')->where('outbox_id', $latestOverrideIntent->id)->pluck('category_id')->map(static fn (mixed $id): int => (int) $id)->all())
            ->toBe([$messageCategory->id])
            ->and($uncategorizedIntents->last()->source_scope)->toBe('message')
            ->and($uncategorizedIntents->last()->desired_state)->toBe('uncategorized')
            ->and(DB::table('gmail_label_projection_outbox_categories')->where('outbox_id', $uncategorizedIntents->last()->id)->count())->toBe(0)
            ->and(array_column($dispatcher->scheduled, 'message_id'))
            ->toBe([$inheritedMessageId, $overriddenMessageId, $uncategorizedMessageId, $overriddenMessageId, $uncategorizedMessageId]);
    });

    it('supersede projeções pendentes sem limpar Labels após decisão oficial inconclusive', function () use ($categoryService, $reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-inconclusive-label-projection');
        $messageIds = [
            (int) DB::table('messages')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'inconclusive-message-1',
            ]),
            (int) DB::table('messages')->insertGetId([
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'inconclusive-message-2',
            ]),
        ];
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            /** @var list<int> */
            public array $messageIds = [];

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->messageIds[] = $messageId;
            }
        };
        $category = $categoryService()->create('inconclusive-preserve', 'Categoria anterior');
        $reviews = $reviewService($projectCategoryLabels($dispatcher));
        $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-before-inconclusive-label-projection',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));
        $review = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-inconclusive-label-projection',
            outcome: OfficialOutcome::Inconclusive,
            inconclusiveReason: InconclusiveReason::LowConfidence,
        ));
        $classification = DB::table('thread_classifications')->where('id', $review->officialClassificationId)->first();

        expect($classification->result_state)->toBe('inconclusive')
            ->and(DB::table('gmail_label_projection_outbox')->count())->toBe(2)
            ->and(DB::table('gmail_label_projection_outbox')->pluck('status')->unique()->all())->toBe(['superseded'])
            ->and(DB::table('gmail_label_projection_outbox_categories')->count())->toBe(2)
            ->and(DB::table('gmail_label_projection_states')->pluck('desired_state')->unique()->all())->toBe(['categorized'])
            ->and(DB::table('gmail_label_projection_states')->pluck('status')->unique()->all())->toBe(['suppressed'])
            ->and(DB::table('message_category_label_assignments')->whereIn('message_id', $messageIds)->count())->toBe(2)
            ->and($dispatcher->messageIds)->toBe($messageIds);
    });

    it('não cria uma intenção nova ao rejeitar Sugestão com decisão oficial existente', function () use ($categoryService, $reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-rejection-without-projection');
        $messageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'rejection-existing-official-message',
        ]);
        $category = $categoryService()->create('rejection-official-category', 'Categoria oficial anterior');
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            public int $scheduled = 0;

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->scheduled++;
            }
        };
        $reviews = $reviewService($projectCategoryLabels($dispatcher));

        $official = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-before-reject-suggestion',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));

        $rejection = $reviews->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::RejectSuggestion,
            idempotencyKey: 'review-reject-without-projection',
        ));

        expect($rejection->officialClassificationId)->toBeNull()
            ->and((int) DB::table('threads')->where('id', $threadId)->value('latest_official_classification_id'))
            ->toBe($official->officialClassificationId)
            ->and(DB::table('gmail_label_projection_outbox')->where('message_id', $messageId)->count())->toBe(1)
            ->and(DB::table('gmail_label_projection_states')->where('message_id', $messageId)->value('desired_generation'))->toBe(1)
            ->and($dispatcher->scheduled)->toBe(1);
    });

    it('reverte Revisão, estado e outbox juntos se não for possível registrar o dispatch pós-commit', function () use ($categoryService, $reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-projection-callback-rollback');
        DB::table('messages')->insert([
            [
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'rollback-message-1',
            ],
            [
                'account_id' => 1,
                'thread_id' => $threadId,
                'gmail_message_id' => 'rollback-message-2',
            ],
        ]);
        $category = $categoryService()->create('rollback-category', 'Categoria de rollback');
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                throw new RuntimeException('Não foi possível registrar o callback de dispatch.');
            }
        };

        expect(fn () => $reviewService($projectCategoryLabels($dispatcher))->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-project-rollback',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        )))->toThrow(RuntimeException::class);

        expect(DB::table('reviews')->where('thread_id', $threadId)->count())->toBe(0)
            ->and(DB::table('thread_classifications')->where('thread_id', $threadId)->count())->toBe(0)
            ->and(DB::table('gmail_label_projection_outbox')->where('thread_id', $threadId)->count())->toBe(0)
            ->and(DB::table('gmail_label_projection_states')->where('thread_id', $threadId)->count())->toBe(0)
            ->and(DB::table('message_category_label_assignments')->where('thread_id', $threadId)->count())->toBe(0);
    });

    it('mantém as intenções duráveis se o enqueue falhar após a transação da Revisão', function () use ($categoryService, $reviewService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-enqueue-failure-after-commit');
        $messageId = (int) DB::table('messages')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'enqueue-failure-message',
        ]);
        $category = $categoryService()->create('enqueue-retry', 'Reprocessamento de enqueue');
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            /** @var list<array{outbox_id: int, message_id: int, transaction_level: int}> */
            public array $scheduled = [];

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->scheduled[] = [
                    'outbox_id' => $outboxId,
                    'message_id' => $messageId,
                    'transaction_level' => DB::transactionLevel(),
                ];
            }

            public function failEnqueue(): never
            {
                throw new RuntimeException('Queue indisponível.');
            }
        };
        $transactionLevelBeforeReview = DB::transactionLevel();

        $review = $reviewService($projectCategoryLabels($dispatcher))->submit(new ReviewRequest(
            threadId: $threadId,
            scope: ReviewScope::Thread,
            targetMessageId: null,
            action: ReviewAction::Manual,
            idempotencyKey: 'review-enqueue-failure-after-commit',
            outcome: OfficialOutcome::Categorized,
            categoryIds: [$category->id],
        ));

        expect($dispatcher->scheduled)->toHaveCount(1)
            ->and($dispatcher->scheduled[0]['message_id'])->toBe($messageId)
            ->and($dispatcher->scheduled[0]['transaction_level'])->toBeGreaterThan($transactionLevelBeforeReview)
            ->and(DB::transactionLevel())->toBe($transactionLevelBeforeReview)
            ->and(DB::table('reviews')->where('id', $review->reviewId)->exists())->toBeTrue()
            ->and(DB::table('gmail_label_projection_outbox')->where('message_id', $messageId)->count())->toBe(1);

        expect(fn () => $dispatcher->failEnqueue())->toThrow(RuntimeException::class);

        expect(DB::table('reviews')->where('id', $review->reviewId)->exists())->toBeTrue()
            ->and(DB::table('gmail_label_projection_outbox')->where('message_id', $messageId)->count())->toBe(1)
            ->and(DB::table('gmail_label_projection_states')->where('message_id', $messageId)->value('status'))->toBe('pending');
    });

    it('ignora Sugestões quando projeta uma Thread sem decisão humana oficial', function () use ($categoryService, $createThread, $projectCategoryLabels) {
        $threadId = $createThread('thread-suggestion-is-not-official');
        DB::table('messages')->insert([
            'account_id' => 1,
            'thread_id' => $threadId,
            'gmail_message_id' => 'suggestion-only-message',
        ]);
        $category = $categoryService()->create('suggested-work', 'Trabalho sugerido');
        $now = now();
        $preAnalysisId = (int) DB::table('pre_analyses')->insertGetId([
            'account_id' => 1,
            'thread_id' => $threadId,
            'thread_snapshot_hash' => str_repeat('a', 64),
            'category_catalog_version' => 'synthetic-catalog-v1',
            'provider' => 'jev',
            'provider_model' => 'synthetic-model-v1',
            'question_version' => 'synthetic-question-v1',
            'schema_version' => 'synthetic-schema-v1',
            'privacy_policy_version' => 'synthetic-policy-v1',
            'status' => 'completed',
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('category_suggestions')->insert([
            'pre_analysis_id' => $preAnalysisId,
            'category_version_id' => $category->currentVersion->id,
            'probability' => 0.99,
            'created_at' => $now,
        ]);
        $dispatcher = new class implements ProjectionIntentDispatcher
        {
            public int $scheduled = 0;

            public function dispatchAfterCommit(int $outboxId, int $messageId): void
            {
                $this->scheduled++;
            }
        };

        expect($projectCategoryLabels($dispatcher)->recordForThread($threadId))->toBe(0)
            ->and(DB::table('gmail_label_projection_outbox')->count())->toBe(0)
            ->and($dispatcher->scheduled)->toBe(0);
    });
});
