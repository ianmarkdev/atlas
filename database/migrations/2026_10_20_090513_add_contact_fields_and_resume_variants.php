<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->string('phone', 64)->nullable();
            $table->string('location')->nullable();
        });

        // Contact email, shared by every resume (case-insensitive, like logins).
        DB::statement('ALTER TABLE candidate_profiles ADD COLUMN email citext NULL');

        Schema::create('resume_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->text('status')->default('active');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'candidate_profile_id'])
                ->references(['workspace_id', 'id'])->on('candidate_profiles')->cascadeOnDelete();
            $table->unique(['workspace_id', 'id']);
            $table->index(['candidate_profile_id', 'status']);
        });

        DB::statement("ALTER TABLE resume_variants ADD CONSTRAINT resume_variants_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('CREATE UNIQUE INDEX resume_variants_one_default ON resume_variants (candidate_profile_id) WHERE is_default');

        Schema::create('resume_variant_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('revision_no');
            $table->jsonb('document');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('created_via')->default('manual');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['resume_variant_id', 'revision_no']);
        });

        DB::statement('ALTER TABLE resume_variants ADD CONSTRAINT resume_variants_current_revision_foreign FOREIGN KEY (current_revision_id) REFERENCES resume_variant_revisions (id) DEFERRABLE INITIALLY DEFERRED');

        // Append-only, like claim revisions (function created with the claims tables).
        DB::unprepared('CREATE TRIGGER resume_variant_revisions_no_update BEFORE UPDATE ON resume_variant_revisions FOR EACH ROW EXECUTE FUNCTION knowledge_history_prevent_update()');

        $this->moveHeadlinesIntoDefaultResumes();

        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropColumn('headline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->string('headline')->nullable();
        });

        DB::statement('ALTER TABLE resume_variants DROP CONSTRAINT IF EXISTS resume_variants_current_revision_foreign');
        Schema::dropIfExists('resume_variant_revisions');
        Schema::dropIfExists('resume_variants');

        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropColumn(['phone', 'location', 'email']);
        });
    }

    /**
     * Each profile with a headline gets a default resume whose title is that headline.
     */
    private function moveHeadlinesIntoDefaultResumes(): void
    {
        $profiles = DB::table('candidate_profiles')->whereNotNull('headline')->get(['id', 'workspace_id', 'user_id', 'headline']);

        foreach ($profiles as $profile) {
            $variantId = DB::table('resume_variants')->insertGetId([
                'ulid' => Str::lower((string) Str::ulid()),
                'workspace_id' => $profile->workspace_id,
                'candidate_profile_id' => $profile->id,
                'name' => 'General',
                'is_default' => true,
                'created_by' => $profile->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $revisionId = DB::table('resume_variant_revisions')->insertGetId([
                'workspace_id' => $profile->workspace_id,
                'resume_variant_id' => $variantId,
                'revision_no' => 1,
                'document' => json_encode([
                    'schema_version' => 1,
                    'title' => $profile->headline,
                    'summary' => null,
                    'experience' => [],
                    'projects' => [],
                ]),
                'created_by' => $profile->user_id,
                'created_via' => 'migration:headline',
            ]);

            DB::table('resume_variants')->where('id', $variantId)->update(['current_revision_id' => $revisionId]);
        }
    }
};
