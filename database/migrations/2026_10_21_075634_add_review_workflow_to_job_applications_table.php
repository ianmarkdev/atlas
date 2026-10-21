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
        // The VA workflow (M7a slice D): who prepares the application, and the latest note
        // exchanged with the review (submit / request changes). The full history is the audit log.
        Schema::table('job_applications', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('owner_user_id')->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable()->after('review_status');
            $table->timestampTz('review_status_changed_at')->nullable()->after('review_note');

            $table->index(['workspace_id', 'assigned_user_id', 'review_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'assigned_user_id', 'review_status']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['review_note', 'review_status_changed_at']);
        });
    }
};
