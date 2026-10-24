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
        // How to apply: where the listing was found, the email subject it asks for, any video or
        // test it requires, and the angle to pitch. Filled by Career Clipper or the web form.
        Schema::table('job_applications', function (Blueprint $table) {
            $table->text('source')->nullable();
            $table->text('subject_line')->nullable();
            $table->text('apply_requirement')->nullable();
            $table->text('proposal_angle')->nullable();
        });

        DB::statement("ALTER TABLE job_applications ADD CONSTRAINT job_applications_apply_requirement_check CHECK (apply_requirement IS NULL OR apply_requirement IN ('video', 'test', 'video_and_test'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE job_applications DROP CONSTRAINT IF EXISTS job_applications_apply_requirement_check');

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn(['source', 'subject_line', 'apply_requirement', 'proposal_angle']);
        });
    }
};
