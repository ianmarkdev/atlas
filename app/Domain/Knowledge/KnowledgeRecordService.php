<?php

namespace App\Domain\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes employments and projects. Records the candidate creates are verified straight away
 * (their own entry is the confirmation); records a VA creates stay proposed until the
 * candidate verifies them. Every write is audited.
 */
class KnowledgeRecordService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @template TRecord of Employment|Project|Credential
     *
     * @param  TRecord  $record  unsaved model, already filled with validated attributes
     * @return TRecord
     */
    public function create(Employment|Project|Credential $record, User $actor): Employment|Project|Credential
    {
        return DB::transaction(function () use ($record, $actor): Employment|Project|Credential {
            $record->candidate_profile_id = CandidateProfile::current()->id;
            $record->created_by = $actor->id;

            if ($actor->isCandidate()) {
                $this->markVerified($record, $actor);
            } else {
                $record->status = KnowledgeStatus::Proposed;
            }

            $record->save();

            $this->audit->record($record->getMorphClass().'.created', $record, ['status' => $record->status->value], $actor);

            return $record;
        });
    }

    /**
     * Apply an edit made from a form that was loaded at `$expectedLockVersion`.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException when someone else saved the record in the meantime
     */
    public function update(Employment|Project|Credential $record, array $attributes, int $expectedLockVersion, User $actor): void
    {
        DB::transaction(function () use ($record, $attributes, $expectedLockVersion, $actor): void {
            $current = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($current->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => __('Someone else changed this record while you were editing. Reload the page to see their version, then apply your change again.'),
                ]);
            }

            $record->fill($attributes);
            $record->lock_version = $expectedLockVersion + 1;
            $record->save();

            $this->audit->record($record->getMorphClass().'.updated', $record, ['fields' => array_keys($record->getChanges())], $actor);
        });
    }

    public function verify(Employment|Project|Credential $record, User $actor): void
    {
        $this->transition($record, $actor, 'verified', function () use ($record, $actor): void {
            $this->markVerified($record, $actor);
        });
    }

    public function archive(Employment|Project|Credential $record, User $actor): void
    {
        $this->transition($record, $actor, 'archived', function () use ($record): void {
            $record->status = KnowledgeStatus::Archived;
        });
    }

    /**
     * Restored records come back as verified: only the candidate can restore.
     */
    public function restore(Employment|Project|Credential $record, User $actor): void
    {
        $this->transition($record, $actor, 'restored', function () use ($record, $actor): void {
            $this->markVerified($record, $actor);
        });
    }

    /**
     * @param  callable(): void  $change
     */
    private function transition(Employment|Project|Credential $record, User $actor, string $action, callable $change): void
    {
        DB::transaction(function () use ($record, $actor, $action, $change): void {
            $change();
            $record->lock_version++;
            $record->save();

            $this->audit->record($record->getMorphClass().'.'.$action, $record, [], $actor);
        });
    }

    private function markVerified(Employment|Project|Credential $record, User $actor): void
    {
        $record->status = KnowledgeStatus::Verified;
        $record->verified_by = $actor->id;
        $record->verified_at = now();
    }
}
