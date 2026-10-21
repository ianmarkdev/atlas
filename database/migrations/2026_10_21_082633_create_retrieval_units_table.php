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
        // The search index over the library (M6): one row per searchable fact, with the text that
        // is searched (and embedded) and the filters retrieval needs. Rebuilt from the sources.
        Schema::create('retrieval_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('unit_type');
            $table->text('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_revision_id')->nullable();
            // The job or project a bullet point (or project) belongs to: results prefer the specific
            // unit over its parent's summary.
            $table->text('parent_type')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('status');
            $table->text('search_text');
            $table->text('employer_normalized')->nullable();
            $table->date('date_to')->nullable();
            $table->float('specificity_score')->default(0);
            $table->char('content_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['source_type', 'source_id']);
            $table->index(['workspace_id', 'candidate_profile_id', 'is_active', 'unit_type', 'status']);
        });

        DB::statement("ALTER TABLE retrieval_units ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', search_text)) STORED");
        DB::statement('CREATE INDEX retrieval_units_tsv ON retrieval_units USING gin (tsv)');
        DB::statement("ALTER TABLE retrieval_units ADD CONSTRAINT retrieval_units_unit_type_check CHECK (unit_type IN ('claim', 'library_answer', 'project_summary', 'employment_summary'))");
        DB::statement("ALTER TABLE retrieval_units ADD CONSTRAINT retrieval_units_status_check CHECK (status IN ('verified', 'proposed', 'approved', 'draft', 'rejected', 'archived'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retrieval_units');
    }
};
