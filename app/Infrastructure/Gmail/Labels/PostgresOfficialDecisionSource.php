<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels;

use App\Domain\GmailLabels\Contracts\OfficialDecisionSource;
use App\Domain\GmailLabels\Data\OfficialDecision;
use App\Domain\GmailLabels\Data\ProjectionMessage;
use App\Domain\GmailLabels\Data\ThreadProjectionSnapshot;
use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class PostgresOfficialDecisionSource implements OfficialDecisionSource
{
    public function threadSnapshot(int $threadId): ?ThreadProjectionSnapshot
    {
        $thread = DB::table('threads')
            ->where('id', $threadId)
            ->first(['id', 'account_id', 'operational_state', 'latest_official_classification_id']);

        if ($thread === null || $thread->latest_official_classification_id === null) {
            return null;
        }

        $classification = DB::table('thread_classifications')
            ->where('id', $thread->latest_official_classification_id)
            ->where('thread_id', $threadId)
            ->where('decision_author', 'human_review')
            ->first(['id', 'review_id', 'result_state', 'decision_author']);

        if ($classification === null) {
            return null;
        }

        $threadDecision = new OfficialDecision(
            decisionId: (int) $classification->id,
            reviewId: (int) $classification->review_id,
            threadId: $threadId,
            scope: DecisionScope::THREAD,
            targetMessageId: null,
            state: OfficialDecisionState::from((string) $classification->result_state),
            categoryIds: $this->threadDecisionCategoryIds((int) $classification->id),
            decisionAuthor: (string) $classification->decision_author,
        );

        $messages = DB::table('messages')
            ->where('thread_id', $threadId)
            ->orderBy('id')
            ->get(['id', 'account_id', 'thread_id', 'gmail_message_id']);

        $messageOverrides = $this->messageOverrides($threadId);
        $projectionMessages = [];

        foreach ($messages as $message) {
            $messageId = (int) $message->id;
            $projectionMessages[] = new ProjectionMessage(
                accountId: (int) $message->account_id,
                messageId: $messageId,
                threadId: (int) $message->thread_id,
                gmailMessageId: (string) $message->gmail_message_id,
                messageOverride: $messageOverrides[$messageId] ?? null,
            );
        }

        return new ThreadProjectionSnapshot(
            accountId: (int) $thread->account_id,
            threadId: (int) $thread->id,
            operationalState: (string) $thread->operational_state,
            latestOfficialThreadDecision: $threadDecision,
            messages: $projectionMessages,
        );
    }

    /** @return list<int> */
    private function threadDecisionCategoryIds(int $classificationId): array
    {
        return DB::table('thread_classification_categories')
            ->join('category_versions', 'category_versions.id', '=', 'thread_classification_categories.category_version_id')
            ->where('thread_classification_categories.thread_classification_id', $classificationId)
            ->orderBy('category_versions.category_id')
            ->pluck('category_versions.category_id')
            ->map(static fn (mixed $categoryId): int => (int) $categoryId)
            ->all();
    }

    /** @return array<int, OfficialDecision> keyed by Message ID */
    private function messageOverrides(int $threadId): array
    {
        $rows = DB::table('message_category_overrides as message_overrides')
            ->join('reviews', function (JoinClause $join): void {
                $join->on('reviews.id', '=', 'message_overrides.review_id')
                    ->on('reviews.thread_id', '=', 'message_overrides.thread_id')
                    ->on('reviews.target_message_id', '=', 'message_overrides.message_id')
                    ->where('reviews.target_scope', 'message');
            })
            ->leftJoin('review_category_versions', 'review_category_versions.review_id', '=', 'reviews.id')
            ->leftJoin('category_versions', 'category_versions.id', '=', 'review_category_versions.category_version_id')
            ->where('message_overrides.thread_id', $threadId)
            ->orderBy('message_overrides.message_id')
            ->orderBy('category_versions.category_id')
            ->get([
                'message_overrides.message_id',
                'reviews.id as review_id',
                'message_overrides.result_state',
                'category_versions.category_id',
            ]);

        $overrides = [];

        foreach ($rows as $row) {
            $messageId = (int) $row->message_id;
            $reviewId = (int) $row->review_id;

            $overrides[$messageId] ??= [
                'review_id' => $reviewId,
                'result_state' => (string) $row->result_state,
                'category_ids' => [],
            ];

            if ($row->category_id !== null) {
                $overrides[$messageId]['category_ids'][] = (int) $row->category_id;
            }
        }

        $decisions = [];

        foreach ($overrides as $messageId => $override) {
            $decisions[$messageId] = new OfficialDecision(
                decisionId: $override['review_id'],
                reviewId: $override['review_id'],
                threadId: $threadId,
                scope: DecisionScope::MESSAGE,
                targetMessageId: $messageId,
                state: OfficialDecisionState::from($override['result_state']),
                categoryIds: $override['category_ids'],
                decisionAuthor: 'human_review',
            );
        }

        return $decisions;
    }
}
