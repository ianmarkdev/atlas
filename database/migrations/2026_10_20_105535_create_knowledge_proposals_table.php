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
        // A suggested change to a verified fact. The target is unchanged until the candidate approves (D5).
        Schema::create('knowledge_proposals', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('origin')->default('manual');
            $table->text('proposal_type');
            $table->text('target_type');
            $table->unsignedBigInteger('target_id');
            // Claims: the current revision id; other records: their lock_version — for conflict detection.
            $table->bigInteger('base_version');
            $table->jsonb('payload');
            $table->text('rationale')->nullable();
            $table->text('status')->default('pending');
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'superseded', 'conflict'))");
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_type_check CHECK (proposal_type IN ('revise_claim', 'revise_record'))");
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_origin_check CHECK (origin IN ('manual', 'feedback', 'achievement_suggestion', 'clarification'))");
        DB::statement("ALTER TABLE knowledge_proposals ADD CONSTRAINT knowledge_proposals_target_type_check CHECK (target_type IN ('claim', 'employment', 'project', 'credential'))");
        // At most one pending change per item: a newer suggestion supersedes the older one.
        DB::statement("CREATE UNIQUE INDEX knowledge_proposals_one_pending ON knowledge_proposals (target_type, target_id) WHERE status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_proposals');
    }
};
