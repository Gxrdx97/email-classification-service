<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->runStatements([
            'ALTER TABLE category_examples ADD COLUMN purged_at timestamptz',
            'ALTER TABLE category_examples ALTER COLUMN content_hash DROP NOT NULL',
            <<<'SQL'
CREATE OR REPLACE FUNCTION guard_category_example_lifecycle()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    purge_requested boolean;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'category_examples retain provenance; purge content instead of deleting'
            USING ERRCODE = '55000';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.example_text IS NULL OR NEW.content_hash IS NULL
            OR NEW.revoked_at IS NOT NULL OR NEW.purged_at IS NOT NULL THEN
            RAISE EXCEPTION 'category examples must be inserted active with content'
                USING ERRCODE = '55000';
        END IF;

        RETURN NEW;
    END IF;

    IF (to_jsonb(NEW) - 'example_text' - 'content_hash' - 'revoked_at' - 'purged_at' - 'search_document')
        IS DISTINCT FROM (to_jsonb(OLD) - 'example_text' - 'content_hash' - 'revoked_at' - 'purged_at' - 'search_document') THEN
        RAISE EXCEPTION 'category example provenance is immutable'
            USING ERRCODE = '55000';
    END IF;

    IF OLD.purged_at IS NOT NULL THEN
        IF NEW.example_text IS DISTINCT FROM OLD.example_text
            OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
            OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at
            OR NEW.purged_at IS DISTINCT FROM OLD.purged_at THEN
            RAISE EXCEPTION 'category example purge and revocation are permanent'
                USING ERRCODE = '55000';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
        RAISE EXCEPTION 'category example revocation is permanent'
            USING ERRCODE = '55000';
    END IF;

    purge_requested := NEW.purged_at IS NOT NULL
        OR NEW.example_text IS NULL
        OR NEW.content_hash IS NULL;

    IF purge_requested THEN
        NEW.revoked_at := COALESCE(OLD.revoked_at, statement_timestamp());
        NEW.purged_at := COALESCE(OLD.purged_at, statement_timestamp());
        NEW.example_text := NULL;
        NEW.content_hash := NULL;

        RETURN NEW;
    END IF;

    IF NEW.example_text IS DISTINCT FROM OLD.example_text THEN
        RAISE EXCEPTION 'category example text may only be purged'
            USING ERRCODE = '55000';
    END IF;

    IF NEW.content_hash IS DISTINCT FROM OLD.content_hash THEN
        RAISE EXCEPTION 'category example content hash may only be purged'
            USING ERRCODE = '55000';
    END IF;

    IF NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
        IF NEW.revoked_at IS NULL THEN
            RAISE EXCEPTION 'category example revocation is permanent'
                USING ERRCODE = '55000';
        END IF;

        NEW.revoked_at := statement_timestamp();
    END IF;

    RETURN NEW;
END;
$$
SQL,
            'DROP TRIGGER IF EXISTS category_examples_lifecycle_trigger ON category_examples',
            <<<'SQL'
CREATE TRIGGER category_examples_lifecycle_trigger
    BEFORE INSERT OR UPDATE OR DELETE ON category_examples
    FOR EACH ROW EXECUTE FUNCTION guard_category_example_lifecycle()
SQL,
            <<<'SQL'
CREATE OR REPLACE FUNCTION purge_category_example_embeddings()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL THEN
        DELETE FROM category_example_embeddings
        WHERE category_example_id = NEW.id;
    END IF;

    RETURN NULL;
END;
$$
SQL,
            'DROP TRIGGER IF EXISTS category_examples_purge_embeddings_trigger ON category_examples',
            <<<'SQL'
CREATE TRIGGER category_examples_purge_embeddings_trigger
    AFTER UPDATE ON category_examples
    FOR EACH ROW EXECUTE FUNCTION purge_category_example_embeddings()
SQL,
            <<<'SQL'
UPDATE category_examples
SET purged_at = statement_timestamp(),
    example_text = NULL,
    content_hash = NULL
WHERE example_text IS NULL OR content_hash IS NULL
SQL,
            <<<'SQL'
ALTER TABLE category_examples
    ADD CONSTRAINT category_examples_purge_state_check
    CHECK (
        (purged_at IS NULL AND example_text IS NOT NULL AND content_hash IS NOT NULL)
        OR (purged_at IS NOT NULL AND revoked_at IS NOT NULL AND example_text IS NULL AND content_hash IS NULL)
    )
SQL,
            <<<'SQL'
CREATE OR REPLACE FUNCTION guard_category_example_embedding_lifecycle()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_revoked_at timestamptz;
    parent_purged_at timestamptz;
BEGIN
    IF TG_OP = 'DELETE' THEN
        SELECT purged_at
        INTO parent_purged_at
        FROM category_examples
        WHERE id = OLD.category_example_id;

        IF NOT FOUND OR parent_purged_at IS NULL THEN
            RAISE EXCEPTION 'category example embeddings may only be deleted during or after purge'
                USING ERRCODE = '55000';
        END IF;

        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' AND NEW.category_example_id IS DISTINCT FROM OLD.category_example_id THEN
        RAISE EXCEPTION 'category example embedding provenance is immutable'
            USING ERRCODE = '55000';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        SELECT revoked_at, purged_at
        INTO parent_revoked_at, parent_purged_at
        FROM category_examples
        WHERE id = NEW.category_example_id
        FOR UPDATE NOWAIT;
    ELSE
        SELECT revoked_at, purged_at
        INTO parent_revoked_at, parent_purged_at
        FROM category_examples
        WHERE id = NEW.category_example_id
        FOR UPDATE;
    END IF;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'category example embedding requires an existing example'
            USING ERRCODE = '55000';
    END IF;

    IF parent_revoked_at IS NOT NULL OR parent_purged_at IS NOT NULL THEN
        RAISE EXCEPTION 'category example embeddings are immutable after revocation or purge'
            USING ERRCODE = '55000';
    END IF;

    RETURN NEW;
END;
$$
SQL,
            'DROP TRIGGER IF EXISTS category_example_embeddings_lifecycle_trigger ON category_example_embeddings',
            <<<'SQL'
CREATE TRIGGER category_example_embeddings_lifecycle_trigger
    BEFORE INSERT OR UPDATE OR DELETE ON category_example_embeddings
    FOR EACH ROW EXECUTE FUNCTION guard_category_example_embedding_lifecycle()
SQL,
        ]);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE category_examples IN ACCESS EXCLUSIVE MODE');

        if (DB::table('category_examples')
            ->whereNotNull('purged_at')
            ->orWhereNull('example_text')
            ->orWhereNull('content_hash')
            ->exists()) {
            throw new LogicException('Cannot reverse category example purge after content or embeddings were irreversibly removed.');
        }

        $this->runStatements([
            'DROP TRIGGER IF EXISTS category_example_embeddings_lifecycle_trigger ON category_example_embeddings',
            'DROP TRIGGER IF EXISTS category_examples_purge_embeddings_trigger ON category_examples',
            'DROP TRIGGER IF EXISTS category_examples_lifecycle_trigger ON category_examples',
            'DROP FUNCTION IF EXISTS guard_category_example_embedding_lifecycle()',
            'DROP FUNCTION IF EXISTS purge_category_example_embeddings()',
            'ALTER TABLE category_examples DROP CONSTRAINT IF EXISTS category_examples_purge_state_check',
            'ALTER TABLE category_examples ALTER COLUMN content_hash SET NOT NULL',
            'ALTER TABLE category_examples DROP COLUMN purged_at',
            <<<'SQL'
CREATE OR REPLACE FUNCTION guard_category_example_lifecycle()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'category_examples retain provenance; erase text and revoke instead of deleting'
            USING ERRCODE = '55000';
    END IF;

    IF (to_jsonb(NEW) - 'example_text' - 'revoked_at' - 'search_document')
        IS DISTINCT FROM (to_jsonb(OLD) - 'example_text' - 'revoked_at' - 'search_document') THEN
        RAISE EXCEPTION 'category examples are immutable except for retention erasure or revocation'
            USING ERRCODE = '55000';
    END IF;

    IF NEW.example_text IS DISTINCT FROM OLD.example_text AND NEW.example_text IS NOT NULL THEN
        RAISE EXCEPTION 'category example text may only be erased'
            USING ERRCODE = '55000';
    END IF;

    IF NEW.revoked_at IS DISTINCT FROM OLD.revoked_at
        AND (OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL) THEN
        RAISE EXCEPTION 'category example revocation is permanent'
            USING ERRCODE = '55000';
    END IF;

    RETURN NEW;
END;
$$
SQL,
            <<<'SQL'
CREATE TRIGGER category_examples_lifecycle_trigger
    BEFORE UPDATE OR DELETE ON category_examples
    FOR EACH ROW EXECUTE FUNCTION guard_category_example_lifecycle()
SQL,
        ]);
    }

    /**
     * @param  list<string>  $statements
     */
    private function runStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }
};
