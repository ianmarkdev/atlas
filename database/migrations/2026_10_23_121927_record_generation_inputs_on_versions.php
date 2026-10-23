<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What an AI version was written from, as it was then: the job description and analysis it
        // read, the wording of its writing rules, and where its evidence came from. The versions are
        // append-only, so these keys have no ON DELETE action: they go with the application.
        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->jsonb('writing_rules')->nullable()->after('writing_rule_ids');
            $table->jsonb('retrieval')->nullable()->after('writing_rules');
            $table->foreignId('job_posting_id')->nullable()->after('resume_version_id')->constrained();
            $table->foreignId('job_analysis_id')->nullable()->after('job_posting_id')->constrained();
        });

        Schema::table('application_resume_versions', function (Blueprint $table) {
            $table->foreignId('job_posting_id')->nullable()->after('generation_run_id')->constrained();
            $table->foreignId('job_analysis_id')->nullable()->after('job_posting_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_resume_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_analysis_id');
            $table->dropConstrainedForeignId('job_posting_id');
        });

        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_analysis_id');
            $table->dropConstrainedForeignId('job_posting_id');
            $table->dropColumn(['writing_rules', 'retrieval']);
        });
    }
};
