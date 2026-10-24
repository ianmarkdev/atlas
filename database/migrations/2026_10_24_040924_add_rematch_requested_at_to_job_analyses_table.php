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
        // "Refresh evidence" runs in the background (it needs the local embedding model): set while
        // a re-match is queued or running, cleared when it ends.
        Schema::table('job_analyses', function (Blueprint $table) {
            $table->timestampTz('rematch_requested_at')->nullable()->after('matched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_analyses', function (Blueprint $table) {
            $table->dropColumn('rematch_requested_at');
        });
    }
};
