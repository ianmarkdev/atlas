<?php

namespace App\Domain\Applications;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named group of applications (e.g. "Fintech roles"). With `use_for_eval` its job
 * descriptions form the real-world evaluation set for analysis quality (`app:eval-analysis`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property bool $use_for_eval
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class JdCollection extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsToMany<JobApplication, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(JobApplication::class, 'jd_collection_items')
            ->withPivot(['workspace_id', 'added_by', 'added_at']);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'use_for_eval' => 'boolean',
        ];
    }
}
