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
        // AI analysis of an application's job description (M7). One row per run, tied to the exact
        // posting it read; re-analysing adds a row. `brief` is the validated model output plus the
        // server's checks; `matches` is the requirement ↔ evidence matrix (refreshable).
        Schema::create('job_analyses', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id');
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('prompt_version')->nullable();
            $table->text('status')->default('queued');
            $table->jsonb('brief')->nullable();
            $table->jsonb('matches')->nullable();
            $table->timestampTz('matched_at')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'job_application_id'])
                ->references(['workspace_id', 'id'])->on('job_applications')->cascadeOnDelete();
            $table->index(['job_application_id', 'created_at']);
        });

        DB::statement("ALTER TABLE job_analyses ADD CONSTRAINT job_analyses_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'invalid_output'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_analyses');
    }
};
