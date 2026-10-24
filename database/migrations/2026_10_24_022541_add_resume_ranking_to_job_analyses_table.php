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
        // Which resume best covers the analysed requirements with its own bullet points
        // (ResumeRanker), worked out with the matches and kept with the resume revisions it read.
        Schema::table('job_analyses', function (Blueprint $table) {
            $table->jsonb('resume_ranking')->nullable()->after('matches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_analyses', function (Blueprint $table) {
            $table->dropColumn('resume_ranking');
        });
    }
};
