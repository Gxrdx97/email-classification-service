<?php

declare(strict_types=1);

namespace App\Infrastructure\PreAnalysis;

use App\Domain\PreAnalysis\Data\PreAnalysisIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PreAnalysisDispatchIntentWriter
{
    public function write(
        PreAnalysisIdentity $identity,
        string $questionVersion,
        string $schemaVersion,
    ): int {
        if (trim($questionVersion) === '' || trim($schemaVersion) === '') {
            throw new InvalidArgumentException('Pre-analysis context versions are required.');
        }

        $connection = DB::connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Pre-analysis dispatch intent must share the CaptureStore transaction.');
        }

        $thread = $connection->table('threads')
            ->where('id', $identity->threadId)
            ->where('account_id', $identity->accountId)
            ->lockForUpdate()
            ->first(['thread_snapshot_hash', 'operational_state', 'latest_official_classification_id']);

        if ($thread === null
            || rtrim((string) $thread->thread_snapshot_hash) !== $identity->threadSnapshotHash
            || $thread->operational_state !== 'pending'
            || $thread->latest_official_classification_id !== null) {
            throw new LogicException('The Thread is not eligible for this pre-analysis dispatch intent.');
        }

        $connection->statement(
            <<<'SQL'
INSERT INTO pre_analyses (
    account_id,
    thread_id,
    thread_snapshot_hash,
    category_catalog_version,
    provider,
    provider_model,
    question_version,
    schema_version,
    privacy_policy_version
)
VALUES (?, ?, ?, ?, 'jev', ?, ?, ?, ?)
ON CONFLICT (
    account_id,
    thread_id,
    thread_snapshot_hash,
    category_catalog_version,
    provider_model,
    privacy_policy_version
)
DO NOTHING
SQL,
            [
                $identity->accountId,
                $identity->threadId,
                $identity->threadSnapshotHash,
                $identity->categoryCatalogVersion,
                $identity->providerModel,
                $questionVersion,
                $schemaVersion,
                $identity->privacyPolicyVersion,
            ],
        );

        $preAnalysis = $connection->table('pre_analyses')
            ->where('account_id', $identity->accountId)
            ->where('thread_id', $identity->threadId)
            ->where('thread_snapshot_hash', $identity->threadSnapshotHash)
            ->where('category_catalog_version', $identity->categoryCatalogVersion)
            ->where('provider_model', $identity->providerModel)
            ->where('privacy_policy_version', $identity->privacyPolicyVersion)
            ->first(['id', 'provider', 'question_version', 'schema_version']);

        if ($preAnalysis === null
            || $preAnalysis->provider !== 'jev'
            || $preAnalysis->question_version !== $questionVersion
            || $preAnalysis->schema_version !== $schemaVersion) {
            throw new LogicException('The pre-analysis identity already exists with incompatible context versions.');
        }

        $connection->statement(
            <<<'SQL'
INSERT INTO pre_analysis_dispatch_outbox (pre_analysis_id)
VALUES (?)
ON CONFLICT (pre_analysis_id) DO NOTHING
SQL,
            [(int) $preAnalysis->id],
        );

        return (int) $preAnalysis->id;
    }
}
