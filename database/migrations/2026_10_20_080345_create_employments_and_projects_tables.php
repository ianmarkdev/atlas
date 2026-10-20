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
        Schema::create('employments', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->string('employer_name');
            $table->string('employer_normalized');
            $table->string('title');
            $table->text('employment_type')->nullable();
            $table->string('location')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->text('date_precision')->default('month');
            $table->text('summary')->nullable();
            $table->text('status');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            // A row can never point at another workspace's profile.
            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['candidate_profile_id', 'status', 'start_date']);
        });

        DB::statement("ALTER TABLE employments ADD CONSTRAINT employments_status_check CHECK (status IN ('verified', 'proposed', 'archived'))");
        DB::statement("ALTER TABLE employments ADD CONSTRAINT employments_type_check CHECK (employment_type IN ('full_time', 'part_time', 'contract', 'freelance', 'internship', 'other'))");
        DB::statement("ALTER TABLE employments ADD CONSTRAINT employments_date_precision_check CHECK (date_precision IN ('day', 'month', 'year'))");
        DB::statement('ALTER TABLE employments ADD CONSTRAINT employments_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE employments ADD CONSTRAINT employments_verified_check CHECK (status <> 'verified' OR verified_by IS NOT NULL)");

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->foreignId('employment_id')->nullable();
            $table->string('name');
            $table->string('client_name')->nullable();
            $table->string('role')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('summary')->nullable();
            $table->string('url', 2048)->nullable();
            $table->text('status');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['candidate_profile_id', 'status']);
            $table->index('employment_id');
        });

        // Same-workspace employment only; deleting it unlinks the project (only employment_id is nulled).
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_employment_foreign FOREIGN KEY (workspace_id, employment_id) REFERENCES employments (workspace_id, id) ON DELETE SET NULL (employment_id)');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('verified', 'proposed', 'archived'))");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_dates_check CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_verified_check CHECK (status <> 'verified' OR verified_by IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('employments');
    }
};
