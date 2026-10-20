<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PostgreSQL extensions the schema relies on: pgvector (embeddings), pg_trgm
     * (near-duplicate detection) and citext (case-insensitive emails).
     *
     * @var list<string>
     */
    private array $extensions = ['vector', 'pg_trgm', 'citext'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->extensions as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->extensions) as $extension) {
            DB::statement("DROP EXTENSION IF EXISTS {$extension}");
        }
    }
};
