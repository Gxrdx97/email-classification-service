<?php

declare(strict_types=1);

namespace App\Domain\Classification\Repositories;

use App\Domain\Classification\Contracts\ClassificationReviewRepository;
use App\Domain\Classification\Contracts\EvidenceReferenceVerifier;
use App\Domain\Classification\Contracts\OfficialDecisionProjector;
use App\Domain\Classification\Data\ReviewDecision;
use App\Domain\Classification\Data\ReviewReceipt;
use App\Domain\Classification\Enums\OfficialOutcome;
use App\Domain\Classification\Enums\ReviewAction;
use App\Domain\Classification\Enums\ReviewScope;
use App\Domain\Classification\Exceptions\CategoryNotActive;
use App\Domain\Classification\Exceptions\InvalidReviewDecision;
use App\Domain\Classification\Exceptions\ReviewIdempotencyConflict;
use App\Domain\Classification\Exceptions\ReviewTargetNotFound;
use Illuminate\Support\Facades\DB;

final class PostgresClassificationReviewRepository implements ClassificationReviewRepository
{
    public function __construct(
        private readonly EvidenceReferenceVerifier $evidence,
        private readonly OfficialDecisionProjector $projector,
    ) {}

    public function record(ReviewDecision $decision): ReviewReceipt
    {
        $this->assertDecisionValid($decision);

        return DB::transaction(function () use ($decision): ReviewReceipt {
            $thread = DB::table('threads')->where('id', $decision->threadId)->lockForUpdate()->first();

            if ($thread === null) {
                throw new ReviewTargetNotFound('Thread não encontrada.');
            }

            $existing = DB::table('reviews')
                ->where('thread_id', $decision->threadId)
                ->where('idempotency_key', $decision->idempotencyKey)
                ->first();

            if ($existing !== null) {
                $receipt = $this->replayOrConflict($existing, $decision);
                $this->recordProjectionIntent($decision);

                return $receipt;
            }

            $this->assertTargetExists($decision);
            $categoryVersionIds = $this->currentVersionIdsFor($decision->categoryIds);

            $now = now();
            $reviewId = (int) DB::table('reviews')->insertGetId([
                'thread_id' => $decision->threadId,
                'target_scope' => $decision->scope->value,
                'target_message_id' => $decision->targetMessageId,
                'action' => $decision->action->value,
                'reviewer_subject' => $decision->reviewerSubject,
                'idempotency_key' => $decision->idempotencyKey,
                'reviewed_at' => $now,
                'created_at' => $now,
            ]);

            foreach ($categoryVersionIds as $categoryVersionId) {
                DB::table('review_category_versions')->insert([
                    'review_id' => $reviewId,
                    'category_version_id' => $categoryVersionId,
                    'created_at' => $now,
                ]);
            }

            $officialClassificationId = null;

            if ($decision->outcome !== null && $decision->scope === ReviewScope::Thread) {
                $officialClassificationId = $this->persistThreadDecision($reviewId, $decision, $categoryVersionIds, $now);
            } elseif ($decision->outcome !== null && $decision->scope === ReviewScope::Message) {
                $this->persistMessageOverride($reviewId, $decision, $now);
            }

            $this->recordProjectionIntent($decision);

            return new ReviewReceipt($reviewId, $decision->scope, $officialClassificationId, false);
        }, 3);
    }

    private function recordProjectionIntent(ReviewDecision $decision): void
    {
        if ($decision->outcome === null) {
            return;
        }

        if ($decision->scope === ReviewScope::Thread) {
            $this->projector->recordForThread($decision->threadId);

            return;
        }

        if ($decision->targetMessageId !== null) {
            $this->projector->recordForMessageOverride($decision->threadId, $decision->targetMessageId);
        }
    }

