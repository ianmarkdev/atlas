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
        // Redrafting one paragraph: which one (0-based), and the version it is cut from, so an
        // edit saved meanwhile is never overwritten.
        Schema::table('answer_drafts', function (Blueprint $table) {
            $table->unsignedSmallInteger('generation_paragraph')->nullable()->after('generation_instruction');
            $table->foreignId('generation_base_version_id')->nullable()->after('generation_paragraph')
                ->constrained('answer_draft_versions')->nullOnDelete();
        });

        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->unsignedSmallInteger('paragraph')->nullable()->after('instruction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('answer_draft_versions', function (Blueprint $table) {
            $table->dropColumn('paragraph');
        });

        Schema::table('answer_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_base_version_id');
            $table->dropColumn('generation_paragraph');
        });
    }
};
