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
        // The questions of one application form, in form order. The cover letter is a question too.
        Schema::create('application_questions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id');
            $table->text('origin')->default('manual');
            $table->text('prompt_text');
            $table->integer('word_limit')->nullable();
            $table->integer('char_limit')->nullable();
            $table->integer('position');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'job_application_id'])
                ->references(['workspace_id', 'id'])->on('job_applications')->cascadeOnDelete();
            $table->index(['job_application_id', 'position']);
        });

        DB::statement("ALTER TABLE application_questions ADD CONSTRAINT application_questions_origin_check CHECK (origin IN ('manual', 'cover_letter', 'extracted'))");
        DB::statement('ALTER TABLE application_questions ADD CONSTRAINT application_questions_limits_check CHECK ((word_limit IS NULL OR word_limit > 0) AND (char_limit IS NULL OR char_limit > 0))');
        DB::statement("CREATE UNIQUE INDEX application_questions_one_cover_letter ON application_questions (job_application_id) WHERE origin = 'cover_letter'");

        // The answer to one question. Versions are append-only; approving freezes the current one.
        Schema::create('answer_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_question_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('approved_version_id')->nullable();
            $table->text('status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_status_check CHECK (status IN ('draft', 'approved'))");
        DB::statement("ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_approved_check CHECK (status <> 'approved' OR (approved_version_id IS NOT NULL AND approved_by IS NOT NULL))");

        Schema::create('answer_draft_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_draft_id')->constrained()->cascadeOnDelete();
            $table->integer('version_no');
            $table->text('body');
            // Where the text came from: typed, or inserted from the answer library (then edited or not).
            $table->foreignId('library_entry_id')->nullable()->constrained('answer_library_entries')->nullOnDelete();
            $table->text('author_type')->default('user');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('created_via')->default('manual');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['answer_draft_id', 'version_no']);
        });

        DB::statement("ALTER TABLE answer_draft_versions ADD CONSTRAINT answer_draft_versions_author_type_check CHECK (author_type IN ('user', 'ai'))");
        DB::unprepared('CREATE TRIGGER answer_draft_versions_no_update BEFORE UPDATE ON answer_draft_versions FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update()');
        DB::statement('ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_current_version_foreign FOREIGN KEY (current_version_id) REFERENCES answer_draft_versions (id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_approved_version_foreign FOREIGN KEY (approved_version_id) REFERENCES answer_draft_versions (id) DEFERRABLE INITIALLY DEFERRED');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE answer_drafts DROP CONSTRAINT IF EXISTS answer_drafts_current_version_foreign');
        DB::statement('ALTER TABLE answer_drafts DROP CONSTRAINT IF EXISTS answer_drafts_approved_version_foreign');
        Schema::dropIfExists('answer_draft_versions');
        Schema::dropIfExists('answer_drafts');
        Schema::dropIfExists('application_questions');
    }
};
