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
        // The resume tailored for one application: a copy of a resume variant, edited for this job
        // (D9, D10). Its versions are append-only; approving freezes the current one.
        Schema::create('application_resumes', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id')->unique();
            $table->foreignId('based_on_variant_id')->nullable()->constrained('resume_variants')->nullOnDelete();
            $table->unsignedBigInteger('based_on_revision_id')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('approved_version_id')->nullable();
            $table->text('status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'job_application_id'])
                ->references(['workspace_id', 'id'])->on('job_applications')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE application_resumes ADD CONSTRAINT application_resumes_status_check CHECK (status IN ('draft', 'approved'))");
        DB::statement("ALTER TABLE application_resumes ADD CONSTRAINT application_resumes_approved_check CHECK (status <> 'approved' OR (approved_version_id IS NOT NULL AND approved_by IS NOT NULL))");

        Schema::create('application_resume_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_resume_id')->constrained()->cascadeOnDelete();
            $table->integer('version_no');
            $table->jsonb('document');
            // The library facts the document points at, as they were when the version was saved
            // (employer, titles, dates, bullet wording…), so a sent resume can be shown as sent.
            $table->jsonb('facts_snapshot');
            $table->text('author_type')->default('user');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('created_via')->default('manual');
            $table->unsignedBigInteger('parent_version_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['application_resume_id', 'version_no']);
        });

        DB::statement("ALTER TABLE application_resume_versions ADD CONSTRAINT application_resume_versions_author_type_check CHECK (author_type IN ('user', 'ai'))");
        DB::unprepared('CREATE TRIGGER application_resume_versions_no_update BEFORE UPDATE ON application_resume_versions FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update()');
        DB::statement('ALTER TABLE application_resumes ADD CONSTRAINT application_resumes_current_version_foreign FOREIGN KEY (current_version_id) REFERENCES application_resume_versions (id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE application_resumes ADD CONSTRAINT application_resumes_approved_version_foreign FOREIGN KEY (approved_version_id) REFERENCES application_resume_versions (id) DEFERRABLE INITIALLY DEFERRED');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE application_resumes DROP CONSTRAINT IF EXISTS application_resumes_current_version_foreign');
        DB::statement('ALTER TABLE application_resumes DROP CONSTRAINT IF EXISTS application_resumes_approved_version_foreign');
        Schema::dropIfExists('application_resume_versions');
        Schema::dropIfExists('application_resumes');
    }
};
