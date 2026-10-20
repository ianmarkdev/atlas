<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            // Morph alias (employment|project|credential) or null for a general claim.
            $table->text('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('kind');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->text('status');
            $table->text('origin')->default('user_entered');
            $table->string('normalized_hash', 64);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['candidate_profile_id', 'status', 'kind']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_status_check CHECK (status IN ('verified', 'proposed', 'rejected', 'archived'))");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_kind_check CHECK (kind IN ('achievement', 'responsibility', 'skill', 'qualification', 'other'))");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_origin_check CHECK (origin IN ('user_entered', 'ai_suggested', 'feedback_derived', 'imported'))");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_subject_type_check CHECK (subject_type IS NULL OR subject_type IN ('employment', 'project', 'credential'))");
        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_subject_pair_check CHECK ((subject_type IS NULL) = (subject_id IS NULL))');
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_verified_check CHECK (status <> 'verified' OR verified_by IS NOT NULL)");
        // Exact-duplicate guard: one live claim per normalised statement per candidate.
        DB::statement("CREATE UNIQUE INDEX claims_live_statement_unique ON claims (candidate_profile_id, normalized_hash) WHERE status IN ('verified', 'proposed')");

        Schema::create('claim_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->integer('revision_no');
            $table->text('statement');
            $table->jsonb('structured')->default('{}');
            $table->string('change_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('created_via')->default('manual');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['claim_id', 'revision_no']);
        });

        // Near-duplicate detection (similarity() over current statements).
        DB::statement('CREATE INDEX claim_revisions_statement_trgm ON claim_revisions USING gin (statement gin_trgm_ops)');

        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_current_revision_foreign FOREIGN KEY (current_revision_id) REFERENCES claim_revisions (id) DEFERRABLE INITIALLY DEFERRED');

        Schema::create('claim_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_revision_id')->constrained()->cascadeOnDelete();
            $table->text('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('quote')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['claim_id', 'source_type']);
        });

        DB::statement("ALTER TABLE claim_sources ADD CONSTRAINT claim_sources_type_check CHECK (source_type IN ('user_confirmation', 'project', 'employment', 'external_url', 'answer_version'))");

        // Revisions and sources are history: rows are added, never edited.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION knowledge_history_prevent_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER claim_revisions_no_update BEFORE UPDATE ON claim_revisions
                FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update();
            CREATE TRIGGER claim_sources_no_update BEFORE UPDATE ON claim_sources
                FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE claims DROP CONSTRAINT IF EXISTS claims_current_revision_foreign');
        Schema::dropIfExists('claim_sources');
        Schema::dropIfExists('claim_revisions');
        Schema::dropIfExists('claims');
        DB::statement('DROP FUNCTION IF EXISTS knowledge_history_prevent_update()');
    }
};
