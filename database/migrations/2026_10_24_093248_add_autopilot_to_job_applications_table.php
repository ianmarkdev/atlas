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
        // Prepare in the background (ApplicationAutopilot): analysis, questions, answers, cover letter
        // and tailored resume, one step per scheduler run, for review on return.
        Schema::table('job_applications', function (Blueprint $table) {
            $table->text('autopilot_status')->nullable();
            $table->text('autopilot_note')->nullable();
            $table->foreignId('autopilot_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autopilot_requested_at')->nullable();
            $table->timestamp('autopilot_questions_added_at')->nullable();
            $table->timestamp('autopilot_finished_at')->nullable();
            $table->index(['workspace_id', 'autopilot_status']);
        });

        DB::statement("ALTER TABLE job_applications ADD CONSTRAINT job_applications_autopilot_status_check CHECK (autopilot_status IS NULL OR autopilot_status IN ('running', 'ready', 'stopped'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE job_applications DROP CONSTRAINT IF EXISTS job_applications_autopilot_status_check');

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'autopilot_status']);
            $table->dropConstrainedForeignId('autopilot_requested_by');
            $table->dropColumn(['autopilot_status', 'autopilot_note', 'autopilot_requested_at', 'autopilot_questions_added_at', 'autopilot_finished_at']);
        });
    }
};
