<?php

declare(strict_types=1);

namespace App\Infrastructure\Gmail\Labels;

use App\Domain\GmailLabels\Contracts\CategoryLabelMappingRepository;
use App\Domain\GmailLabels\Contracts\ProjectionOutboxRepository;
use App\Domain\GmailLabels\Data\CategoryLabelMapping;
use App\Domain\GmailLabels\Data\DesiredMessageLabelProjection;
use App\Domain\GmailLabels\Data\OfficialDecision;
use App\Domain\GmailLabels\Data\ProjectionIntent;
use App\Domain\GmailLabels\Data\ProjectionOutboxReceipt;
use App\Domain\GmailLabels\Enums\DecisionScope;
use App\Domain\GmailLabels\Enums\OfficialDecisionState;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresGmailLabelProjectionRepository implements CategoryLabelMappingRepository, ProjectionOutboxRepository
{
    public function find(int $categoryId): ?CategoryLabelMapping
    {
        $row = DB::table('categories')
            ->select(['id', 'gmail_label_id', 'gmail_label_name'])
            ->where('id', $categoryId)
            ->whereNotNull('gmail_label_id')
            ->first();

        return $row === null ? null : $this->mappingFromRow($row);
    }

    public function displayName(int $categoryId): ?string
    {
        $name = DB::table('categories')
            ->join('category_versions', 'category_versions.id', '=', 'categories.current_version_id')
            ->where('categories.id', $categoryId)
            ->value('category_versions.name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function saveIfMissing(int $categoryId, string $displayName, string $providerLabelId): CategoryLabelMapping
    {
        $existing = $this->find($categoryId);

        if ($existing !== null) {
            return $existing;
        }

        DB::table('categories')
            ->where('id', $categoryId)
            ->whereNull('gmail_label_id')
            ->update([
                'gmail_label_id' => $providerLabelId,
                'gmail_label_name' => $displayName,
                'updated_at' => now(),
            ]);

        return $this->find($categoryId)
            ?? throw new RuntimeException('Category Label mapping could not be persisted.');
    }

    /** @return list<CategoryLabelMapping> */
    public function allManaged(): array
    {
        return DB::table('categories')
            ->select(['id', 'gmail_label_id', 'gmail_label_name'])
            ->whereNotNull('gmail_label_id')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): CategoryLabelMapping => $this->mappingFromRow($row))
            ->all();
    }

    public function record(DesiredMessageLabelProjection $projection, string $idempotencyKey): ProjectionOutboxReceipt
    {
        if (preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('Projection idempotency key is invalid.');
        }

        $desiredHash = hash('sha256', json_encode([
            'state' => $projection->decision->state->value,
            'category_ids' => $projection->categoryIds,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($projection, $idempotencyKey, $desiredHash): ProjectionOutboxReceipt {
            $message = DB::table('messages')
                ->where('id', $projection->messageId)
                ->lockForUpdate()
                ->first(['id', 'account_id', 'thread_id']);

            if (
                $message === null
                || (int) $message->account_id !== $projection->accountId
                || (int) $message->thread_id !== $projection->threadId
            ) {
                throw new RuntimeException('Projection Message scope is unavailable.');
            }

            $existing = DB::table('gmail_label_projection_outbox')
                ->where('account_id', $projection->accountId)
                ->where('idempotency_key', $idempotencyKey)
                ->first(['id', 'message_id']);

            if ($existing !== null) {
                if ((int) $existing->message_id !== $projection->messageId) {
                    throw new RuntimeException('Projection idempotency key is already bound to another Message.');
                }

                return new ProjectionOutboxReceipt((int) $existing->id, (int) $existing->message_id);
            }

            $state = DB::table('gmail_label_projection_states')
                ->where('message_id', $projection->messageId)
                ->lockForUpdate()
                ->first(['desired_generation']);
            $generation = ($state === null ? 0 : (int) $state->desired_generation) + 1;
            $decision = $projection->decision;
            $source = [
                'source_scope' => $decision->scope->value,
                'source_classification_id' => $decision->scope === DecisionScope::THREAD ? $decision->decisionId : null,
                'source_review_id' => $decision->scope === DecisionScope::MESSAGE ? $decision->reviewId : null,
                'source_review_target_message_id' => $decision->scope === DecisionScope::MESSAGE ? $projection->messageId : null,
            ];
            $timestamp = now();
            $outboxId = (int) DB::table('gmail_label_projection_outbox')->insertGetId([
                'account_id' => $projection->accountId,
                'message_id' => $projection->messageId,
                'thread_id' => $projection->threadId,
                'generation' => $generation,
                'idempotency_key' => $idempotencyKey,
                'desired_state' => $decision->state->value,
                'desired_hash' => $desiredHash,
                ...$source,
                'status' => 'pending',
                'attempt_count' => 0,
                'available_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], 'id');

            foreach ($projection->categoryIds as $categoryId) {
                DB::table('gmail_label_projection_outbox_categories')->insert([
                    'outbox_id' => $outboxId,
                    'category_id' => $categoryId,
                ]);
            }

            DB::table('gmail_label_projection_states')->updateOrInsert(
                ['message_id' => $projection->messageId],
                [
                    'thread_id' => $projection->threadId,
                    'desired_generation' => $generation,
                    'desired_state' => $decision->state->value,
                    'desired_hash' => $desiredHash,
                    'applied_hash' => null,
                    ...$source,
                    'status' => 'pending',
                    'attempt_count' => 0,
                    'last_error_code' => null,
                    'last_attempt_at' => null,
                    'applied_at' => null,
                    'updated_at' => $timestamp,
                ],
            );

            DB::table('message_category_label_assignments')
                ->where('message_id', $projection->messageId)
                ->delete();

            foreach ($projection->categoryIds as $categoryId) {
                DB::table('message_category_label_assignments')->insert([
                    'message_id' => $projection->messageId,
                    'thread_id' => $projection->threadId,
                    'category_id' => $categoryId,
                    ...$source,
                    'assigned_at' => $timestamp,
                ]);
            }

            return new ProjectionOutboxReceipt($outboxId, $projection->messageId);
        });
    }

    public function claim(int $outboxId): ?ProjectionIntent
    {
        return DB::transaction(function () use ($outboxId): ?ProjectionIntent {
            $row = DB::table('gmail_label_projection_outbox')
                ->where('id', $outboxId)
                ->lockForUpdate()
                ->first();

            if ($row === null || in_array($row->status, ['succeeded', 'dead', 'superseded'], true)) {
                return null;
            }

            $timestamp = now();

            $state = DB::table('gmail_label_projection_states')
                ->where('message_id', $row->message_id)
                ->lockForUpdate()
                ->first(['desired_generation']);

            if ($state !== null && (int) $state->desired_generation > (int) $row->generation) {
                DB::table('gmail_label_projection_outbox')
                    ->where('id', $outboxId)
                    ->update([
                        'status' => 'superseded',
                        'completed_at' => $timestamp,
                        'leased_at' => null,
                        'last_error_code' => 'stale_projection_generation',
                        'updated_at' => $timestamp,
                    ]);

                return null;
            }

            if (
                $row->status === 'processing'
                && $row->leased_at !== null
                && now()->subSeconds(120)->lessThan($row->leased_at)
            ) {
                return null;
            }

            if (now()->lessThan($row->available_at)) {
                return null;
            }

            $attemptCount = (int) $row->attempt_count + 1;
            DB::table('gmail_label_projection_outbox')
                ->where('id', $outboxId)
                ->update([
                    'status' => 'processing',
                    'attempt_count' => $attemptCount,
                    'leased_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

            return $this->hydrateIntent($outboxId, $attemptCount);
        });
    }

    public function supersede(ProjectionIntent $intent, string $reasonCode): void
    {
        $safeCode = $this->safeErrorCode($reasonCode, 'projection_superseded');
        $timestamp = now();

        DB::transaction(function () use ($intent, $safeCode, $timestamp): void {
            $updated = DB::table('gmail_label_projection_outbox')
                ->where('id', $intent->outboxId)
                ->where('generation', $intent->generation)
                ->whereIn('status', ['pending', 'processing', 'retryable'])
                ->update([
                    'status' => 'superseded',
                    'completed_at' => $timestamp,
                    'leased_at' => null,
                    'last_error_code' => $safeCode,
                    'updated_at' => $timestamp,
                ]);

            if ($updated > 0) {
                $this->suppressStateIfCurrent($intent->desired->messageId, $intent->generation, $safeCode, $timestamp);
            }
        });
    }

    public function supersedeActiveForMessage(int $messageId, string $reasonCode): void
    {
        if ($messageId < 1) {
            throw new RuntimeException('Projection Message identifier is invalid.');
        }

        $safeCode = $this->safeErrorCode($reasonCode, 'projection_superseded');
        $timestamp = now();

        DB::transaction(function () use ($messageId, $safeCode, $timestamp): void {
            $activeRows = DB::table('gmail_label_projection_outbox')
                ->where('message_id', $messageId)
                ->whereIn('status', ['pending', 'processing', 'retryable'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'generation']);

            if ($activeRows->isEmpty()) {
                return;
            }

            DB::table('gmail_label_projection_outbox')
                ->whereIn('id', $activeRows->pluck('id'))
                ->whereIn('status', ['pending', 'processing', 'retryable'])
                ->update([
                    'status' => 'superseded',
                    'completed_at' => $timestamp,
                    'leased_at' => null,
                    'last_error_code' => $safeCode,
                    'updated_at' => $timestamp,
                ]);

            $state = DB::table('gmail_label_projection_states')
                ->where('message_id', $messageId)
                ->lockForUpdate()
                ->first(['desired_generation']);

            if ($state !== null && $activeRows->contains(
                static fn (object $row): bool => (int) $row->generation === (int) $state->desired_generation,
            )) {
                $this->suppressStateIfCurrent(
                    $messageId,
                    (int) $state->desired_generation,
                    $safeCode,
                    $timestamp,
                );
            }
        });
    }

    public function markApplied(ProjectionIntent $intent): void
    {
        DB::transaction(function () use ($intent): void {
            $timestamp = now();
            $updated = DB::table('gmail_label_projection_outbox')
                ->where('id', $intent->outboxId)
                ->where('generation', $intent->generation)
                ->where('status', 'processing')
                ->update([
                    'status' => 'succeeded',
                    'completed_at' => $timestamp,
                    'leased_at' => null,
                    'last_error_code' => null,
                    'updated_at' => $timestamp,
                ]);

            if ($updated === 0) {
                return;
            }

            DB::table('gmail_label_projection_states')
                ->where('message_id', $intent->desired->messageId)
                ->where('desired_generation', $intent->generation)
                ->update([
                    'applied_hash' => $intent->desiredHash,
                    'status' => 'applied',
                    'attempt_count' => $intent->attemptCount,
                    'last_error_code' => null,
                    'last_attempt_at' => $timestamp,
                    'applied_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        });
    }

    public function markRetryable(ProjectionIntent $intent, string $errorCode, DateTimeImmutable $availableAt): void
    {
        $this->updateFailure(
            $intent->outboxId,
            $intent->desired->messageId,
            $intent->generation,
            $errorCode,
            'retryable',
            $availableAt,
            ['processing'],
        );
    }

    public function markDead(int $outboxId, string $errorCode): void
    {
        $row = DB::table('gmail_label_projection_outbox')
            ->where('id', $outboxId)
            ->first(['message_id', 'generation']);

        if ($row === null) {
            return;
        }

        $this->updateFailure(
            $outboxId,
            (int) $row->message_id,
            (int) $row->generation,
            $errorCode,
            'dead',
            new DateTimeImmutable,
            ['pending', 'processing', 'retryable'],
        );
    }

    private function updateFailure(
        int $outboxId,
        int $messageId,
        int $generation,
        string $errorCode,
        string $outboxStatus,
        DateTimeImmutable $availableAt,
        array $eligibleStatuses,
    ): void {
        $safeCode = $this->safeErrorCode($errorCode, 'projection_failed');
        $timestamp = now();

        DB::transaction(function () use ($outboxId, $messageId, $generation, $safeCode, $outboxStatus, $availableAt, $timestamp, $eligibleStatuses): void {
            $updated = DB::table('gmail_label_projection_outbox')
                ->where('id', $outboxId)
                ->where('generation', $generation)
                ->whereIn('status', $eligibleStatuses)
                ->update([
                    'status' => $outboxStatus,
                    'available_at' => $availableAt->format(DATE_ATOM),
                    'completed_at' => $outboxStatus === 'dead' ? $timestamp : null,
                    'leased_at' => null,
                    'last_error_code' => $safeCode,
                    'updated_at' => $timestamp,
                ]);

            if ($updated === 0) {
                return;
            }

            DB::table('gmail_label_projection_states')
                ->where('message_id', $messageId)
                ->where('desired_generation', $generation)
                ->update([
                    'status' => $outboxStatus === 'dead' ? 'failed' : 'retryable',
                    'last_error_code' => $safeCode,
                    'last_attempt_at' => $timestamp,
                    'attempt_count' => DB::raw('attempt_count + 1'),
                    'updated_at' => $timestamp,
                ]);
        });
    }

    private function suppressStateIfCurrent(
        int $messageId,
        int $generation,
        string $reasonCode,
        mixed $timestamp,
    ): void {
        $state = DB::table('gmail_label_projection_states')
            ->where('message_id', $messageId)
            ->where('desired_generation', $generation)
            ->lockForUpdate()
            ->first(['desired_hash', 'applied_hash']);

        if ($state === null) {
            return;
        }

        $alreadyApplied = $state->applied_hash !== null
            && hash_equals(trim((string) $state->desired_hash), trim((string) $state->applied_hash));

        DB::table('gmail_label_projection_states')
            ->where('message_id', $messageId)
            ->where('desired_generation', $generation)
            ->update([
                'status' => $alreadyApplied ? 'applied' : 'suppressed',
                'last_error_code' => $alreadyApplied ? null : $reasonCode,
                'updated_at' => $timestamp,
            ]);
    }

    private function safeErrorCode(string $errorCode, string $fallback): string
    {
        return preg_match('/^[a-z0-9_]{1,64}$/', $errorCode) === 1 ? $errorCode : $fallback;
    }

    private function hydrateIntent(int $outboxId, int $attemptCount): ProjectionIntent
    {
        $row = DB::table('gmail_label_projection_outbox')->where('id', $outboxId)->first();

        if ($row === null) {
            throw new RuntimeException('Projection outbox intent disappeared.');
        }

        $message = DB::table('messages')
            ->where('id', $row->message_id)
            ->first(['id', 'account_id', 'thread_id', 'gmail_message_id']);
        $categoryIds = DB::table('gmail_label_projection_outbox_categories')
            ->where('outbox_id', $outboxId)
            ->orderBy('category_id')
            ->pluck('category_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($message === null) {
            throw new RuntimeException('Projection Message no longer exists.');
        }

        $scope = DecisionScope::from($row->source_scope);
        $state = OfficialDecisionState::from($row->desired_state);
        $decisionId = $scope === DecisionScope::THREAD
            ? (int) $row->source_classification_id
            : (int) $row->source_review_id;
        $reviewId = $scope === DecisionScope::MESSAGE ? (int) $row->source_review_id : null;
        $targetMessageId = $scope === DecisionScope::MESSAGE ? (int) $row->source_review_target_message_id : null;
        $decision = new OfficialDecision(
            decisionId: $decisionId,
            reviewId: $reviewId,
            threadId: (int) $row->thread_id,
            scope: $scope,
            targetMessageId: $targetMessageId,
            state: $state,
            categoryIds: $categoryIds,
            decisionAuthor: 'human_review',
        );
        $projection = new DesiredMessageLabelProjection(
            accountId: (int) $message->account_id,
            messageId: (int) $message->id,
            threadId: (int) $message->thread_id,
            gmailMessageId: (string) $message->gmail_message_id,
            decision: $decision,
            categoryIds: $categoryIds,
        );

        return new ProjectionIntent(
            outboxId: (int) $row->id,
            generation: (int) $row->generation,
            desired: $projection,
            idempotencyKey: (string) $row->idempotency_key,
            desiredHash: trim((string) $row->desired_hash),
            attemptCount: $attemptCount,
        );
    }

    private function mappingFromRow(object $row): CategoryLabelMapping
    {
        return new CategoryLabelMapping(
            categoryId: (int) $row->id,
            displayName: (string) $row->gmail_label_name,
            providerLabelId: (string) $row->gmail_label_id,
        );
    }
}
