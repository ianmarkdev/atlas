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
        // Education and certifications: verifiable facts, same lifecycle as employments/projects.
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('type');
            $table->string('institution');
            $table->string('name');
            $table->string('field_of_study')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('credential_url', 2048)->nullable();
            $table->text('summary')->nullable();
            $table->text('status');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['candidate_profile_id', 'type', 'status']);
        });

        DB::statement("ALTER TABLE credentials ADD CONSTRAINT credentials_type_check CHECK (type IN ('education', 'certification'))");
        DB::statement("ALTER TABLE credentials ADD CONSTRAINT credentials_status_check CHECK (status IN ('verified', 'proposed', 'archived'))");
        DB::statement('ALTER TABLE credentials ADD CONSTRAINT credentials_dates_check CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE credentials ADD CONSTRAINT credentials_verified_check CHECK (status <> 'verified' OR verified_by IS NOT NULL)");

        // Skills (and later technologies/domains for tagging claims). One list per workspace.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('kind')->default('skill');
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'kind', 'normalized_name']);
        });

        DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_kind_check CHECK (kind IN ('skill', 'technology', 'domain'))");

        Schema::create('candidate_languages', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->string('language', 100);
            $table->text('proficiency');
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['candidate_profile_id', 'language']);
        });

        DB::statement("ALTER TABLE candidate_languages ADD CONSTRAINT candidate_languages_proficiency_check CHECK (proficiency IN ('native', 'fluent', 'professional', 'conversational', 'basic'))");

        Schema::create('candidate_links', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->text('kind');
            $table->string('label', 100)->nullable();
            $table->string('url', 2048);
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE candidate_links ADD CONSTRAINT candidate_links_kind_check CHECK (kind IN ('portfolio', 'github', 'linkedin', 'website', 'other'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_links');
        Schema::dropIfExists('candidate_languages');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('credentials');
    }
};
