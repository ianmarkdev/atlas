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
        // A flag anyone preparing applications can set, from the extension when capturing or from
        // the app, so the listings worth extra care stand out on the board, the table and in Orbit.
        Schema::table('job_applications', function (Blueprint $table) {
            $table->boolean('is_important')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn('is_important');
        });
    }
};
