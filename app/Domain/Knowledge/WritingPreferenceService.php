<?php

namespace App\Domain\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Domain\Feedback\FeedbackEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes writing preferences (M10). A rule for everyone is the candidate's call (a VA's becomes a
 * `create_writing_preference` proposal, see ProposalService); a personal rule is its owner's.
 */
class WritingPreferenceService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  User|null  $owner  the user a personal rule belongs to; null for a rule for everyone
     * @param  string  $via  `manual` or `proposal:{id}`
     * @param  list<int>  $supportedBy  ids of the feedback events the rule was seen in
     * @param  WritingPreference|null  $replacing  a rule this one replaces (a rewording, a merge, or the side
     *                                             of a contradiction that loses): archived, and its supporting edits carried over
     *
     * @throws ValidationException when the same rule is already active in that scope, or the rule to replace is no longer active
     */
    public function create(string $rule, WritingPreferenceKind $kind, ?User $owner, User $actor, string $via = 'manual', WritingPreferenceDrafts $drafts = WritingPreferenceDrafts::All, array $supportedBy = [], ?WritingPreference $replacing = null): WritingPreference
    {
        return DB::transaction(function () use ($rule, $kind, $owner, $actor, $via, $drafts, $supportedBy, $replacing): WritingPreference {
            if ($replacing !== null) {
                $replacing = WritingPreference::query()->whereKey($replacing->id)->lockForUpdate()->firstOrFail();

                if ($replacing->status !== 'active') {
                    throw ValidationException::withMessages(['replaces' => __('The rule to replace has been removed already.')]);
                }

                array_push($supportedBy, ...$replacing->supportingEdits()->pluck('feedback_events.id')->all());
                $this->archive($replacing, $actor);
            }

            if ($this->exists($rule, $owner)) {
                throw ValidationException::withMessages(['rule' => __('This rule is already in the list.')]);
            }

            $preference = new WritingPreference;
            $preference->forceFill([
                'candidate_profile_id' => CandidateProfile::current()->id,
                'scope' => $owner === null ? 'profile' : 'user',
                'user_id' => $owner?->id,
                'rule' => $rule,
                'kind' => $kind,
                'drafts' => $drafts,
                'status' => 'active',
                'created_via' => $via,
                'created_by' => $actor->id,
            ])->save();

            // Only events of this workspace that still exist.
            $events = FeedbackEvent::query()->whereKey(array_unique($supportedBy))->pluck('id');
            $preference->supportingEdits()->attach($events->mapWithKeys(fn (int $id): array => [$id => ['workspace_id' => $preference->workspace_id]])->all());

            $this->audit->record('writing_preference.created', $preference, ['scope' => $preference->scope, 'drafts' => $drafts->value, 'via' => $via, 'replaces' => $replacing?->ulid], $actor);

            return $preference;
        });
    }

    public function archive(WritingPreference $preference, User $actor): void
    {
        $preference->forceFill(['status' => 'archived', 'lock_version' => $preference->lock_version + 1])->save();

        $this->audit->record('writing_preference.archived', $preference, ['scope' => $preference->scope], $actor);
    }

    /**
     * Whether an active rule with the same wording applies in that scope (a personal rule also
     * counts as existing when the same rule applies to everyone).
     */
    public function exists(string $rule, ?User $owner): bool
    {
        $normalized = WritingPreference::normalizeRule($rule);

        return WritingPreference::query()
            ->appliesTo($owner)
            ->get(['rule'])
            ->contains(fn (WritingPreference $preference): bool => WritingPreference::normalizeRule($preference->rule) === $normalized);
    }
}
