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
        // M8: redraft with an instruction, and the claim review that gates approving an AI draft.
        Schema::table('answer_drafts', function (Blueprint $table) {
            $table->text('generation_instruction')->nullable()->after('generation_requested_by');
            $table->foreignId('claims_reviewed_version_id')->nullable()->after('approved_version_id')->constrained('answer_draft_versions')->nullOnDelete();
        });

        // What an AI version was asked for, and the tailored resume version a cover letter was written from.
        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->text('instruction')->nullable()->after('generation_run_id');
            $table->foreignId('resume_version_id')->nullable()->after('instruction')->constrained('application_resume_versions')->nullOnDelete();
        });

        // M8: AI tailoring of the application's resume, in the background like answers.
        Schema::table('application_resumes', function (Blueprint $table) {
            $table->text('generation_status')->nullable()->after('status');
            $table->text('generation_error')->nullable()->after('generation_status');
            $table->timestampTz('generation_requested_at')->nullable()->after('generation_error');
            $table->foreignId('generation_requested_by')->nullable()->after('generation_requested_at')->constrained('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE application_resumes ADD CONSTRAINT application_resumes_generation_status_check CHECK (generation_status IS NULL OR generation_status IN ('queued', 'running', 'failed'))");

        Schema::table('application_resume_versions', function (Blueprint $table) {
            $table->foreignId('generation_run_id')->nullable()->after('created_via')->constrained()->nullOnDelete();
            // What the server kept and refused from the model's proposal (see ResumeTailor).
            $table->jsonb('ai_report')->nullable()->after('generation_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_resume_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_run_id');
            $table->dropColumn('ai_report');
        });

        DB::statement('ALTER TABLE application_resumes DROP CONSTRAINT IF EXISTS application_resumes_generation_status_check');

        Schema::table('application_resumes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_requested_by');
            $table->dropColumn(['generation_status', 'generation_error', 'generation_requested_at']);
        });

        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_version_id');
            $table->dropColumn('instruction');
        });

        Schema::table('answer_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claims_reviewed_version_id');
            $table->dropColumn('generation_instruction');
        });
    }
};
