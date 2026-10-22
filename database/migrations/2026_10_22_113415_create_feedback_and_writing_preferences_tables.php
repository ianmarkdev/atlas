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
        // M10: how answers should be written ("British spelling", "never say passionate"). Profile
        // rules apply to everyone writing for the candidate; user rules only to that user's drafts.
        Schema::create('writing_preferences', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('scope');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('rule');
            $table->text('kind');
            $table->text('status')->default('active');
            // `manual` or `proposal:{id}` (learned from edits).
            $table->text('created_via')->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->index(['candidate_profile_id', 'status']);
        });

        DB::statement("ALTER TABLE writing_preferences ADD CONSTRAINT writing_preferences_scope_check CHECK ((scope = 'profile' AND user_id IS NULL) OR (scope = 'user' AND user_id IS NOT NULL))");
        DB::statement("ALTER TABLE writing_preferences ADD CONSTRAINT writing_preferences_kind_check CHECK (kind IN ('tone', 'banned_phrase', 'format', 'spelling', 'structure'))");
        DB::statement("ALTER TABLE writing_preferences ADD CONSTRAINT writing_preferences_status_check CHECK (status IN ('active', 'archived'))");

        // M10: an edited AI answer, the server's word diff and what the AI learned from it.
        Schema::create('feedback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_draft_id')->constrained()->cascadeOnDelete();
            // The AI version that was edited, and the edited version.
            $table->foreignId('from_version_id')->constrained('answer_draft_versions')->cascadeOnDelete();
            $table->foreignId('to_version_id')->constrained('answer_draft_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('trigger');
            $table->jsonb('diff');
            $table->jsonb('analysis')->nullable();
            $table->foreignId('generation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('status')->default('pending_analysis');
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->unique(['from_version_id', 'to_version_id']);
        });

        DB::statement("ALTER TABLE feedback_events ADD CONSTRAINT feedback_events_trigger_check CHECK (trigger IN ('approval', 'manual'))");
        DB::statement("ALTER TABLE feedback_events ADD CONSTRAINT feedback_events_status_check CHECK (status IN ('pending_analysis', 'analysed', 'failed'))");

        Schema::table('knowledge_proposals', function (Blueprint $table) {
            // Proposals that create something have no target yet.
            $table->unsignedBigInteger('target_id')->nullable()->change();
            $table->unsignedBigInteger('origin_id')->nullable()->after('origin');
            $table->foreignId('proposed_by_run_id')->nullable()->after('proposed_by_user_id')->constrained('generation_runs')->nullOnDelete();
            // Who decides, when not the candidate: the user a personal writing preference belongs to.
            $table->foreignId('reviewer_user_id')->nullable()->after('proposed_by_run_id')->constrained('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_type_check CHECK (proposal_type IN ('revise_claim', 'revise_record', 'revise_library_answer', 'create_claim', 'create_library_answer', 'create_writing_preference', 'revise_resume_variant'))");
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_target_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_target_type_check CHECK (target_type IN ('claim', 'employment', 'project', 'credential', 'answer_library_entry', 'writing_preference', 'resume_variant'))");
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_target_check CHECK ((proposal_type LIKE 'create_%') = (target_id IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DELETE FROM knowledge_proposals WHERE proposal_type IN ('create_claim', 'create_library_answer', 'create_writing_preference', 'revise_resume_variant')");
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_target_check');
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_type_check CHECK (proposal_type IN ('revise_claim', 'revise_record', 'revise_library_answer'))");
        DB::statement('ALTER TABLE knowledge_proposals DROP CONSTRAINT knowledge_proposals_target_type_check');
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_target_type_check CHECK (target_type IN ('claim', 'employment', 'project', 'credential', 'answer_library_entry'))");

        Schema::table('knowledge_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_user_id');
            $table->dropConstrainedForeignId('proposed_by_run_id');
            $table->dropColumn('origin_id');
            $table->unsignedBigInteger('target_id')->nullable(false)->change();
        });

        Schema::dropIfExists('feedback_events');
        Schema::dropIfExists('writing_preferences');
    }
};
