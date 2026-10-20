<?php

namespace App\Domain\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only writer of claims. A claim the candidate enters is verified at once (their entry is the
 * confirmation, recorded as a `user_confirmation` source); a VA's claim stays proposed until the
 * candidate verifies or rejects it. Every statement change appends a revision.
 */
class ClaimService
{
    public function __construct(
        private DuplicateClaimDetector $duplicates,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  Model|null  $subject  employment, project or credential; null for a general claim
     * @param  bool  $allowNearDuplicate  the user saw the "similar claim" warning and chose to save anyway
     *
     * @throws ValidationException on an exact duplicate, or a near duplicate that was not confirmed
     */
    public function create(string $statement, ClaimKind $kind, ?Model $subject, User $actor, bool $allowNearDuplicate = false): Claim
    {
        $profile = CandidateProfile::current();

        $this->guardDuplicates($profile->id, $statement, $subject, null, $allowNearDuplicate);

        return $this->translateRace(fn (): Claim => DB::transaction(function () use ($profile, $statement, $kind, $subject, $actor): Claim {
            $claim = new Claim;
            $claim->forceFill([
                'candidate_profile_id' => $profile->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'kind' => $kind,
                'status' => ClaimStatus::Proposed,
                'origin' => 'user_entered',
                'normalized_hash' => Claim::hashStatement($statement),
                'created_by' => $actor->id,
            ])->save();

            $revision = $this->appendRevision($claim, $statement, null, $actor);

            if ($actor->isCandidate()) {
                $this->markVerified($claim, $revision, $actor);
            }

            $claim->save();

            $this->audit->record('claim.created', $claim, ['status' => $claim->status->value], $actor);

            return $claim;
        }));
    }

    /**
     * Add one claim per non-empty line. Exact duplicates (of existing claims or of earlier lines)
     * are skipped, not rejected, so a pasted list never fails as a whole.
     *
     * @param  list<string>  $statements  already normalised, non-empty
     * @return array{created: list<Claim>, skipped: int}
     */
    public function createMany(array $statements, ClaimKind $kind, ?Model $subject, User $actor): array
    {
        $created = [];
        $skipped = 0;
        $seen = [];

        foreach ($statements as $statement) {
            $hash = Claim::hashStatement($statement);

            if (isset($seen[$hash])) {
                $skipped++;

                continue;
            }
            $seen[$hash] = true;

            try {
                $created[] = $this->create($statement, $kind, $subject, $actor, allowNearDuplicate: true);
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Change the statement and/or kind of a claim that was loaded at `$expectedLockVersion`.
     * A new revision is appended only when the statement changes. When the candidate edits a
     * verified claim, the new revision is confirmed by that edit.
     *
     * @throws ValidationException on a stale edit or a duplicate
     */
    public function revise(Claim $claim, string $statement, ClaimKind $kind, ?string $changeReason, int $expectedLockVersion, User $actor, bool $allowNearDuplicate = false, string $via = 'manual'): void
    {
        $statementChanged = Claim::hashStatement($statement) !== $claim->normalized_hash
            || $statement !== $claim->statement();

        if ($statementChanged) {
            $this->guardDuplicates($claim->candidate_profile_id, $statement, $claim->subject, $claim->id, $allowNearDuplicate);
        }

        $this->translateRace(fn () => DB::transaction(function () use ($claim, $statement, $kind, $changeReason, $expectedLockVersion, $actor, $statementChanged, $via): void {
            $current = Claim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if ($current->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => __('Someone else changed this claim while you were editing. Reload the page to see their version, then apply your change again.'),
                ]);
            }

            if ($statementChanged) {
                $revision = $this->appendRevision($claim, $statement, $changeReason, $actor, $via);
                $claim->normalized_hash = Claim::hashStatement($statement);

                if ($claim->status === ClaimStatus::Verified) {
                    $this->markVerified($claim, $revision, $actor);
                }
            }

            $claim->kind = $kind;
            $claim->lock_version = $expectedLockVersion + 1;
            $claim->save();

            $this->audit->record('claim.revised', $claim, ['statement_changed' => $statementChanged], $actor);
        }));
    }

    public function verify(Claim $claim, User $actor): void
    {
        $this->transition($claim, $actor, 'verified', function () use ($claim, $actor): void {
            $this->markVerified($claim, $claim->currentRevision ?? throw new LogicException('Claim has no revision.'), $actor);
        });
    }

    public function reject(Claim $claim, User $actor): void
    {
        $this->transition($claim, $actor, 'rejected', function () use ($claim): void {
            $claim->status = ClaimStatus::Rejected;
        });
    }

    public function archive(Claim $claim, User $actor): void
    {
        $this->transition($claim, $actor, 'archived', function () use ($claim): void {
            $claim->status = ClaimStatus::Archived;
        });
    }

    /**
     * Restored claims come back verified (only the candidate restores), unless the same statement
     * has been added again in the meantime.
     *
     * @throws ValidationException when a live claim with the same statement exists
     */
    public function restore(Claim $claim, User $actor): void
    {
        $this->guardDuplicates($claim->candidate_profile_id, $claim->statement(), $claim->subject, $claim->id, allowNearDuplicate: true);

        $this->transition($claim, $actor, 'restored', function () use ($claim, $actor): void {
            $this->markVerified($claim, $claim->currentRevision ?? throw new LogicException('Claim has no revision.'), $actor);
        });
    }

    /**
     * @throws ValidationException
     */
    private function guardDuplicates(int $candidateProfileId, string $statement, ?Model $subject, ?int $exceptClaimId, bool $allowNearDuplicate): void
    {
        if ($this->duplicates->exactDuplicate($candidateProfileId, $statement, $exceptClaimId) !== null) {
            throw ValidationException::withMessages([
                'statement' => __('You already have this claim.'),
            ]);
        }

        if ($allowNearDuplicate) {
            return;
        }

        $similar = $this->duplicates->nearDuplicates($candidateProfileId, $statement, $subject, $exceptClaimId);

        if ($similar->isNotEmpty()) {
            throw ValidationException::withMessages([
                'statement' => __('This looks very similar to a claim you already have: ":existing". Save anyway if it is different.', [
                    'existing' => $similar->first()->statement(),
                ]),
                'near_duplicate' => 'true',
            ]);
        }
    }

    private function appendRevision(Claim $claim, string $statement, ?string $changeReason, User $actor, string $via = 'manual'): ClaimRevision
    {
        $nextNumber = (int) ClaimRevision::query()->where('claim_id', $claim->id)->max('revision_no') + 1;

        $revision = ClaimRevision::create([
            'claim_id' => $claim->id,
            'revision_no' => $nextNumber,
            'statement' => $statement,
            'change_reason' => $changeReason,
            'created_by' => $actor->id,
            'created_via' => $via,
        ]);

        $claim->current_revision_id = $revision->id;
        $claim->setRelation('currentRevision', $revision);

        return $revision;
    }

    /**
     * Verification is always tied to a specific revision through a `user_confirmation` source.
     */
    private function markVerified(Claim $claim, ClaimRevision $revision, User $actor): void
    {
        $claim->status = ClaimStatus::Verified;
        $claim->verified_by = $actor->id;
        $claim->verified_at = now();

        ClaimSource::create([
            'claim_id' => $claim->id,
            'claim_revision_id' => $revision->id,
            'source_type' => 'user_confirmation',
            'confirmed_by' => $actor->id,
        ]);
    }

    /**
     * @param  callable(): void  $change
     */
    private function transition(Claim $claim, User $actor, string $action, callable $change): void
    {
        $this->translateRace(fn () => DB::transaction(function () use ($claim, $actor, $action, $change): void {
            $change();
            $claim->lock_version++;
            $claim->save();

            $this->audit->record('claim.'.$action, $claim, [], $actor);
        }));
    }

    /**
     * Two requests adding the same statement at once both pass the duplicate check; the partial
     * unique index rejects the second, which is reported like any other duplicate.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $write
     * @return TReturn
     */
    private function translateRace(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'statement' => __('You already have this claim.'),
            ]);
        }
    }
}
