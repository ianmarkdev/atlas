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
 * @property string $language
 * @property LanguageProficiency $proficiency
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['workspace_id', 'candidate_profile_id', 'language', 'proficiency', 'position'])]
class CandidateLanguage extends Model
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proficiency' => LanguageProficiency::class,
        ];
    }
}