    private function assertDecisionValid(ReviewDecision $decision): void
    {
        if ($decision->threadId < 1
            || trim($decision->idempotencyKey) === ''
            || trim((string) $decision->reviewerSubject) === ''
            || ($decision->scope === ReviewScope::Thread && $decision->targetMessageId !== null)
            || ($decision->scope === ReviewScope::Message
                && ($decision->targetMessageId === null || $decision->targetMessageId < 1))
            || ! array_is_list($decision->categoryIds)
            || ! array_is_list($decision->evidenceRefs)
            || ! is_finite($decision->confidence)
            || $decision->confidence < 0
            || $decision->confidence > 1) {
            throw new InvalidReviewDecision('Revisão inválida para persistência.');
        }

        if ($decision->outcome === null) {
            if ($decision->action !== ReviewAction::RejectSuggestion
                || $decision->categoryIds !== []
                || $decision->evidenceRefs !== []
                || $decision->inconclusiveReason !== null
                || $decision->confidence !== 1.0) {
                throw new InvalidReviewDecision('Rejeição não pode criar resultado oficial.');
            }

            return;
        }

        $allowedActions = $decision->scope === ReviewScope::Thread
            ? [ReviewAction::ConfirmSuggestion, ReviewAction::Correct, ReviewAction::Manual]
            : [ReviewAction::Correct];

        if (! in_array($decision->action, $allowedActions, true)
            || ($decision->scope === ReviewScope::Message
                && ! in_array($decision->outcome, [OfficialOutcome::Categorized, OfficialOutcome::Uncategorized], true))
            || (($decision->outcome === OfficialOutcome::Categorized) !== ($decision->categoryIds !== []))
            || ($decision->scope === ReviewScope::Message && $decision->confidence !== 1.0)
            || (($decision->outcome === OfficialOutcome::Inconclusive) !== ($decision->inconclusiveReason !== null))) {
            throw new InvalidReviewDecision('Somente uma escolha humana válida pode criar decisão oficial.');
        }

        foreach ($decision->categoryIds as $categoryId) {
            if (! is_int($categoryId) || $categoryId < 1) {
                throw new InvalidReviewDecision('ID de Categoria inválido.');
            }
        }

        if (count($decision->categoryIds) !== count(array_unique($decision->categoryIds))) {
            throw new InvalidReviewDecision('Revisão contém Categorias duplicadas.');
        }

        foreach ($decision->evidenceRefs as $reference) {
            if (! is_string($reference)
                || trim($reference) === ''
                || ! $this->evidence->isVerifiedLocalReference($decision->threadId, $reference)) {
                throw new InvalidReviewDecision('Revisão contém Evidência local não verificada.');
            }
        }

        if (count($decision->evidenceRefs) !== count(array_unique($decision->evidenceRefs))) {
            throw new InvalidReviewDecision('Revisão contém Evidência duplicada.');
        }

        if ($decision->scope === ReviewScope::Message && $decision->evidenceRefs !== []) {
            throw new InvalidReviewDecision('Correção restrita à Message não aceita Evidência de Thread.');
        }
    }

    private function assertTargetExists(ReviewDecision $decision): void
    {
        if ($decision->scope !== ReviewScope::Message) {
            return;
        }

        $message = DB::table('messages')
            ->where('id', $decision->targetMessageId)
            ->where('thread_id', $decision->threadId)
            ->lockForUpdate()
            ->first();

        if ($message === null) {
            throw new ReviewTargetNotFound('Message não pertence à Thread informada.');
        }
    }

    /** @param list<int> $categoryIds
     * @return list<int>
     */
    private function currentVersionIdsFor(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = DB::table('categories')
            ->join('category_versions', function ($join): void {
                $join->on('category_versions.category_id', '=', 'categories.id')
                    ->on('category_versions.id', '=', 'categories.current_version_id');
            })
            ->whereIn('categories.id', $categoryIds)
            ->whereNull('categories.archived_at')
            ->lockForUpdate()
            ->orderBy('categories.id')
            ->select(['categories.id as category_id', 'category_versions.id as category_version_id'])
            ->get()
            ->all();

        $activeCategoryIds = array_map(static fn (object $row): int => (int) $row->category_id, $rows);
        $requestedCategoryIds = $categoryIds;
        sort($requestedCategoryIds);

        if ($activeCategoryIds !== $requestedCategoryIds) {
            throw new CategoryNotActive('A Revisão deve usar somente Categorias ativas.');
        }

        return array_map(static fn (object $row): int => (int) $row->category_version_id, $rows);
    }

    /** @param list<int> $categoryVersionIds */
    private function persistThreadDecision(int $reviewId, ReviewDecision $decision, array $categoryVersionIds, mixed $now): int
    {
        $outcome = $decision->outcome;

        // No persisted proposal registry exists yet; provider_name uses human_review as the known provenance.
        $classificationId = (int) DB::table('thread_classifications')->insertGetId([
            'thread_id' => $decision->threadId,
            'review_id' => $reviewId,
            'review_scope' => ReviewScope::Thread->value,
            'result_state' => $outcome->value,
            'confidence' => $decision->confidence,
            'inconclusive_reason' => $decision->inconclusiveReason?->value,
            'failure_code' => null,
            'provider_name' => 'human_review',
            'provider_model' => null,
            'category_catalog_version' => null,
            'evidence_refs' => json_encode($decision->evidenceRefs, JSON_THROW_ON_ERROR),
            'decision_author' => 'human_review',
            'classified_at' => $now,
            'created_at' => $now,
        ]);

        foreach ($categoryVersionIds as $categoryVersionId) {
            DB::table('thread_classification_categories')->insert([
                'thread_classification_id' => $classificationId,
                'category_version_id' => $categoryVersionId,
                'created_at' => $now,
            ]);
        }

        DB::table('threads')->where('id', $decision->threadId)->update([
            'operational_state' => $outcome->value,
            'latest_official_classification_id' => $classificationId,
            'updated_at' => $now,
        ]);

        return $classificationId;
    }

