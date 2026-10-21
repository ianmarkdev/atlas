<?php

namespace App\Domain\Retrieval;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One searchable fact of the library (a bullet point, an approved library answer, or a job or
 * project summary), derived from its source by RetrievalIndexer. Never edited by hand.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $unit_type
 * @property string $source_type
 * @property int $source_id
 * @property int|null $source_revision_id
 * @property string|null $parent_type
 * @property int|null $parent_id
 * @property string $status
 * @property string $search_text
 * @property string|null $employer_normalized
 * @property CarbonImmutable|null $date_to
 * @property float $specificity_score
 * @property string $content_hash
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RetrievalUnit extends Model
{
    use BelongsToWorkspace;

    /**
     * Written only through RetrievalIndexer.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The generated `tsv` column is never read back.
     *
     * @var list<string>
     */
    protected $hidden = ['tsv'];

    /**
     * @return HasMany<Embedding, $this>
     */
    public function embeddings(): HasMany
    {
        return $this->hasMany(Embedding::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_to' => 'date',
            'specificity_score' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
