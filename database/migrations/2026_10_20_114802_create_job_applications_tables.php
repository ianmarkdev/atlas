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
        // One job the candidate applies (or may apply) for. Two tracks (D10): `stage` is the job
        // search pipeline, `review_status` the VA preparation workflow.
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('company_name');
            $table->text('role_title');
            $table->text('source_url')->nullable();
            $table->text('location')->nullable();
            $table->text('employment_type')->nullable();
            $table->text('salary_text')->nullable();
            $table->text('stage')->default('saved');
            $table->text('review_status')->default('not_started');
            $table->timestampTz('stage_changed_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->date('deadline_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'stage', 'deadline_on']);
        });

        DB::statement("ALTER TABLE job_applications ADD CONSTRAINT job_applications_stage_check CHECK (stage IN ('saved', 'preparing', 'applied', 'interviewing', 'offer', 'rejected', 'withdrawn'))");
        DB::statement("ALTER TABLE job_applications ADD CONSTRAINT job_applications_review_status_check CHECK (review_status IN ('not_started', 'in_progress', 'in_review', 'changes_requested', 'approved'))");
        DB::statement("ALTER TABLE job_applications ADD CONSTRAINT job_applications_employment_type_check CHECK (employment_type IS NULL OR employment_type IN ('full_time', 'part_time', 'contract', 'freelance', 'internship', 'other'))");
        DB::statement('CREATE INDEX job_applications_company_trgm ON job_applications USING gin (company_name gin_trgm_ops)');

        // The job description exactly as pasted. Never updated: a corrected paste adds a row and the
        // newest row is the current one, so an analysis can always point at the text it read.
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id')->index();
            $table->text('raw_text');
            $table->char('raw_sha256', 64);
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('captured_at')->useCurrent();

            $table->foreign(['workspace_id', 'job_application_id'])
                ->references(['workspace_id', 'id'])->on('job_applications')->cascadeOnDelete();
        });

        DB::unprepared('CREATE TRIGGER job_postings_no_update BEFORE UPDATE ON job_postings FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('job_applications');
    }
};
