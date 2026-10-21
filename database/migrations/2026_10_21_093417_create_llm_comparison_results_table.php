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
        // Model comparison (M4): the same synthetic sample run through several providers, with
        // output, latency and validation results, scored blind by a person.
        Schema::create('llm_comparison_results', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->ulid('batch');
            $table->text('sample_key');
            $table->text('task');
            $table->text('provider');
            $table->text('model')->nullable();
            $table->char('blind_label', 1);
            $table->text('status');
            $table->jsonb('output')->nullable();
            $table->jsonb('validation_errors')->default('[]');
            $table->jsonb('grounding_flags')->default('[]');
            $table->text('error_message')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->foreignId('generation_run_id')->nullable()->constrained()->nullOnDelete();
            // Human scores, 1–5 each: factual accuracy, specificity, writing quality, instruction compliance.
            $table->smallInteger('score_accuracy')->nullable();
            $table->smallInteger('score_specificity')->nullable();
            $table->smallInteger('score_writing')->nullable();
            $table->smallInteger('score_compliance')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('rated_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'batch']);
        });

        DB::statement("ALTER TABLE llm_comparison_results ADD CONSTRAINT llm_comparison_results_status_check CHECK (status IN ('succeeded', 'invalid_output', 'failed', 'skipped'))");

        foreach (['accuracy', 'specificity', 'writing', 'compliance'] as $score) {
            DB::statement("ALTER TABLE llm_comparison_results ADD CONSTRAINT llm_comparison_results_score_{$score}_check CHECK (score_{$score} IS NULL OR score_{$score} BETWEEN 1 AND 5)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_comparison_results');
    }
};
