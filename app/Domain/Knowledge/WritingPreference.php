<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use App\Policies\WritingPreferencePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule for how answers are written (M10), e.g. "British spelling" or "Never say passionate".
 * Profile rules apply to every draft written for the candidate; a user's personal rules only to
 * the drafts that user asks for. Always given to the writer in full, never retrieved or embedded.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $scope profile|user
 * @property int|null $user_id
 * @property string $rule
 * @property WritingPreferenceKind $kind
 * @property string $status active|archived
 * @property string $created_via manual|proposal:{id}
 * @property int|null $created_by
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 */
#[UsePolicy(WritingPreferencePolicy::class)]
class WritingPreference extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * Written only through WritingPreferenceService.
     *
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

    public function isPersonal(): bool
    {
        return $this->scope === 'user';
    }

    /**
     * Active rules for drafts requested by `$user`: the profile's, plus the user's own.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAppliesTo(Builder $query, ?User $user): void
    {
        $query->where('status', 'active')
            ->where(fn (Builder $inner) => $inner
                ->where('scope', 'profile')
                ->when($user !== null, fn (Builder $own) => $own->orWhere('user_id', $user->id)));
    }

    /**
     * The rules as lines for a prompt.
     *
     * @param  iterable<self>  $preferences
     */
    public static function render(iterable $preferences): string
    {
        $lines = [];

        foreach ($preferences as $preference) {
            $lines[] = "- ({$preference->kind->label()}) {$preference->rule}";
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * The rule in a form for spotting the same rule twice.
     */
    public static function normalizeRule(string $rule): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($rule)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WritingPreferenceKind::class,
        ];
    }
}
