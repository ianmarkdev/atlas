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
        // A batch becomes a baseline other batches are compared with (app:eval-report): which
        // prompt version ran, a hash of the sample's inputs (only unchanged samples are compared),
        // the server's automatic checks, and two more human scores (rubric 2).
        Schema::table('llm_comparison_results', function (Blueprint $table) {
            $table->text('prompt_key')->nullable()->after('task');
            $table->integer('prompt_version')->nullable()->after('prompt_key');
            $table->char('input_hash', 64)->nullable()->after('prompt_version');
            $table->jsonb('checks')->default('{}')->after('grounding_flags');
            $table->smallInteger('rubric_version')->default(1)->after('generation_run_id');
            $table->smallInteger('score_relevance')->nullable()->after('score_accuracy');
            $table->smallInteger('score_evidence')->nullable()->after('score_relevance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('llm_comparison_results', function (Blueprint $table) {
            $table->dropColumn(['prompt_key', 'prompt_version', 'input_hash', 'checks', 'rubric_version', 'score_relevance', 'score_evidence']);
        });
    }
};
