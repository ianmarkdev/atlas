<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property LinkKind $kind
 * @property string|null $label
 * @property string $url
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['workspace_id', 'candidate_profile_id', 'kind', 'label', 'url', 'position'])]
class CandidateLink extends Model
{
    use BelongsToWorkspace, HasUlids;

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
     * Label shown on the resume: the custom label, or the kind ("GitHub").
     */
    public function displayLabel(): string
    {
        return $this->label ?? match ($this->kind) {
            LinkKind::Github => 'GitHub',
            LinkKind::Linkedin => 'LinkedIn',
            default => ucfirst($this->kind->value),
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LinkKind::class,
        ];
    }
}
