<?php

declare(strict_types=1);

namespace App\Infrastructure\PreAnalysis;

use App\Domain\PreAnalysis\Contracts\PreAnalysisContextSource;
use App\Domain\PreAnalysis\Data\PreAnalysisCategoryVersion;
use App\Domain\PreAnalysis\Data\PreAnalysisContext;
use App\Domain\PreAnalysis\Data\PreAnalysisExample;
use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use App\Domain\PreAnalysis\Data\PreAnalysisLease;
use App\Domain\Privacy\Data\PrivacyPolicy;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PostgresPreAnalysisContextSource implements PreAnalysisContextSource
{
    public function __construct(
        private PrivacyPolicy $privacyPolicy,
        private string $questionVersion,
        private string $schemaVersion,
        private string $question,
    ) {
        if (trim($this->questionVersion) === ''
            || trim($this->schemaVersion) === ''
            || trim($this->question) === '') {
            throw new InvalidArgumentException('Pre-analysis context versions and question are required.');
        }
    }

    public function load(PreAnalysisLease $lease): ?PreAnalysisContext
    {
        $connection = DB::connection();
        $identity = $lease->identity;

        $row = $connection->table('pre_analyses as pre_analysis')
            ->join('threads as thread', function ($join): void {
                $join->on('thread.id', '=', 'pre_analysis.thread_id')
                    ->on('thread.account_id', '=', 'pre_analysis.account_id');
            })
            ->leftJoin('sync_state as sync_state', 'sync_state.account_id', '=', 'pre_analysis.account_id')
            ->where('pre_analysis.id', $lease->preAnalysisId)
            ->where('pre_analysis.account_id', $identity->accountId)
            ->where('pre_analysis.thread_id', $identity->threadId)
            ->where('pre_analysis.thread_snapshot_hash', $identity->threadSnapshotHash)
            ->where('pre_analysis.category_catalog_version', $identity->categoryCatalogVersion)
            ->where('pre_analysis.provider_model', $identity->providerModel)
            ->where('pre_analysis.privacy_policy_version', $identity->privacyPolicyVersion)
            ->where('pre_analysis.privacy_policy_version', $this->privacyPolicy->version)
            ->where('pre_analysis.question_version', $this->questionVersion)
            ->where('pre_analysis.schema_version', $this->schemaVersion)
            ->where('pre_analysis.provider', 'jev')
            ->where('pre_analysis.status', 'running')
            ->where('pre_analysis.started_at', $this->timestamp($lease->startedAt))
            ->where('thread.thread_snapshot_hash', $identity->threadSnapshotHash)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('pre_analysis_dispatch_outbox as dispatch_outbox')
                    ->whereColumn('dispatch_outbox.pre_analysis_id', 'pre_analysis.id');
            })
            ->first([
                'pre_analysis.account_id',
                'pre_analysis.thread_id',
                'pre_analysis.thread_snapshot_hash',
                'pre_analysis.category_catalog_version',
                'pre_analysis.provider_model',
                'pre_analysis.privacy_policy_version',
                'pre_analysis.question_version',
                'pre_analysis.schema_version',
                'thread.latest_official_classification_id',
                'sync_state.history_id',
                'sync_state.initial_sync_completed_at',
            ]);

        if ($row === null) {
            return null;
        }

        $loadedIdentity = new PreAnalysisIdentity(
            (int) $row->account_id,
            (int) $row->thread_id,
            rtrim((string) $row->thread_snapshot_hash),
            (string) $row->category_catalog_version,
            (string) $row->provider_model,
            (string) $row->privacy_policy_version,
        );
        $cursorCommitted = (is_string($row->history_id) && trim($row->history_id) !== '')
            || $row->initial_sync_completed_at !== null;
        $hasOfficialResult = $row->latest_official_classification_id !== null;

        if (! $cursorCommitted || $hasOfficialResult) {
            return new PreAnalysisContext(
                $loadedIdentity,
                $cursorCommitted,
                $hasOfficialResult,
                $this->privacyPolicy->action,
                (string) $row->question_version,
                (string) $row->schema_version,
                $this->question,
                [],
                [],
                [],
            );
        }

        $thread = $this->loadThread($identity->accountId, $identity->threadId);

        if ($thread === null) {
            return null;
        }

        $categoryVersions = $this->loadActiveCategoryVersions();
        $examples = $this->loadEligibleExamples(array_map(
            static fn (PreAnalysisCategoryVersion $category): int => $category->id,
            $categoryVersions,
        ));

        return new PreAnalysisContext(
            $loadedIdentity,
            true,
            false,
            $this->privacyPolicy->action,
            (string) $row->question_version,
            (string) $row->schema_version,
            $this->question,
            $thread,
            $categoryVersions,
            $examples,
        );
    }

    /** @return array{messages: list<array{from: string|null, subject: string|null, sent_at: string|null, body: string}>}|null */
    private function loadThread(int $accountId, int $threadId): ?array
    {
        $connection = DB::connection();
        $messageCount = $connection->table('messages')
            ->where('account_id', $accountId)
            ->where('thread_id', $threadId)
            ->count();

        if ($messageCount < 1) {
            return null;
        }

        $rows = $connection->select(
            <<<'SQL'
SELECT sender.from_value,
       latest_version.subject,
       latest_version.provider_internal_date,
       latest_enrichment.normalized_text
FROM messages AS message
JOIN LATERAL (
    SELECT version.id, version.subject, version.provider_internal_date
    FROM message_versions AS version
    WHERE version.message_id = message.id
    ORDER BY version.version_number DESC
    LIMIT 1
) AS latest_version ON TRUE
JOIN LATERAL (
    SELECT enrichment.id, enrichment.normalized_text, enrichment.thread_position
    FROM message_enrichments AS enrichment
    WHERE enrichment.message_version_id = latest_version.id
    ORDER BY enrichment.created_at DESC, enrichment.id DESC
    LIMIT 1
) AS latest_enrichment ON TRUE
LEFT JOIN LATERAL (
    SELECT string_agg(
        CASE
            WHEN participant.display_name IS NULL OR participant.display_name = ''
                THEN participant.normalized_email
            ELSE participant.display_name || ' <' || participant.normalized_email || '>'
        END,
        ', ' ORDER BY participant.normalized_email
    ) AS from_value
    FROM enrichment_participants AS participant
    WHERE participant.enrichment_id = latest_enrichment.id
      AND participant.role = 'from'
) AS sender ON TRUE
WHERE message.account_id = ?
  AND message.thread_id = ?
ORDER BY latest_enrichment.thread_position, message.id
SQL,
            [$accountId, $threadId],
        );

        if (count($rows) !== $messageCount) {
            return null;
        }

        $messages = [];

        foreach ($rows as $row) {
            $messages[] = [
                'from' => $row->from_value === null ? null : (string) $row->from_value,
                'subject' => $row->subject === null ? null : (string) $row->subject,
                'sent_at' => $row->provider_internal_date === null
                    ? null
                    : (string) $row->provider_internal_date,
                'body' => (string) $row->normalized_text,
            ];
        }

        return ['messages' => $messages];
    }

    /** @return list<PreAnalysisCategoryVersion> */
    private function loadActiveCategoryVersions(): array
    {
        $rows = DB::connection()->table('categories as category')
            ->join('category_versions as version', function ($join): void {
                $join->on('version.id', '=', 'category.current_version_id')
                    ->on('version.category_id', '=', 'category.id');
            })
            ->whereNull('category.archived_at')
            ->orderBy('category.id')
            ->get([
                'version.id',
                'version.category_id',
                'version.version_number',
                'version.name',
                'version.slug',
                'version.description',
                'version.classification_instructions',
            ]);

        return $rows->map(static fn (object $row): PreAnalysisCategoryVersion => new PreAnalysisCategoryVersion(
            (int) $row->id,
            (int) $row->category_id,
            (int) $row->version_number,
            (string) $row->name,
            (string) $row->slug,
            $row->description === null ? null : (string) $row->description,
            $row->classification_instructions === null ? null : (string) $row->classification_instructions,
        ))->all();
    }

    /** @param list<int> $categoryVersionIds
     * @return list<PreAnalysisExample>
     */
    private function loadEligibleExamples(array $categoryVersionIds): array
    {
        if ($categoryVersionIds === []) {
            return [];
        }

        $rows = DB::connection()->table('category_examples as example')
            ->join('category_versions as version', 'version.id', '=', 'example.category_version_id')
            ->join('categories as category', function ($join): void {
                $join->on('category.current_version_id', '=', 'version.id')
                    ->on('category.id', '=', 'version.category_id');
            })
            ->whereIn('version.id', $categoryVersionIds)
            ->whereNull('category.archived_at')
            ->where('version.examples_enabled', true)
            ->where('example.examples_enabled', true)
            ->whereNull('example.revoked_at')
            ->whereNull('example.purged_at')
            ->whereNotNull('example.example_text')
            ->whereNotNull('example.content_hash')
            ->orderBy('example.category_version_id')
            ->orderBy('example.id')
            ->get([
                'example.id',
                'example.example_version',
                'example.category_version_id',
                'example.example_text',
            ]);

        return $rows->map(static fn (object $row): PreAnalysisExample => new PreAnalysisExample(
            (int) $row->id,
            (int) $row->example_version,
            (int) $row->category_version_id,
            (string) $row->example_text,
        ))->all();
    }

    private function timestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
