<?php

declare(strict_types=1);

use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use App\Domain\GmailLabels\Services\MessageLabelProjectionResolver;
use App\Infrastructure\Gmail\Labels\PostgresOfficialDecisionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('gmail_accounts')->insert(['id' => 1]);
});

function seedPostgresOfficialDecisionSourceThread(string $gmailThreadId): int
{
    return (int) DB::table('threads')->insertGetId([
        'account_id' => 1,
        'gmail_thread_id' => $gmailThreadId,
    ]);
}

function seedPostgresOfficialDecisionSourceMessage(int $threadId, string $gmailMessageId): int
{
    return (int) DB::table('messages')->insertGetId([
        'account_id' => 1,
        'thread_id' => $threadId,
        'gmail_message_id' => $gmailMessageId,
    ]);
}

/** @return array{category_id: int, category_version_id: int} */
function seedPostgresOfficialDecisionSourceCategory(string $slug, string $name): array
{
    $now = now();
    $categoryId = (int) DB::table('categories')->insertGetId([
        'slug' => $slug,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $categoryVersionId = (int) DB::table('category_versions')->insertGetId([
        'category_id' => $categoryId,
        'version_number' => 1,
        'name' => $name,
        'slug' => $slug,
        'created_at' => $now,
    ]);

    DB::table('categories')->where('id', $categoryId)->update(['current_version_id' => $categoryVersionId]);

    return ['category_id' => $categoryId, 'category_version_id' => $categoryVersionId];
}

function seedPostgresOfficialDecisionSourceReview(int $threadId, ?int $messageId, string $key): int
{
    $now = now();

    return (int) DB::table('reviews')->insertGetId([
        'thread_id' => $threadId,
        'target_scope' => $messageId === null ? 'thread' : 'message',
        'target_message_id' => $messageId,
        'action' => $messageId === null ? 'manual' : 'correct',
        'reviewer_subject' => 'synthetic-reviewer',
        'idempotency_key' => $key,
        'reviewed_at' => $now,
        'created_at' => $now,
    ]);
}

/**
 * @param  list<int>  $categoryVersionIds
 */
function seedPostgresOfficialDecisionSourceThreadClassification(
    int $threadId,
    int $reviewId,
    string $state,
    array $categoryVersionIds,
    DateTimeInterface $classifiedAt,
): int {
    $classificationId = (int) DB::table('thread_classifications')->insertGetId([
        'thread_id' => $threadId,
        'review_id' => $reviewId,
        'review_scope' => 'thread',
        'result_state' => $state,
        'confidence' => 1.0,
        'provider_name' => 'human_review',
        'evidence_refs' => '[]',
        'decision_author' => 'human_review',
        'classified_at' => $classifiedAt,
        'created_at' => $classifiedAt,
    ]);

    foreach ($categoryVersionIds as $categoryVersionId) {
        DB::table('thread_classification_categories')->insert([
            'thread_classification_id' => $classificationId,
            'category_version_id' => $categoryVersionId,
            'created_at' => $classifiedAt,
        ]);
    }

    return $classificationId;
}

/**
 * @param  list<int>  $categoryVersionIds
 */
function seedPostgresOfficialDecisionSourceReviewCategories(int $reviewId, array $categoryVersionIds): void
{
    foreach ($categoryVersionIds as $categoryVersionId) {
        DB::table('review_category_versions')->insert([
            'review_id' => $reviewId,
            'category_version_id' => $categoryVersionId,
            'created_at' => now(),
        ]);
    }
}

describe('fonte PostgreSQL de decisão oficial para Labels', function () {
    it('preserva a classificação apontada enquanto a Thread está pending e não escolhe pelo timestamp', function () {
        $threadId = seedPostgresOfficialDecisionSourceThread('official-source-pending-thread');
        $messageIds = [
            seedPostgresOfficialDecisionSourceMessage($threadId, 'official-source-message-1'),
            seedPostgresOfficialDecisionSourceMessage($threadId, 'official-source-message-2'),
        ];
        $pointedCategory = seedPostgresOfficialDecisionSourceCategory('pointed', 'Apontada');
        $newerCategory = seedPostgresOfficialDecisionSourceCategory('newer', 'Mais recente');
        $pointedCategory['category_version_id'] = (int) DB::table('category_versions')->insertGetId([
            'category_id' => $pointedCategory['category_id'],
            'version_number' => 2,
            'name' => 'Apontada v2',
            'slug' => 'pointed',
            'created_at' => now(),
        ]);
        DB::table('categories')->where('id', $pointedCategory['category_id'])->update([
            'current_version_id' => $pointedCategory['category_version_id'],
        ]);
        $pointedReviewId = seedPostgresOfficialDecisionSourceReview($threadId, null, 'official-source-pointed-review');
        $pointedClassificationId = seedPostgresOfficialDecisionSourceThreadClassification(
            $threadId,
            $pointedReviewId,
            'categorized',
            [$pointedCategory['category_version_id']],
            now(),
        );
        DB::table('threads')->where('id', $threadId)->update([
            'latest_official_classification_id' => $pointedClassificationId,
            'operational_state' => 'pending',
        ]);

        $newerReviewId = seedPostgresOfficialDecisionSourceReview($threadId, null, 'official-source-unpointed-review');
        $newerClassificationId = seedPostgresOfficialDecisionSourceThreadClassification(
            $threadId,
            $newerReviewId,
            'categorized',
            [$newerCategory['category_version_id']],
            now()->addMinute(),
        );

        $snapshot = (new PostgresOfficialDecisionSource)->threadSnapshot($threadId);

        expect($snapshot)->not->toBeNull()
            ->and($snapshot->operationalState)->toBe('pending')
            ->and($snapshot->latestOfficialThreadDecision->decisionId)->toBe($pointedClassificationId)
            ->and($snapshot->latestOfficialThreadDecision->reviewId)->toBe($pointedReviewId)
            ->and($snapshot->latestOfficialThreadDecision->state)->toBe(OfficialDecisionState::CATEGORIZED)
            ->and($snapshot->latestOfficialThreadDecision->categoryIds)->toBe([$pointedCategory['category_id']])
            ->and($snapshot->latestOfficialThreadDecision->categoryIds)->not->toContain($newerCategory['category_id'])
            ->and($snapshot->latestOfficialThreadDecision->decisionId)->not->toBe($newerClassificationId)
            ->and(array_map(static fn ($message): int => $message->messageId, $snapshot->messages))->toBe($messageIds)
            ->and($snapshot->messages[0]->gmailMessageId)->toBe('official-source-message-1');
    });

    it('dá precedência ao override humano vigente por Message e mantém herança nas demais', function () {
        $threadId = seedPostgresOfficialDecisionSourceThread('official-source-overrides-thread');
        $threadMessageId = seedPostgresOfficialDecisionSourceMessage($threadId, 'official-source-inherited-message');
        $overriddenMessageId = seedPostgresOfficialDecisionSourceMessage($threadId, 'official-source-overridden-message');
        $uncategorizedMessageId = seedPostgresOfficialDecisionSourceMessage($threadId, 'official-source-uncategorized-message');
        $threadCategory = seedPostgresOfficialDecisionSourceCategory('thread-category', 'Categoria da Thread');
        $oldMessageCategory = seedPostgresOfficialDecisionSourceCategory('old-message-category', 'Categoria antiga');
        $currentMessageCategory = seedPostgresOfficialDecisionSourceCategory('current-message-category', 'Categoria vigente');
        $threadReviewId = seedPostgresOfficialDecisionSourceReview($threadId, null, 'official-source-override-thread-review');
        $threadClassificationId = seedPostgresOfficialDecisionSourceThreadClassification(
            $threadId,
            $threadReviewId,
            'categorized',
            [$threadCategory['category_version_id']],
            now(),
        );
        DB::table('threads')->where('id', $threadId)->update([
            'latest_official_classification_id' => $threadClassificationId,
        ]);

        $oldReviewId = seedPostgresOfficialDecisionSourceReview($threadId, $overriddenMessageId, 'official-source-old-message-review');
        seedPostgresOfficialDecisionSourceReviewCategories($oldReviewId, [$oldMessageCategory['category_version_id']]);
        $currentReviewId = seedPostgresOfficialDecisionSourceReview($threadId, $overriddenMessageId, 'official-source-current-message-review');
        seedPostgresOfficialDecisionSourceReviewCategories($currentReviewId, [$currentMessageCategory['category_version_id']]);
        DB::table('message_category_overrides')->insert([
            'message_id' => $overriddenMessageId,
            'thread_id' => $threadId,
            'review_id' => $currentReviewId,
            'review_scope' => 'message',
            'result_state' => 'categorized',
            'updated_at' => now(),
        ]);

        $uncategorizedReviewId = seedPostgresOfficialDecisionSourceReview(
            $threadId,
            $uncategorizedMessageId,
            'official-source-uncategorized-message-review',
        );
        DB::table('message_category_overrides')->insert([
            'message_id' => $uncategorizedMessageId,
            'thread_id' => $threadId,
            'review_id' => $uncategorizedReviewId,
            'review_scope' => 'message',
            'result_state' => 'uncategorized',
            'updated_at' => now(),
        ]);

        $snapshot = (new PostgresOfficialDecisionSource)->threadSnapshot($threadId);
        $projections = (new MessageLabelProjectionResolver)->resolve($snapshot);
        $overriddenMessage = $snapshot->messages[1];
        $uncategorizedMessage = $snapshot->messages[2];

        expect($snapshot->latestOfficialThreadDecision->decisionId)->toBe($threadClassificationId)
            ->and($overriddenMessage->messageOverride->decisionId)->toBe($currentReviewId)
            ->and($overriddenMessage->messageOverride->reviewId)->toBe($currentReviewId)
            ->and($overriddenMessage->messageOverride->targetMessageId)->toBe($overriddenMessageId)
            ->and($overriddenMessage->messageOverride->categoryIds)->toBe([$currentMessageCategory['category_id']])
            ->and($overriddenMessage->messageOverride->categoryIds)->not->toContain($oldMessageCategory['category_id'])
            ->and($snapshot->messages[0]->messageOverride)->toBeNull()
            ->and($uncategorizedMessage->messageOverride->state)->toBe(OfficialDecisionState::UNCATEGORIZED)
            ->and($uncategorizedMessage->messageOverride->categoryIds)->toBe([])
            ->and(array_map(static fn ($projection): int => $projection->messageId, $projections))
            ->toBe([$threadMessageId, $overriddenMessageId, $uncategorizedMessageId])
            ->and($projections[0]->decision->scope)->toBe(DecisionScope::THREAD)
            ->and($projections[1]->decision->scope)->toBe(DecisionScope::MESSAGE)
            ->and($projections[1]->categoryIds)->toBe([$currentMessageCategory['category_id']])
            ->and($projections[2]->decision->state)->toBe(OfficialDecisionState::UNCATEGORIZED)
            ->and($projections[2]->categoryIds)->toBe([]);
    });

    it('retorna null quando só há Pré-análise e Sugestões consultivas', function () {
        $threadId = seedPostgresOfficialDecisionSourceThread('official-source-consultative-thread');
        seedPostgresOfficialDecisionSourceMessage($threadId, 'official-source-consultative-message');
        $suggestedCategory = seedPostgresOfficialDecisionSourceCategory('suggested-only', 'Somente sugerida');
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
            'category_version_id' => $suggestedCategory['category_version_id'],
            'probability' => 0.99,
            'created_at' => $now,
        ]);

        expect((new PostgresOfficialDecisionSource)->threadSnapshot($threadId))->toBeNull();
    });
});
