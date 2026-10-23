<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // `checking`: the AI draft is written and its claims are being labelled (CheckAnswerClaims).
        DB::statement('ALTER TABLE answer_drafts DROP CONSTRAINT answer_drafts_generation_status_check');
        DB::statement("ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_generation_status_check CHECK (generation_status IS NULL OR generation_status IN ('queued', 'running', 'checking', 'failed'))");

        // Versions stay append-only, with one exception: the claim labels of a version written
        // without them are filled in once. Nothing else about the row may change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION answer_draft_versions_prevent_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.grounding IS NULL AND NEW.grounding IS NOT NULL
                    AND (to_jsonb(NEW) - 'grounding') = (to_jsonb(OLD) - 'grounding') THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER answer_draft_versions_no_update ON answer_draft_versions;
            CREATE TRIGGER answer_draft_versions_no_update BEFORE UPDATE ON answer_draft_versions
                FOR EACH ROW EXECUTE FUNCTION answer_draft_versions_prevent_update();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER answer_draft_versions_no_update ON answer_draft_versions;
            CREATE TRIGGER answer_draft_versions_no_update BEFORE UPDATE ON answer_draft_versions
                FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update();
            DROP FUNCTION IF EXISTS answer_draft_versions_prevent_update();
            SQL);

        DB::statement("UPDATE answer_drafts SET generation_status = NULL WHERE generation_status = 'checking'");
        DB::statement('ALTER TABLE answer_drafts DROP CONSTRAINT answer_drafts_generation_status_check');
        DB::statement("ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_generation_status_check CHECK (generation_status IS NULL OR generation_status IN ('queued', 'running', 'failed'))");
    }
};
