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
        // Standard application questions with the candidate's own answers (reusable, versioned).
        Schema::create('answer_library_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('question');
            $table->text('category');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->text('status');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['candidate_profile_id', 'status', 'category']);
        });

        DB::statement("ALTER TABLE answer_library_entries ADD CONSTRAINT answer_library_entries_status_check CHECK (status IN ('approved', 'draft', 'archived'))");
        DB::statement("ALTER TABLE answer_library_entries ADD CONSTRAINT answer_library_entries_category_check CHECK (category IN ('motivation', 'strengths', 'weaknesses', 'behavioural', 'technical', 'salary', 'availability', 'other'))");
        DB::statement("ALTER TABLE answer_library_entries ADD CONSTRAINT answer_library_entries_approved_check CHECK (status <> 'approved' OR approved_by IS NOT NULL)");
        DB::statement('CREATE INDEX answer_library_entries_question_trgm ON answer_library_entries USING gin (question gin_trgm_ops)');

        Schema::create('answer_library_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_library_entry_id')->constrained()->cascadeOnDelete();
            $table->integer('revision_no');
            $table->text('body');
            // Bullet points (claim ULIDs) the answer relies on — evidence for later AI reuse.
            $table->jsonb('claim_ulids')->default('[]');
            $table->string('change_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('created_via')->default('manual');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['answer_library_entry_id', 'revision_no']);
        });

        DB::statement('CREATE INDEX answer_library_revisions_body_trgm ON answer_library_revisions USING gin (body gin_trgm_ops)');
        DB::statement('ALTER TABLE answer_library_entries ADD CONSTRAINT answer_library_entries_current_revision_foreign FOREIGN KEY (current_revision_id) REFERENCES answer_library_revisions (id) DEFERRABLE INITIALLY DEFERRED');
        DB::unprepared('CREATE TRIGGER answer_library_revisions_no_update BEFORE UPDATE ON answer_library_revisions FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update()');

        // VAs suggest changes to approved answers through the same proposals as other facts.
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_type_check CHECK (proposal_type IN ('revise_claim', 'revise_record', 'revise_library_answer'))");
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_target_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_target_type_check CHECK (target_type IN ('claim', 'employment', 'project', 'credential', 'answer_library_entry'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_type_check CHECK (proposal_type IN ('revise_claim', 'revise_record'))");
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_target_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_target_type_check CHECK (target_type IN ('claim', 'employment', 'project', 'credential'))");

        DB::statement('ALTER TABLE answer_library_entries DROP CONSTRAINT IF EXISTS answer_library_entries_current_revision_foreign');
        Schema::dropIfExists('answer_library_revisions');
        Schema::dropIfExists('answer_library_entries');
    }
};
