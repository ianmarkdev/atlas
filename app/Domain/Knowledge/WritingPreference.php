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
 * A learned rule keeps links to the edits it was seen in (`supportingEdits`); an edit that fixed
 * what a rule the writer had already asks for is kept as a miss (`missedIn`), a sign the rule is
 * unclear or not followed.
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
 * @property-read Collection<int, FeedbackEvent> $missedIn
 * @property-read int|null $supporting_edits_count
 * @property-read int|null $missed_in_count
 */
#[UsePolicy(WritingPreferencePolicy::class)]
class WritingPreference extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** At most this many rules go into one writer's prompt. */
    public const MAX_PROMPT_RULES = 20;

    /** A rule added by hand ranks like one seen in this many edits. */
    public const MANUAL_SUPPORT = 2;

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
     * The rules for a writer's prompt, most important first, as many as fit `$maxCharacters`
     * (as render() lines) and at most MAX_PROMPT_RULES: those the writer has missed before
     * (`missed_in_count`, they need the prominence), then the best supported
     * (`supporting_edits_count`; a rule added by hand counts as MANUAL_SUPPORT edits), then the
     * oldest. A rule too long for what is left is passed over for shorter ones after it.
     *
     * @param  Collection<int, self>  $preferences  loaded with withCount(['supportingEdits', 'missedIn'])
     * @return Collection<int, self>
     */
    public static function forPrompt(Collection $preferences, int $maxCharacters): Collection
    {
        $ranked = $preferences->sortBy([
            fn (self $a, self $b): int => (int) $b->missed_in_count <=> (int) $a->missed_in_count,
            fn (self $a, self $b): int => $b->support() <=> $a->support(),
            fn (self $a, self $b): int => $a->id <=> $b->id,
        ]);
        $chosen = new Collection;
        $characters = 0;

        foreach ($ranked as $preference) {
            $length = mb_strlen("- {$preference->line()}") + 1;

            if ($characters + $length > $maxCharacters) {
                continue;
            }

            $chosen->push($preference);
            $characters += $length;

            if ($chosen->count() >= self::MAX_PROMPT_RULES) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * How well a rule is supported: the edits it was seen in, or MANUAL_SUPPORT for a rule
     * someone added by hand (stated outright, so as sure as a rule seen twice).
     */
    private function support(): int
    {
        $edits = (int) $this->supporting_edits_count;

        return $this->created_via === 'manual' ? max($edits, self::MANUAL_SUPPORT) : $edits;
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
            $lines[] = "- {$preference->line()}";
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * The rules as the writer was given them (render()), kept on the version so a later edit of
     * a rule does not change what an earlier draft was written with.
     *
     * @param  iterable<self>  $preferences
     * @return list<array{id: int, line: string}>
     */
    public static function snapshot(iterable $preferences): array
    {
        $rules = [];

        foreach ($preferences as $preference) {
            $rules[] = ['id' => $preference->id, 'line' => $preference->line()];
        }

        return $rules;
    }

    /**
     * The rules as tagged lines with an id (`R{id}`) the AI can name, for the feedback analysis.
     *
     * @param  iterable<self>  $preferences
     */
    public static function renderWithIds(iterable $preferences): string
    {
        $lines = [];

        foreach ($preferences as $preference) {
            $lines[] = sprintf('<rule id="R%d">%s</rule>', $preference->id, htmlspecialchars($preference->line(), ENT_NOQUOTES));
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * "(Kind, where) rule".
     */
    private function line(): string
    {
        $where = $this->drafts === WritingPreferenceDrafts::All ? '' : ', '.mb_strtolower($this->drafts->label());

        return "({$this->kind->label()}{$where}) {$this->rule}";
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
     * Edits that fixed by hand what this rule asks for, in drafts written with it.
     *
     * @return BelongsToMany<FeedbackEvent, $this>
     */
    public function missedIn(): BelongsToMany
    {
        return $this->belongsToMany(FeedbackEvent::class, 'writing_preference_misses')
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
