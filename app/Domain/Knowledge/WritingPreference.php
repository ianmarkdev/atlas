<?php

namespace App\Domain\Knowledge;

use App\Domain\Feedback\FeedbackEvent;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use App\Policies\WritingPreferencePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A rule for how answers are written (M10), e.g. "British spelling" or "Never say passionate".
 * Profile rules apply to every draft written for the candidate; a user's personal rules only to
 * the drafts that user asks for. A rule may be only for cover letters or only for answers to
 * application questions (`drafts`). Always given to the writer in full, never retrieved or embedded.
 * A learned rule keeps links to the edits it was seen in (`supportingEdits`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $scope profile|user
 * @property int|null $user_id
 * @property string $rule
 * @property WritingPreferenceKind $kind
 * @property WritingPreferenceDrafts $drafts
 * @property string $status active|archived
 * @property string $created_via manual|proposal:{id}
 * @property int|null $created_by
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, FeedbackEvent> $supportingEdits
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
     * Rules for drafts of this kind: those for all drafts, plus those only for it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForDrafts(Builder $query, WritingPreferenceDrafts $drafts): void
    {
        $query->whereIn('drafts', array_unique([WritingPreferenceDrafts::All->value, $drafts->value]));
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
            $where = $preference->drafts === WritingPreferenceDrafts::All ? '' : ', '.mb_strtolower($preference->drafts->label());
            $lines[] = "- ({$preference->kind->label()}{$where}) {$preference->rule}";
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
     * @return BelongsToMany<FeedbackEvent, $this>
     */
    public function supportingEdits(): BelongsToMany
    {
        return $this->belongsToMany(FeedbackEvent::class, 'writing_preference_sources')
            ->withPivot(['workspace_id', 'created_at']);
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
            'drafts' => WritingPreferenceDrafts::class,
        ];
    }
}
