<?php

declare(strict_types=1);

namespace App\Infrastructure\Capture;

use App\Domain\Capture\Contracts\CaptureStore;
use App\Domain\Capture\Data\AttachmentMetadata;
use App\Domain\Capture\Data\CaptureBatch;
use App\Domain\Capture\Data\CaptureCommit;
use App\Domain\Capture\Data\CapturedMessage;
use App\Domain\Capture\Data\SyncMode;
use App\Domain\Capture\Exceptions\CaptureFailure;
use App\Domain\Classification\Enums\OperationalState;
use App\Domain\PreAnalysis\Data\PreAnalysisDispatchPolicy;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Infrastructure\PreAnalysis\PreAnalysisDispatchIntentWriter;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PostgresCaptureStore implements CaptureStore
{
    public function __construct(
        private readonly PreAnalysisDispatchIntentWriter $dispatchIntentWriter = new PreAnalysisDispatchIntentWriter,
    ) {}

    /**
     * @var list<string>
     */
    private const SYSTEM_LABEL_IDS = [
        'INBOX',
        'UNREAD',
        'STARRED',
        'IMPORTANT',
        'SENT',
        'DRAFT',
        'SPAM',
        'TRASH',
        'CATEGORY_PERSONAL',
        'CATEGORY_SOCIAL',
        'CATEGORY_PROMOTIONS',
        'CATEGORY_UPDATES',
        'CATEGORY_FORUMS',
    ];

    public function commitBatch(CaptureBatch $batch): CaptureCommit
    {
        if ($batch->accountId !== 1) {
            throw new CaptureFailure('A Captura aceita somente a conta Gmail configurada.');
        }

        try {
            return DB::transaction(function () use ($batch): CaptureCommit {
                if (! DB::table('gmail_accounts')->where('id', $batch->accountId)->exists()) {
                    throw new CaptureFailure('A conta Gmail não está persistida.');
                }

                DB::table('sync_state')->insertOrIgnore([
                    'account_id' => $batch->accountId,
                ]);

                $syncState = DB::table('sync_state')
                    ->where('account_id', $batch->accountId)
                    ->lockForUpdate()
                    ->first(['history_id']);

                if ($syncState === null || $syncState->history_id !== $batch->expectedCursor) {
                    throw new CaptureFailure('O Cursor da Captura foi alterado por outra sincronização.');
                }

                if ($batch->cursorAfter !== null) {
                    $this->assertCursorDoesNotGoBack($batch->expectedCursor, $batch->cursorAfter);
                }

                $now = now();
                $newMessages = 0;

                foreach ($batch->messages as $capturedMessage) {
                    if ($this->persistCapturedMessage($batch->accountId, $capturedMessage, $now)) {
                        $newMessages++;
                    }
                }

                $threadIds = [];
                foreach ($batch->messages as $capturedMessage) {
                    $providerThreadId = $capturedMessage->canonical->providerThreadId;
                    $threadIds['thread:'.$providerThreadId] = $providerThreadId;
                }
                foreach ($batch->touchedThreadIds as $providerThreadId) {
                    $threadIds['thread:'.$providerThreadId] = $providerThreadId;
                }

                $threadSnapshots = $this->refreshThreadSnapshots($batch->accountId, $threadIds, $now);

                if ($batch->cursorAfter !== null) {
                    $this->advanceCursor($batch, $now);
                    $this->writeFinalPreAnalysisIntents($batch, $threadSnapshots);
                }

                return new CaptureCommit(
                    committedCursor: $batch->cursorAfter ?? $batch->expectedCursor,
                    capturedMessages: count($batch->messages),
                    newMessages: $newMessages,
                );
            }, attempts: 3);
        } catch (QueryException) {
            throw new CaptureFailure('A transação da Captura não pôde ser persistida.');
        }
    }

    private function persistCapturedMessage(int $accountId, CapturedMessage $capturedMessage, DateTimeInterface $now): bool
    {
        $canonical = $capturedMessage->canonical;
        $thread = $this->lockThread($accountId, $canonical->providerThreadId, $now);
        $messageDate = $canonical->providerInternalDate?->format('Y-m-d H:i:s.uP');

        $threadChanges = ['updated_at' => $now];
        if ($messageDate !== null && $this->isLater($messageDate, $thread->last_message_at)) {
            $threadChanges['last_message_at'] = $messageDate;
        }
        DB::table('threads')->where('id', $thread->id)->update($threadChanges);

        $inserted = DB::table('messages')->insertOrIgnore([
            'account_id' => $accountId,
            'thread_id' => $thread->id,
            'gmail_message_id' => $canonical->providerMessageId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $message = DB::table('messages')
            ->where('account_id', $accountId)
            ->where('gmail_message_id', $canonical->providerMessageId)
            ->lockForUpdate()
            ->first(['id', 'thread_id']);

        if ($message === null || (int) $message->thread_id !== (int) $thread->id) {
            throw new CaptureFailure('A Message capturada não corresponde à Thread persistida.');
        }

        DB::table('messages')->where('id', $message->id)->update([
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);

        $isNewMessage = $inserted > 0;
        if ($isNewMessage) {
            DB::table('threads')->where('id', $thread->id)->update([
                'operational_state' => OperationalState::Pending->value,
                'updated_at' => $now,
            ]);
        }

        $versionId = $this->persistVersion((int) $message->id, $capturedMessage, $now);
        $this->persistAttachments($versionId, $canonical->attachments, $now);
        $this->persistEnrichment($versionId, $capturedMessage, $now);
        $this->replaceLabels((int) $message->id, $canonical->labelIds, $now);

        return $isNewMessage;
    }

    private function lockThread(int $accountId, string $providerThreadId, DateTimeInterface $now): object
    {
        DB::table('threads')->insertOrIgnore([
            'account_id' => $accountId,
            'gmail_thread_id' => $providerThreadId,
            'operational_state' => OperationalState::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $thread = DB::table('threads')
            ->where('account_id', $accountId)
            ->where('gmail_thread_id', $providerThreadId)
            ->lockForUpdate()
            ->first(['id', 'last_message_at']);

        if ($thread === null) {
            throw new CaptureFailure('A Thread da Captura não pôde ser persistida.');
        }

        return $thread;
    }

    /**
     * Recalcula snapshots após persistir as páginas. IDs do provider resolvem Threads locais;
     * Labels e registros de anexos ficam fora do snapshot, que inclui content_hash como digest opaco.
     *
     * @param  array<string, string>  $providerThreadIds
     * @return array<string, array{thread_id: int, hash: string}>
     */
    private function refreshThreadSnapshots(int $accountId, array $providerThreadIds, DateTimeInterface $now): array
    {
        if ($providerThreadIds === []) {
            return [];
        }

        ksort($providerThreadIds, SORT_STRING);
        $snapshots = [];
        foreach (array_chunk($providerThreadIds, 500, true) as $threadIdChunk) {
            $snapshots += $this->refreshThreadSnapshotChunk($accountId, $threadIdChunk, $now);
        }

        return $snapshots;
    }

    /**
     * @param  array<string, string>  $providerThreadIds
     * @return array<string, array{thread_id: int, hash: string}>
     */
    private function refreshThreadSnapshotChunk(int $accountId, array $providerThreadIds, DateTimeInterface $now): array
    {
        $threads = DB::table('threads')
            ->where('account_id', $accountId)
            ->whereIn('gmail_thread_id', array_values($providerThreadIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'gmail_thread_id']);

        if ($threads->isEmpty()) {
            return [];
        }

        $threadIdsByKey = [];
        $databaseThreadIds = [];
        foreach ($threads as $thread) {
            $key = 'thread:'.(string) $thread->gmail_thread_id;
            $threadId = (int) $thread->id;
            $threadIdsByKey[$key] = $threadId;
            $databaseThreadIds[] = $threadId;
        }

        $messageCounts = DB::table('messages')
            ->where('account_id', $accountId)
            ->whereIn('thread_id', $databaseThreadIds)
            ->select('thread_id')
            ->selectRaw('COUNT(*) AS message_count')
            ->groupBy('thread_id')
            ->pluck('message_count', 'thread_id')
            ->all();

        $threadPlaceholders = implode(', ', array_fill(0, count($databaseThreadIds), '?'));
        $rows = DB::select(<<<SQL
SELECT message.thread_id,
       message.id AS message_id,
       latest_version.content_hash,
       latest_version.provider_internal_date,
       enrichment.extractor_name,
       enrichment.extractor_version,
       enrichment.normalized_text,
       enrichment.language_code,
       enrichment.direction,
       enrichment.thread_position,
       enrichment.deterministic_facts::text AS deterministic_facts,
       participant.normalized_email,
       participant.display_name,
       participant.role
FROM messages AS message
JOIN LATERAL (
    SELECT version.id, version.content_hash, version.provider_internal_date
    FROM message_versions AS version
    WHERE version.message_id = message.id
    ORDER BY version.version_number DESC
    LIMIT 1
) AS latest_version ON TRUE
LEFT JOIN LATERAL (
    SELECT candidate.id,
           candidate.extractor_name,
           candidate.extractor_version,
           candidate.normalized_text,
           candidate.language_code,
           candidate.direction,
           candidate.thread_position,
           candidate.deterministic_facts
    FROM message_enrichments AS candidate
    WHERE candidate.message_version_id = latest_version.id
    ORDER BY candidate.created_at DESC, candidate.id DESC
    LIMIT 1
) AS enrichment ON TRUE
LEFT JOIN enrichment_participants AS participant
    ON participant.enrichment_id = enrichment.id
WHERE message.account_id = ?
  AND message.thread_id IN ($threadPlaceholders)
ORDER BY message.thread_id,
         message.id,
         participant.role NULLS FIRST,
         participant.normalized_email NULLS FIRST,
         participant.display_name NULLS FIRST
SQL, [$accountId, ...$databaseThreadIds]);

        $rowsByThread = [];
        foreach ($rows as $row) {
            $rowsByThread[(int) $row->thread_id][] = $row;
        }

        $snapshots = [];
        $snapshotHashes = [];
        foreach ($threadIdsByKey as $key => $threadId) {
            $messageCount = (int) ($messageCounts[$threadId] ?? 0);
            $snapshotHash = $messageCount === 0
                ? null
                : $this->threadSnapshotHashFromRows($rowsByThread[$threadId] ?? [], $messageCount);
            $snapshotHashes[$threadId] = $snapshotHash;

            if ($snapshotHash !== null) {
                $snapshots[$key] = ['thread_id' => $threadId, 'hash' => $snapshotHash];
            }
        }

        $caseBindings = [];
        $caseClauses = [];
        foreach ($snapshotHashes as $threadId => $snapshotHash) {
            $caseClauses[] = 'WHEN ? THEN CAST(? AS char(64))';
            $caseBindings[] = $threadId;
            $caseBindings[] = $snapshotHash;
        }
        $idPlaceholders = implode(', ', array_fill(0, count($snapshotHashes), '?'));
        DB::update(
            'UPDATE threads SET thread_snapshot_hash = CASE id '.implode(' ', $caseClauses)
                .' END, updated_at = ? WHERE id IN ('.$idPlaceholders.')',
            [...$caseBindings, $now, ...array_keys($snapshotHashes)],
        );

        return $snapshots;
    }

    /** @param list<object> $rows */
    private function threadSnapshotHashFromRows(array $rows, int $messageCount): string
    {
        $messages = [];
        foreach ($rows as $row) {
            $messageKey = (string) $row->message_id;
            if (! isset($messages[$messageKey])) {
                $messages[$messageKey] = [
                    'content_hash' => rtrim((string) $row->content_hash),
                    'provider_internal_date' => $row->provider_internal_date === null
                        ? null
                        : (string) $row->provider_internal_date,
                    'enrichments' => [],
                ];
            }

            if ($row->extractor_name === null) {
                continue;
            }

            $extractorName = (string) $row->extractor_name;
            $extractorVersion = (string) $row->extractor_version;
            $enrichmentKey = 'enrichment:'.hash('sha256', $extractorName."\0".$extractorVersion);
            if (! isset($messages[$messageKey]['enrichments'][$enrichmentKey])) {
                $facts = json_decode((string) $row->deterministic_facts, flags: JSON_THROW_ON_ERROR);
                if (! $facts instanceof stdClass) {
                    throw new CaptureFailure('O enriquecimento da Versão da mensagem é inválido.');
                }

                $messages[$messageKey]['enrichments'][$enrichmentKey] = [
                    'extractor_name' => $extractorName,
                    'extractor_version' => $extractorVersion,
                    'normalized_text' => (string) $row->normalized_text,
                    'language_code' => $row->language_code === null ? null : (string) $row->language_code,
                    'direction' => (string) $row->direction,
                    'thread_position' => (int) $row->thread_position,
                    'deterministic_facts' => $this->canonicalizeJsonValue($facts),
                    'participants' => [],
                ];
            }

            if ($row->normalized_email !== null) {
                $messages[$messageKey]['enrichments'][$enrichmentKey]['participants'][] = [
                    'role' => (string) $row->role,
                    'normalized_email' => (string) $row->normalized_email,
                    'display_name' => $row->display_name === null ? null : (string) $row->display_name,
                ];
            }
        }

        if (count($messages) !== $messageCount) {
            throw new CaptureFailure('O snapshot da Thread não contém todas as Versões de mensagem.');
        }

        foreach ($messages as &$message) {
            if ($message['enrichments'] === []) {
                throw new CaptureFailure('O snapshot da Thread não contém todos os enriquecimentos.');
            }

            $message['enrichments'] = array_values($message['enrichments']);
        }
        unset($message);

        // IDs internos de Message agrupam/ordenam linhas, mas não são serializados no snapshot.
        /** @var list<array<string, mixed>> $messageSnapshots */
        $messageSnapshots = array_values($messages);
        usort($messageSnapshots, function (array $left, array $right): int {
            $positionOrder = (int) ($left['enrichments'][0]['thread_position'] ?? PHP_INT_MAX)
                <=> (int) ($right['enrichments'][0]['thread_position'] ?? PHP_INT_MAX);
            if ($positionOrder !== 0) {
                return $positionOrder;
            }

            $dateOrder = strcmp(
                (string) ($left['provider_internal_date'] ?? ''),
                (string) ($right['provider_internal_date'] ?? ''),
            );

            return $dateOrder ?: strcmp($this->encodeStableJson($left), $this->encodeStableJson($right));
        });

        return hash('sha256', $this->encodeStableJson($messageSnapshots));
    }

    /** @param array<string, array{thread_id: int, hash: string}> $threadSnapshots */
    private function writeFinalPreAnalysisIntents(CaptureBatch $batch, array $threadSnapshots): void
    {
        $provider = config('preanalysis.provider', 'none');
        $enabled = config('preanalysis.enabled', false);
        $providerModel = config('preanalysis.model');
        $policy = new PreAnalysisDispatchPolicy(
            provider: is_string($provider) ? $provider : 'none',
            providerEnabled: $enabled === true,
            providerModel: is_string($providerModel) ? $providerModel : null,
        );
        $configuredModel = $policy->providerModel;

        if (! $policy->isEnabled() || ! is_string($configuredModel) || trim($configuredModel) === '') {
            return;
        }

        $questionVersion = config('preanalysis.question_version');
        $schemaVersion = config('preanalysis.schema_version');
        $privacyPolicyVersion = config('preanalysis.privacy_policy_version');
        if (! is_string($questionVersion) || trim($questionVersion) === ''
            || ! is_string($schemaVersion) || trim($schemaVersion) === ''
            || ! is_string($privacyPolicyVersion) || trim($privacyPolicyVersion) === '') {
            throw new CaptureFailure('A configuração da Pré-análise está incompleta.');
        }

        $threadIds = [];
        foreach ($batch->touchedThreadIds as $providerThreadId) {
            $snapshot = $threadSnapshots['thread:'.$providerThreadId] ?? null;
            if ($snapshot !== null) {
                $threadIds['thread-id:'.$snapshot['thread_id']] = $snapshot['thread_id'];
            }
        }

        $eligibleThreadIds = [];
        foreach (array_chunk(array_values($threadIds), 500) as $threadIdChunk) {
            $eligibleRows = DB::table('threads')
                ->where('account_id', $batch->accountId)
                ->whereIn('id', $threadIdChunk)
                ->where('operational_state', OperationalState::Pending->value)
                ->whereNull('latest_official_classification_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            foreach ($eligibleRows as $threadId) {
                $eligibleThreadIds['thread-id:'.(int) $threadId] = true;
            }
        }

        if ($eligibleThreadIds === []) {
            return;
        }

        $categoryCatalogVersion = null;
        foreach ($batch->touchedThreadIds as $providerThreadId) {
            $key = 'thread:'.$providerThreadId;
            $snapshot = $threadSnapshots[$key] ?? null;
            if ($snapshot === null || ! isset($eligibleThreadIds['thread-id:'.$snapshot['thread_id']])) {
                continue;
            }

            $categoryCatalogVersion ??= $this->activeCategoryCatalogVersion();
            $identity = new PreAnalysisIdentity(
                accountId: $batch->accountId,
                threadId: $snapshot['thread_id'],
                threadSnapshotHash: $snapshot['hash'],
                categoryCatalogVersion: $categoryCatalogVersion,
                providerModel: $configuredModel,
                privacyPolicyVersion: $privacyPolicyVersion,
            );

            $this->dispatchIntentWriter->write($identity, $questionVersion, $schemaVersion);
        }
    }

    private function activeCategoryCatalogVersion(): string
    {
        $rows = DB::table('categories as category')
            ->join('category_versions as version', function ($join): void {
                $join->on('version.id', '=', 'category.current_version_id')
                    ->on('version.category_id', '=', 'category.id');
            })
            ->whereNull('category.archived_at')
            ->orderBy('category.slug')
            ->get([
                'category.slug as category_slug',
                'version.version_number',
                'version.name',
                'version.slug as version_slug',
                'version.description',
                'version.classification_instructions',
                'version.examples_enabled',
            ]);

        $catalog = [];
        foreach ($rows as $row) {
            $catalog[] = [
                'category_slug' => (string) $row->category_slug,
                'version_number' => (int) $row->version_number,
                'name' => (string) $row->name,
                'version_slug' => (string) $row->version_slug,
                'description' => $row->description === null ? null : (string) $row->description,
                'classification_instructions' => $row->classification_instructions === null
                    ? null
                    : (string) $row->classification_instructions,
                'examples_enabled' => $this->databaseBoolean($row->examples_enabled),
            ];
        }

        return hash('sha256', $this->encodeStableJson($catalog));
    }

    private function canonicalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $canonical = new stdClass;
            foreach ($properties as $key => $property) {
                $canonical->{$key} = $this->canonicalizeJsonValue($property);
            }

            return $canonical;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeJsonValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeJsonValue($item);
        }

        return $value;
    }

    private function encodeStableJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalizeJsonValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function persistVersion(int $messageId, CapturedMessage $capturedMessage, DateTimeInterface $now): int
    {
        $canonical = $capturedMessage->canonical;
        $existing = DB::table('message_versions')
            ->where('message_id', $messageId)
            ->where('content_hash', $canonical->contentHash)
            ->first(['id']);

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $versionNumber = ((int) DB::table('message_versions')
            ->where('message_id', $messageId)
            ->max('version_number')) + 1;

        return (int) DB::table('message_versions')->insertGetId([
            'message_id' => $messageId,
            'version_number' => $versionNumber,
            'content_hash' => $canonical->contentHash,
            'subject' => $canonical->subject,
            'raw_body_text' => $canonical->rawBodyText,
            'raw_body_html' => $canonical->rawBodyHtml,
            'provider_internal_date' => $canonical->providerInternalDate?->format('Y-m-d H:i:s.uP'),
            'captured_at' => $now,
            'created_at' => $now,
        ], 'id');
    }

    /**
     * @param  list<AttachmentMetadata>  $attachments
     */
    private function persistAttachments(int $versionId, array $attachments, DateTimeInterface $now): void
    {
        DB::table('message_version_attachments')
            ->where('message_version_id', $versionId)
            ->delete();

        $rows = [];
        foreach ($attachments as $attachment) {
            $rows[] = [
                'message_version_id' => $versionId,
                'provider_attachment_id' => null,
                'file_name' => $attachment->fileName,
                'media_type' => $attachment->mediaType,
                'size_bytes' => $attachment->sizeBytes,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('message_version_attachments')->insert($rows);
        }
    }

    private function persistEnrichment(int $versionId, CapturedMessage $capturedMessage, DateTimeInterface $now): void
    {
        $enrichment = $capturedMessage->enrichment;
        DB::table('message_enrichments')->upsert([[
            'message_version_id' => $versionId,
            'extractor_name' => $enrichment->extractorName,
            'extractor_version' => $enrichment->extractorVersion,
            'normalized_text' => $enrichment->normalizedText,
            'language_code' => $enrichment->languageCode,
            'direction' => $enrichment->direction,
            'thread_position' => $enrichment->threadPosition,
            'deterministic_facts' => json_encode(
                $enrichment->deterministicFacts,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'created_at' => $now,
        ]], ['message_version_id', 'extractor_name', 'extractor_version'], [
            'normalized_text',
            'language_code',
            'direction',
            'thread_position',
            'deterministic_facts',
        ]);

        $enrichmentId = DB::table('message_enrichments')
            ->where('message_version_id', $versionId)
            ->where('extractor_name', $enrichment->extractorName)
            ->where('extractor_version', $enrichment->extractorVersion)
            ->value('id');

        if ($enrichmentId === null) {
            throw new CaptureFailure('O enriquecimento da Versão da mensagem não pôde ser persistido.');
        }

        DB::table('enrichment_participants')->where('enrichment_id', $enrichmentId)->delete();
        $participants = [];
        foreach ($enrichment->participants as $participant) {
            $participants[] = [
                'enrichment_id' => $enrichmentId,
                'normalized_email' => $participant->normalizedEmail,
                'display_name' => $participant->displayName,
                'role' => $participant->role,
            ];
        }

        if ($participants !== []) {
            DB::table('enrichment_participants')->insertOrIgnore($participants);
        }
    }

    /**
     * @param  list<string>  $labelIds
     */
    private function replaceLabels(int $messageId, array $labelIds, DateTimeInterface $now): void
    {
        DB::table('message_captured_labels')->where('message_id', $messageId)->delete();

        $labels = [];
        foreach (array_values(array_unique($labelIds)) as $labelId) {
            $labels[] = [
                'message_id' => $messageId,
                'gmail_label_id' => $labelId,
                'label_name' => null,
                'label_type' => in_array($labelId, self::SYSTEM_LABEL_IDS, true) ? 'system' : 'user',
                'captured_at' => $now,
            ];
        }

        if ($labels !== []) {
            DB::table('message_captured_labels')->insert($labels);
        }
    }

    private function advanceCursor(CaptureBatch $batch, DateTimeInterface $now): void
    {
        $changes = [
            'history_id' => $batch->cursorAfter,
            'cursor_generation' => DB::raw('cursor_generation + 1'),
            'updated_at' => $now,
        ];

        if ($batch->mode === SyncMode::Initial) {
            $changes['initial_sync_completed_at'] = $now;
            $changes['last_full_sync_at'] = $now;
        } elseif (in_array($batch->mode, [SyncMode::Full, SyncMode::ExpiredHistoryResync], true)) {
            $changes['last_full_sync_at'] = $now;
        }

        DB::table('sync_state')->where('account_id', $batch->accountId)->update($changes);
    }

    private function assertCursorDoesNotGoBack(?string $currentCursor, string $nextCursor): void
    {
        if ($currentCursor === null) {
            return;
        }

        $current = ltrim($currentCursor, '0') ?: '0';
        $next = ltrim($nextCursor, '0') ?: '0';

        if (strlen($next) < strlen($current) || (strlen($next) === strlen($current) && strcmp($next, $current) < 0)) {
            throw new CaptureFailure('O Cursor da Captura não pode retroceder.');
        }
    }

    private function isLater(string $candidate, ?string $stored): bool
    {
        return $stored === null || new DateTimeImmutable($candidate) > new DateTimeImmutable($stored);
    }
}
