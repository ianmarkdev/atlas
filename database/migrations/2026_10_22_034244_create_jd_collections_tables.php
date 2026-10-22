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
        // Groups of applications (M7), e.g. "Fintech roles"; a collection marked `use_for_eval`
        // is the real-world set `app:eval-analysis` re-runs when prompts or models change (D7).
        Schema::create('jd_collections', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->boolean('use_for_eval')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'id']);
        });

        // Many-to-many: one application can sit in several collections.
        Schema::create('jd_collection_items', function (Blueprint $table) {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jd_collection_id');
            $table->foreignId('job_application_id');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('added_at')->useCurrent();

            $table->primary(['jd_collection_id', 'job_application_id']);
            $table->foreign(['workspace_id', 'jd_collection_id'])
                ->references(['workspace_id', 'id'])->on('jd_collections')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'job_application_id'])
                ->references(['workspace_id', 'id'])->on('job_applications')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jd_collection_items');
        Schema::dropIfExists('jd_collections');
    }
};
