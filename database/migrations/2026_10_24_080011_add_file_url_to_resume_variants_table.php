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
        Schema::table('resume_variants', function (Blueprint $table) {
            // Link to the resume as a file in cloud storage (PDF/DOCX), e.g. for onlinejobs.ph uploads.
            $table->string('file_url', 2048)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resume_variants', function (Blueprint $table) {
            $table->dropColumn('file_url');
        });
    }
};
