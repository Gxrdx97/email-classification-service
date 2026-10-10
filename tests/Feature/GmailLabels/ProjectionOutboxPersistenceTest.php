<?php

declare(strict_types=1);

use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use App\Domain\GmailLabels\Data\OfficialDecision;
use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use App\Infrastructure\Gmail\Labels\PostgresGmailLabelProjectionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedGmailLabelProjectionPersistenceRows(): void
{
    $timestamp = now();

    DB::table('gmail_accounts')->insert([
        'id' => 1,
        'gmail_user_id' => 'synthetic-user',
        'email_address' => 'synthetic@example.test',
        'connected_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('threads')->insert([
        'id' => 1,
        'account_id' => 1,
        'gmail_thread_id' => 'gmail-thread-synthetic',
        'operational_state' => 'pending',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('messages')->insert([
        'id' => 1,
        'account_id' => 1,
        'thread_id' => 1,
        'gmail_message_id' => 'gmail-message-synthetic',
        'first_seen_at' => $timestamp,
        'last_seen_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('categories')->insert([
        'id' => 1,
        'slug' => 'work',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    DB::table('category_versions')->insert([
        'id' => 1,
        'category_id' => 1,
        'version_number' => 1,
        'name' => 'Trabalho',
        'slug' => 'work',
        'created_at' => $timestamp,
    ]);
    DB::table('categories')->where('id', 1)->update(['current_version_id' => 1]);
    DB::table('reviews')->insert([
        'id' => 1,
        'thread_id' => 1,
        'target_scope' => 'thread',
        'target_message_id' => null,
        'action' => 'manual',
        'reviewer_subject' => 'synthetic-reviewer',
        'idempotency_key' => 'synthetic-review-1',
        'reviewed_at' => $timestamp,
        'created_at' => $timestamp,
    ]);
    DB::table('thread_classifications')->insert([
        'id' => 1,
        'thread_id' => 1,
        'review_id' => 1,
        'review_scope' => 'thread',
        'result_state' => 'categorized',
        'confidence' => 0.99,
        'provider_name' => 'rules',
        'category_catalog_version' => 'synthetic-v1',
        'evidence_refs' => '[]',
        'decision_author' => 'human_review',
        'classified_at' => $timestamp,
        'created_at' => $timestamp,
    ]);
    DB::table('thread_classification_categories')->insert([
        'thread_classification_id' => 1,
        'category_version_id' => 1,
        'created_at' => $timestamp,
    ]);
    DB::table('threads')->where('id', 1)->update(['latest_official_classification_id' => 1]);
}

describe('persistência PostgreSQL da projeção de Labels', function () {
    it('grava estado, assignments e outbox de forma idempotente sem duplicar retry', function () {
        seedGmailLabelProjectionPersistenceRows();

        $decision = new OfficialDecision(
            decisionId: 1,
            reviewId: 1,
            threadId: 1,
            scope: DecisionScope::THREAD,
            targetMessageId: null,
            state: OfficialDecisionState::CATEGORIZED,
            categoryIds: [1],
            decisionAuthor: 'human_review',
        );
        $projection = new DesiredMessageLabelProjection(
            accountId: 1,
            messageId: 1,
            threadId: 1,
            gmailMessageId: 'gmail-message-synthetic',
            decision: $decision,
            categoryIds: [1],
        );
        $repository = new PostgresGmailLabelProjectionRepository;
        $idempotencyKey = hash('sha256', 'synthetic-official-decision');

        $first = $repository->record($projection, $idempotencyKey);
        $retry = $repository->record($projection, $idempotencyKey);

        $timestamp = now();
        DB::table('categories')->insert([
            'id' => 2,
            'slug' => 'priority',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('category_versions')->insert([
            'id' => 2,
            'category_id' => 2,
            'version_number' => 1,
            'name' => 'Prioridade',
            'slug' => 'priority',
            'created_at' => $timestamp,
        ]);
        DB::table('categories')->where('id', 2)->update(['current_version_id' => 2]);
        DB::table('reviews')->insert([
            'id' => 2,
            'thread_id' => 1,
            'target_scope' => 'thread',
            'target_message_id' => null,
            'action' => 'manual',
            'reviewer_subject' => 'synthetic-reviewer',
            'idempotency_key' => 'synthetic-review-2',
            'reviewed_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        DB::table('thread_classifications')->insert([
            'id' => 2,
            'thread_id' => 1,
            'review_id' => 2,
            'review_scope' => 'thread',
            'result_state' => 'categorized',
            'confidence' => 0.99,
            'provider_name' => 'rules',
            'category_catalog_version' => 'synthetic-v2',
            'evidence_refs' => '[]',
            'decision_author' => 'human_review',
            'classified_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        DB::table('thread_classification_categories')->insert([
            'thread_classification_id' => 2,
            'category_version_id' => 2,
            'created_at' => $timestamp,
        ]);
        DB::table('threads')->where('id', 1)->update(['latest_official_classification_id' => 2]);
        $newDecision = new OfficialDecision(
            decisionId: 2,
            reviewId: 2,
            threadId: 1,
            scope: DecisionScope::THREAD,
            targetMessageId: null,
            state: OfficialDecisionState::CATEGORIZED,
            categoryIds: [2],
            decisionAuthor: 'human_review',
        );
        $reclassified = new DesiredMessageLabelProjection(
            accountId: 1,
            messageId: 1,
            threadId: 1,
            gmailMessageId: 'gmail-message-synthetic',
            decision: $newDecision,
            categoryIds: [2],
        );
        $second = $repository->record($reclassified, hash('sha256', 'synthetic-official-decision-2'));

        expect($retry->outboxId)->toBe($first->outboxId)
            ->and($second->outboxId)->not->toBe($first->outboxId)
            ->and(DB::table('gmail_label_projection_outbox')->count())->toBe(2)
            ->and(DB::table('gmail_label_projection_outbox_categories')->count())->toBe(2)
            ->and(DB::table('message_category_label_assignments')->count())->toBe(1)
            ->and((int) DB::table('message_category_label_assignments')->value('category_id'))->toBe(2)
            ->and((int) DB::table('gmail_label_projection_states')->where('message_id', 1)->value('desired_generation'))->toBe(2)
            ->and(DB::table('gmail_label_projection_states')->where('message_id', 1)->value('desired_state'))->toBe('categorized')
            ->and(DB::table('gmail_label_projection_states')->where('message_id', 1)->value('status'))->toBe('pending');

        expect($repository->claim($first->outboxId))->toBeNull()
            ->and(DB::table('gmail_label_projection_outbox')->where('id', $first->outboxId)->value('status'))
            ->toBe('superseded');
    });

    it('supersede uma projeção em andamento e preserva o estado desejado sem chamar Gmail', function () {
        seedGmailLabelProjectionPersistenceRows();

        $decision = new OfficialDecision(
            decisionId: 1,
            reviewId: 1,
            threadId: 1,
            scope: DecisionScope::THREAD,
            targetMessageId: null,
            state: OfficialDecisionState::CATEGORIZED,
            categoryIds: [1],
            decisionAuthor: 'human_review',
        );
        $projection = new DesiredMessageLabelProjection(
            accountId: 1,
            messageId: 1,
            threadId: 1,
            gmailMessageId: 'gmail-message-synthetic',
            decision: $decision,
            categoryIds: [1],
        );
        $repository = new PostgresGmailLabelProjectionRepository;
        $receipt = $repository->record($projection, hash('sha256', 'superseded-projection'));

        expect($repository->claim($receipt->outboxId))->not->toBeNull();

        $repository->supersedeActiveForMessage(1, 'official_decision_not_actionable');

        expect(DB::table('gmail_label_projection_outbox')->where('id', $receipt->outboxId)->value('status'))
            ->toBe('superseded')
            ->and(DB::table('gmail_label_projection_outbox')->where('id', $receipt->outboxId)->value('completed_at'))
            ->not->toBeNull()
            ->and(DB::table('gmail_label_projection_states')->where('message_id', 1)->value('status'))
            ->toBe('suppressed')
            ->and(DB::table('gmail_label_projection_states')->where('message_id', 1)->value('desired_state'))
            ->toBe('categorized')
            ->and(DB::table('message_category_label_assignments')->where('message_id', 1)->count())
            ->toBe(1)
            ->and($repository->claim($receipt->outboxId))->toBeNull();
    });

    it('supersede uma intent claimada quando a revisão vigente muda antes do envio', function () {
        seedGmailLabelProjectionPersistenceRows();

        $decision = new OfficialDecision(
            decisionId: 1,
            reviewId: 1,
            threadId: 1,
            scope: DecisionScope::THREAD,
            targetMessageId: null,
            state: OfficialDecisionState::CATEGORIZED,
            categoryIds: [1],
            decisionAuthor: 'human_review',
        );
        $projection = new DesiredMessageLabelProjection(
            accountId: 1,
            messageId: 1,
            threadId: 1,
            gmailMessageId: 'gmail-message-synthetic',
            decision: $decision,
            categoryIds: [1],
        );
        $repository = new PostgresGmailLabelProjectionRepository;
        $receipt = $repository->record($projection, hash('sha256', 'decision-changed-projection'));
        $intent = $repository->claim($receipt->outboxId)
            ?? throw new RuntimeException('Expected the projection intent to be claimable.');

        $repository->supersede($intent, 'official_decision_changed');

        expect(DB::table('gmail_label_projection_outbox')->where('id', $receipt->outboxId)->value('status'))
            ->toBe('superseded')
            ->and(DB::table('gmail_label_projection_states')->where('message_id', 1)->value('status'))
            ->toBe('suppressed');
    });

    it('mantém as constraints de origem e conclusão da outbox após a migration de supersession', function () {
        $definitions = DB::select(<<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'gmail_label_projection_outbox'::regclass
  AND contype = 'c'
SQL);
        $definitions = array_map(static fn (object $row): string => $row->definition, $definitions);

        $hasOriginConstraint = collect($definitions)->contains(
            static fn (string $definition): bool => str_contains($definition, 'source_scope')
                && str_contains($definition, 'source_review_id'),
        );
        $hasSupersededCompletionConstraint = collect($definitions)->contains(
            static fn (string $definition): bool => str_contains($definition, 'completed_at')
                && str_contains($definition, 'superseded'),
        );
        $stateDefinitions = DB::select(<<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'gmail_label_projection_states'::regclass
  AND contype = 'c'
SQL);
        $hasSuppressedStateConstraint = collect($stateDefinitions)->contains(
            static fn (object $constraint): bool => str_contains((string) $constraint->definition, 'suppressed'),
        );

        expect($hasOriginConstraint)->toBeTrue()
            ->and($hasSupersededCompletionConstraint)->toBeTrue()
            ->and($hasSuppressedStateConstraint)->toBeTrue();
    });
});
