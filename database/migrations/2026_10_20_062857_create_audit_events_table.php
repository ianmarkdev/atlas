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
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('actor_type');
            $table->text('action');
            $table->text('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('ip_hash', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_events_actor_type_check CHECK (actor_type IN ('user', 'system', 'job'))");

        // Append-only: rows may be inserted, or removed by a workspace cascade, but never edited.
        // OR REPLACE: `migrate:fresh` drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_prevent_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_no_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_prevent_update();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::statement('DROP FUNCTION IF EXISTS audit_events_prevent_update()');
    }
};
