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
        // The writing preferences an AI version was written with (ids), to tell a rule the writer
        // missed from one it never had.
        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->jsonb('writing_rule_ids')->nullable()->after('evidence_pack');
        });

        // Edits that fixed by hand what a rule the writer was given already asked for.
        Schema::create('writing_preference_misses', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('writing_preference_id');
            $table->foreignId('feedback_event_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['writing_preference_id', 'feedback_event_id']);
            $table->foreign(['workspace_id', 'writing_preference_id'])
                ->references(['workspace_id', 'id'])->on('writing_preferences')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'feedback_event_id'])
                ->references(['workspace_id', 'id'])->on('feedback_events')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('writing_preference_misses');

        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->dropColumn('writing_rule_ids');
        });
    }
};
