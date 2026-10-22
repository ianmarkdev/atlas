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
        // Listings saved with the browser extension may not name the company (agency ads,
        // anonymised postings). The web form still asks for it.
        Schema::table('job_applications', function (Blueprint $table) {
            $table->text('company_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('job_applications')->whereNull('company_name')->update(['company_name' => '']);

        Schema::table('job_applications', function (Blueprint $table) {
            $table->text('company_name')->nullable(false)->change();
        });
    }
};
