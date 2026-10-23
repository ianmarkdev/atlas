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
        // Which drafts a rule shapes: all, cover letters or answers to application questions.
        Schema::table('writing_preferences', function (Blueprint $table) {
            $table->text('drafts')->default('all')->after('kind');
            $table->unique(['workspace_id', 'id']);
        });

        DB::statement("ALTER TABLE writing_preferences ADD CONSTRAINT writing_preferences_drafts_check CHECK (drafts IN ('all', 'cover_letter', 'question'))");

        Schema::table('feedback_events', function (Blueprint $table) {
            $table->unique(['workspace_id', 'id']);
        });

        // The edits a learned rule was seen in: the one it was suggested from and the earlier ones it repeated.
        Schema::create('writing_preference_sources', function (Blueprint $table) {
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
        Schema::dropIfExists('writing_preference_sources');

        Schema::table('feedback_events', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'id']);
        });

        DB::statement('ALTER TABLE writing_preferences DROP CONSTRAINT writing_preferences_drafts_check');

        Schema::table('writing_preferences', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'id']);
            $table->dropColumn('drafts');
        });
    }
};
