<?php

declare(strict_types=1);

namespace App\Domain\Classification\Repositories;

use App\Domain\Classification\Contracts\ClassificationOperationalStateRepository;
use App\Domain\Classification\Enums\OperationalState;
use App\Domain\Classification\Exceptions\ReviewTargetNotFound;
use Illuminate\Support\Facades\DB;

final class PostgresClassificationOperationalStateRepository implements ClassificationOperationalStateRepository
{
    public function markPending(int $threadId): void
    {
        $this->updateOperationalState($threadId, OperationalState::Pending);
    }

    public function markFailed(int $threadId): void
    {
        $this->updateOperationalState($threadId, OperationalState::Failed);
    }

    private function updateOperationalState(int $threadId, OperationalState $state): void
    {
        $updated = DB::table('threads')->where('id', $threadId)->update([
            'operational_state' => $state->value,
            'updated_at' => now(),
        ]);

        if ($updated === 0 && ! DB::table('threads')->where('id', $threadId)->exists()) {
            throw new ReviewTargetNotFound('Thread não encontrada.');
        }
    }
}
