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
        Schema::table('users', function (Blueprint $table) {
            $table->ulid()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->string('name');
            $table->jsonb('settings')->default('{}');
            $table->bigInteger('monthly_ai_budget_micros')->default(30_000_000);
            $table->smallInteger('budget_warn_pct')->default(80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // MVP: a user belongs to exactly one workspace.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('role');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['workspace_id', 'role']);
        });

        DB::statement("ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_role_check CHECK (role IN ('candidate', 'va'))");
        DB::statement("CREATE UNIQUE INDEX workspace_memberships_one_candidate ON workspace_memberships (workspace_id) WHERE role = 'candidate'");

        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('headline')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_profiles');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ulid', 'is_active', 'last_login_at']);
        });
    }
};
