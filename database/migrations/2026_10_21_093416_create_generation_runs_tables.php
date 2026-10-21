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
        // Every AI call (M4): which task, prompt version, provider and model, usage, cost and
        // outcome. No prompt or response text here — that lives (encrypted, briefly) in
        // generation_payloads.
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('task');
            $table->text('purpose')->nullable();
            $table->text('prompt_key');
            $table->integer('prompt_version');
            $table->text('provider');
            $table->text('model');
            $table->char('request_hash', 64);
            $table->text('idempotency_key')->nullable()->unique();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->bigInteger('cost_micros')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->integer('attempts')->default(0);
            $table->text('status')->default('running');
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['workspace_id', 'created_at']);
        });

        DB::statement("ALTER TABLE generation_runs ADD CONSTRAINT generation_runs_status_check CHECK (status IN ('running', 'succeeded', 'failed', 'invalid_output'))");

        // Prompt and response bodies for debugging: encrypted at rest, deleted after the retention period.
        Schema::create('generation_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('request');
            $table->text('response')->nullable();
            $table->text('output')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_payloads');
        Schema::dropIfExists('generation_runs');
    }
};
