<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE thread_classifications
    ADD COLUMN decision_author text NOT NULL DEFAULT 'human_review',
    ADD CONSTRAINT thread_classifications_human_decision_author_check
        CHECK (decision_author = 'human_review')
SQL);

        DB::statement(<<<'SQL'
COMMENT ON COLUMN thread_classifications.provider_name IS
    'Origem consultiva da proposta considerada na Revisão; não identifica quem gravou a decisão oficial.'
SQL);

        DB::statement(<<<'SQL'
COMMENT ON COLUMN thread_classifications.decision_author IS
    'Autoridade da decisão oficial. Restrita a human_review; provedores externos nunca persistem decisões oficiais.'
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE thread_classifications DROP CONSTRAINT IF EXISTS thread_classifications_human_decision_author_check');
        DB::statement('ALTER TABLE thread_classifications DROP COLUMN IF EXISTS decision_author');
        DB::statement('COMMENT ON COLUMN thread_classifications.provider_name IS NULL');
    }
};
