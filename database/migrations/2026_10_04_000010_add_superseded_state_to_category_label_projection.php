<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gmail_label_projection_outbox DROP CONSTRAINT IF EXISTS gmail_label_projection_outbox_status_check');
        $this->dropCompletionConstraint();
        DB::statement('ALTER TABLE gmail_label_projection_states DROP CONSTRAINT IF EXISTS gmail_label_projection_states_status_check');

        DB::statement(<<<'SQL'
ALTER TABLE gmail_label_projection_outbox
    ADD CONSTRAINT gmail_label_projection_outbox_status_check
    CHECK (status IN ('pending', 'processing', 'succeeded', 'retryable', 'dead', 'superseded'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE gmail_label_projection_outbox
    ADD CONSTRAINT gmail_label_projection_outbox_completion_check
    CHECK (
        (status IN ('pending', 'processing', 'retryable') AND completed_at IS NULL)
        OR (status IN ('succeeded', 'dead', 'superseded') AND completed_at IS NOT NULL)
    )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE gmail_label_projection_states
    ADD CONSTRAINT gmail_label_projection_states_status_check
    CHECK (status IN ('pending', 'applied', 'retryable', 'failed', 'suppressed'))
SQL);
    }

    public function down(): void
    {
        if (DB::table('gmail_label_projection_outbox')->where('status', 'superseded')->exists()) {
            throw new RuntimeException('Cannot roll back while superseded Gmail projection intents exist.');
        }

        if (DB::table('gmail_label_projection_states')->where('status', 'suppressed')->exists()) {
            throw new RuntimeException('Cannot roll back while suppressed Gmail projection states exist.');
        }

        DB::statement('ALTER TABLE gmail_label_projection_outbox DROP CONSTRAINT gmail_label_projection_outbox_status_check');
        DB::statement('ALTER TABLE gmail_label_projection_outbox DROP CONSTRAINT gmail_label_projection_outbox_completion_check');
        DB::statement('ALTER TABLE gmail_label_projection_states DROP CONSTRAINT gmail_label_projection_states_status_check');

        DB::statement(<<<'SQL'
ALTER TABLE gmail_label_projection_outbox
    ADD CONSTRAINT gmail_label_projection_outbox_status_check
    CHECK (status IN ('pending', 'processing', 'succeeded', 'retryable', 'dead'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE gmail_label_projection_outbox
    ADD CONSTRAINT gmail_label_projection_outbox_completion_check
    CHECK (
        (status IN ('pending', 'processing', 'retryable') AND completed_at IS NULL)
        OR (status IN ('succeeded', 'dead') AND completed_at IS NOT NULL)
    )
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE gmail_label_projection_states
    ADD CONSTRAINT gmail_label_projection_states_status_check
    CHECK (status IN ('pending', 'applied', 'retryable', 'failed'))
SQL);
    }

    private function dropCompletionConstraint(): void
    {
        $constraint = DB::selectOne(<<<'SQL'
SELECT conname
FROM pg_constraint
WHERE conrelid = 'gmail_label_projection_outbox'::regclass
  AND contype = 'c'
  AND pg_get_constraintdef(oid) ILIKE '%completed_at%'
SQL);

        if ($constraint === null) {
            throw new RuntimeException('Gmail projection completion constraint was not found.');
        }

        $name = str_replace('"', '""', (string) $constraint->conname);
        DB::statement('ALTER TABLE gmail_label_projection_outbox DROP CONSTRAINT "'.$name.'"');
    }
};
