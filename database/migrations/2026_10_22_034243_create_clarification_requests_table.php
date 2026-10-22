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
        // Gaps from an analysis (M7): a must-have with no evidence becomes a question for the
        // candidate. An answer becomes a bullet point (proposed when a VA writes it).
        Schema::create('clarification_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_application_id');
            $table->foreignId('job_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->text('requirement');
            $table->char('requirement_hash', 64);
            $table->text('question_text');
            $table->text('status')->default('open');
            $table->text('answer_text')->nullable();
            $table->foreignId('converted_claim_id')->nullable()->constrained('claims')->nullOnDelete();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'job_application_id'])
                ->references(['workspace_id', 'id'])->on('job_applications')->cascadeOnDelete();
            $table->index(['job_application_id', 'status']);
        });

        DB::statement("ALTER TABLE clarification_requests ADD CONSTRAINT clarification_requests_status_check CHECK (status IN ('open', 'answered', 'dismissed', 'resolved'))");
        // One open question per requirement and application.
        DB::statement("CREATE UNIQUE INDEX clarification_requests_one_open ON clarification_requests (job_application_id, requirement_hash) WHERE status = 'open'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clarification_requests');
    }
};
