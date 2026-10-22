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
        // M8: an answer can be drafted by AI in the background. The draft tracks the request;
        // the AI-written version keeps what it was built from and the server's claim check.
        Schema::table('answer_drafts', function (Blueprint $table) {
            $table->text('generation_status')->nullable()->after('status');
            $table->text('generation_error')->nullable()->after('generation_status');
            $table->timestampTz('generation_requested_at')->nullable()->after('generation_error');
            $table->foreignId('generation_requested_by')->nullable()->after('generation_requested_at')->constrained('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE answer_drafts ADD CONSTRAINT answer_drafts_generation_status_check CHECK (generation_status IS NULL OR generation_status IN ('queued', 'running', 'failed'))");

        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->foreignId('generation_run_id')->nullable()->after('created_via')->constrained()->nullOnDelete();
            // The evidence the model was given (E1… → ref, status, text), the server-checked claims
            // and the model's questions for the candidate. Null for versions written by a person.
            $table->jsonb('evidence_pack')->nullable()->after('generation_run_id');
            $table->jsonb('grounding')->nullable()->after('evidence_pack');
            $table->jsonb('clarification_questions')->nullable()->after('grounding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_run_id');
            $table->dropColumn(['evidence_pack', 'grounding', 'clarification_questions']);
        });

        DB::statement('ALTER TABLE answer_drafts DROP CONSTRAINT IF EXISTS answer_drafts_generation_status_check');

        Schema::table('answer_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_requested_by');
            $table->dropColumn(['generation_status', 'generation_error', 'generation_requested_at']);
        });
    }
};
