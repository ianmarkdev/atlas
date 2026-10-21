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
        // Embedding models in use (D11: local Ollama, bge-m3). Switching models adds a row and a
        // backfill; reads flip once the new vectors exist.
        Schema::create('embedding_models', function (Blueprint $table) {
            $table->id();
            $table->text('provider');
            $table->text('model');
            $table->integer('dimensions');
            $table->text('distance')->default('cosine');
            $table->boolean('is_active_for_write')->default(false);
            $table->boolean('is_active_for_read')->default(false);
            $table->timestampsTz();

            $table->unique(['provider', 'model', 'dimensions']);
        });

        DB::statement("ALTER TABLE embedding_models ADD CONSTRAINT embedding_models_distance_check CHECK (distance IN ('cosine'))");

        // One vector per retrieval unit and model. `halfvec` without a fixed dimension, stored inline
        // (STORAGE PLAIN): S3 found it 5x smaller and 3-5x faster to scan than TOASTed `vector`.
        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retrieval_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('embedding_model_id')->constrained()->cascadeOnDelete();
            $table->char('content_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['retrieval_unit_id', 'embedding_model_id']);
            $table->index(['workspace_id', 'embedding_model_id']);
        });

        DB::statement('ALTER TABLE embeddings ADD COLUMN embedding halfvec NOT NULL');
        DB::statement('ALTER TABLE embeddings ALTER COLUMN embedding SET STORAGE PLAIN');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
        Schema::dropIfExists('embedding_models');
    }
};
