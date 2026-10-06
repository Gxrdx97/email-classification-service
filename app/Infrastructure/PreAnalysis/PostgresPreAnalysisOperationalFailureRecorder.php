<?php

declare(strict_types=1);

namespace App\Infrastructure\PreAnalysis;

use App\Domain\Classification\Exceptions\ReviewTargetNotFound;
use App\Domain\PreAnalysis\Contracts\PreAnalysisOperationalFailureRecorder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostgresPreAnalysisOperationalFailureRecorder implements PreAnalysisOperationalFailureRecorder
{
    public function markFailedWithoutReplacingOfficialResult(int $threadId, string $failureCode): void
    {
        if ($threadId < 1 || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $failureCode) !== 1) {
            throw new InvalidArgumentException('Pre-analysis failure data is invalid.');
        }

        $connection = DB::connection();
        $updated = $connection->table('threads')
            ->where('id', $threadId)
            ->update([
                'operational_state' => 'failed',
                'updated_at' => now(),
            ]);

        if ($updated === 0 && ! $connection->table('threads')->where('id', $threadId)->exists()) {
            throw new ReviewTargetNotFound('Thread não encontrada.');
        }
    }
}
