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
        // M9: what happened to each unconfirmed claim and each AI question of an AI draft.
        // `item_index` points into the version's `grounding` (claims) or `clarification_questions`.
        Schema::create('answer_claim_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_draft_version_id')->constrained()->cascadeOnDelete();
            $table->text('item_type');
            $table->unsignedSmallInteger('item_index');
            $table->text('decision');
            // The bullet point an "add to library" decision created.
            $table->foreignId('claim_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['answer_draft_version_id', 'item_type', 'item_index']);
        });

        DB::statement("ALTER TABLE answer_claim_reviews ADD CONSTRAINT answer_claim_reviews_item_type_check CHECK (item_type IN ('claim', 'question'))");
        DB::statement("ALTER TABLE answer_claim_reviews ADD CONSTRAINT answer_claim_reviews_decision_check CHECK (decision IN ('accepted', 'added', 'dismissed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('answer_claim_reviews');
    }
};