    private function persistMessageOverride(int $reviewId, ReviewDecision $decision, mixed $now): void
    {
        $messageId = $decision->targetMessageId;

        if ($messageId === null) {
            throw new ReviewTargetNotFound('Message da Revisão não informada.');
        }

        $this->assertMessageDecisionDiverges($decision);

        $attributes = [
            'message_id' => $messageId,
            'thread_id' => $decision->threadId,
            'review_id' => $reviewId,
            'review_scope' => ReviewScope::Message->value,
            'result_state' => $decision->outcome?->value,
            'updated_at' => $now,
        ];

        $existing = DB::table('message_category_overrides')
            ->where('message_id', $messageId)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            DB::table('message_category_overrides')->insert($attributes);

            return;
        }

        DB::table('message_category_overrides')->where('message_id', $messageId)->update($attributes);
    }

    private function assertMessageDecisionDiverges(ReviewDecision $decision): void
    {
        $thread = DB::table('threads')->where('id', $decision->threadId)->first();
        $officialClassificationId = $thread?->latest_official_classification_id;

        if ($officialClassificationId === null) {
            throw new InvalidReviewDecision('Correção de Message exige uma decisão oficial da Thread para registrar divergência.');
        }

        $official = DB::table('thread_classifications')
            ->where('id', $officialClassificationId)
            ->where('thread_id', $decision->threadId)
            ->first();

        if ($official === null) {
            throw new ReviewTargetNotFound('Classificação oficial da Thread não encontrada.');
        }

        $threadCategoryIds = DB::table('thread_classification_categories')
            ->join('category_versions', 'category_versions.id', '=', 'thread_classification_categories.category_version_id')
            ->where('thread_classification_id', $official->id)
            ->orderBy('category_versions.category_id')
            ->pluck('category_versions.category_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $messageCategoryIds = $decision->categoryIds;
        sort($messageCategoryIds);

        if ($official->result_state === $decision->outcome?->value
            && $threadCategoryIds === $messageCategoryIds) {
            throw new InvalidReviewDecision('A correção de Message deve divergir da decisão oficial da Thread.');
        }
    }

    private function replayOrConflict(object $review, ReviewDecision $decision): ReviewReceipt
    {
        if ($review->target_scope !== $decision->scope->value
            || (int) $review->target_message_id !== (int) $decision->targetMessageId
            || $review->action !== $decision->action->value
            || $review->reviewer_subject !== $decision->reviewerSubject) {
            throw new ReviewIdempotencyConflict('Chave idempotente reutilizada para outra Revisão.');
        }

        $storedCategoryIds = DB::table('review_category_versions')
            ->join('category_versions', 'category_versions.id', '=', 'review_category_versions.category_version_id')
            ->where('review_id', $review->id)
            ->orderBy('category_versions.category_id')
            ->pluck('category_versions.category_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $requestedCategoryIds = $decision->categoryIds;
        sort($requestedCategoryIds);

        if ($storedCategoryIds !== $requestedCategoryIds) {
            throw new ReviewIdempotencyConflict('Chave idempotente reutilizada com Categorias diferentes.');
        }

        $classification = DB::table('thread_classifications')->where('review_id', $review->id)->first();

        if ($decision->scope === ReviewScope::Thread) {
            if (($classification === null) !== ($decision->outcome === null)) {
                throw new ReviewIdempotencyConflict('Chave idempotente reutilizada com outro desfecho.');
            }

            if ($classification !== null && ! $this->classificationMatches($classification, $decision)) {
                throw new ReviewIdempotencyConflict('Chave idempotente reutilizada com outra decisão.');
            }
        } elseif ($decision->outcome !== null) {
            $savedOutcome = $storedCategoryIds === [] ? 'uncategorized' : 'categorized';

            if ($savedOutcome !== $decision->outcome->value) {
                throw new ReviewIdempotencyConflict('Chave idempotente reutilizada com outro desfecho de Message.');
            }
        }

        return new ReviewReceipt(
            (int) $review->id,
            $decision->scope,
            $classification === null ? null : (int) $classification->id,
            true,
        );
    }

    private function classificationMatches(object $classification, ReviewDecision $decision): bool
    {
        $storedEvidence = json_decode((string) $classification->evidence_refs, true);

        return $classification->result_state === $decision->outcome?->value
            && (float) $classification->confidence === $decision->confidence
            && $classification->inconclusive_reason === $decision->inconclusiveReason?->value
            && $classification->provider_name === 'human_review'
            && $classification->provider_model === null
            && $classification->category_catalog_version === null
            && $storedEvidence === $decision->evidenceRefs;
    }
}
