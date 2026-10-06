<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE message_version_attachments
    ALTER COLUMN provider_attachment_id DROP NOT NULL
SQL);

        DB::statement(<<<'SQL'
COMMENT ON COLUMN message_version_attachments.provider_attachment_id IS
    'Deprecated compatibility column. New domain writes persist only file_name, media_type and size_bytes.'
SQL);
    }

    public function down(): void
    {
        if (DB::table('message_version_attachments')->whereNull('provider_attachment_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore provider_attachment_id NOT NULL while attachment rows without a provider ID exist.'
            );
        }

        DB::statement(<<<'SQL'
ALTER TABLE message_version_attachments
    ALTER COLUMN provider_attachment_id SET NOT NULL
SQL);

        DB::statement('COMMENT ON COLUMN message_version_attachments.provider_attachment_id IS NULL');
    }
};
