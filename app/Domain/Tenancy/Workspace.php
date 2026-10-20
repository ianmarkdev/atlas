<?php

namespace App\Domain\Tenancy;

use App\Domain\Knowledge\CandidateProfile;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The tenant boundary. MVP: exactly one row, created by `app:install`.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property array<string, mixed> $settings
 * @property int $monthly_ai_budget_micros
 * @property int $budget_warn_pct
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'created_by'])]
#[UseFactory(WorkspaceFactory::class)]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return HasMany<CandidateProfile, $this>
     */
    public function candidateProfiles(): HasMany
    {
        return $this->hasMany(CandidateProfile::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
