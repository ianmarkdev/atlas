<?php

namespace App\Domain\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes the answer library. Answers the candidate saves are approved; a VA's new answer is a
 * draft until the candidate approves it. Every change to the answer text appends a revision.
 */
class AnswerLibraryService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<string>  $claimUlids  bullet points the answer relies on (already validated)
     */
    public function create(string $question, string $body, AnswerCategory $category, array $claimUlids, User $actor): AnswerLibraryEntry
    {
        return DB::transaction(function () use ($question, $body, $category, $claimUlids, $actor): AnswerLibraryEntry {
            $entry = new AnswerLibraryEntry;
            $entry->forceFill([
                'candidate_profile_id' => CandidateProfile::current()->id,
                'question' => $question,
                'category' => $category,
                'status' => AnswerStatus::Draft,
                'created_by' => $actor->id,
                'lock_version' => 0,
            ])->save();

            $this->appendRevision($entry, $body, $claimUlids, null, $actor, 'manual');

            if ($actor->isCandidate()) {
                $this->markApproved($entry, $actor);
            }

            $entry->save();

            $this->audit->record('answer.created', $entry, ['status' => $entry->status->value], $actor);

            return $entry;
        });
    }

    /**
     * Add several question/answer pairs at once (all in one category).
     *
     * @param  list<array{question: string, body: string}>  $pairs
     * @return list<AnswerLibraryEntry>
     */
    public function createMany(array $pairs, AnswerCategory $category, User $actor): array
    {
        return DB::transaction(fn (): array => array_map(
            fn (array $pair): AnswerLibraryEntry => $this->create($pair['question'], $pair['body'], $category, [], $actor),
            $pairs,
        ));
    }

    /**
     * Apply an edit made from a form loaded at `$expectedLockVersion`. A new revision is appended
     * only when the answer text or its evidence changes.
     *
     * @param  list<string>  $claimUlids
     *
     * @throws ValidationException when someone saved the answer in the meantime
     */
    public function revise(AnswerLibraryEntry $entry, string $question, string $body, AnswerCategory $category, array $claimUlids, ?string $changeReason, int $expectedLockVersion, User $actor, string $via = 'manual'): void
    {
        DB::transaction(function () use ($entry, $question, $body, $category, $claimUlids, $changeReason, $expectedLockVersion, $actor, $via): void {
            $current = AnswerLibraryEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($current->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => __('Someone else changed this answer while you were editing. Reload the page to see their version, then apply your change again.'),
                ]);
            }

            $entry->loadMissing('currentRevision');

            if ($body !== $entry->body() || $claimUlids !== $entry->claimUlids()) {
                $this->appendRevision($entry, $body, $claimUlids, $changeReason, $actor, $via);
            }

            $entry->question = $question;
            $entry->category = $category;
            $entry->lock_version = $expectedLockVersion + 1;
            $entry->save();

            $this->audit->record('answer.revised', $entry, [], $actor);
        });
    }

    public function approve(AnswerLibraryEntry $entry, User $actor): void
    {
        $this->transition($entry, $actor, 'approved', fn () => $this->markApproved($entry, $actor));
    }

    public function archive(AnswerLibraryEntry $entry, User $actor): void
    {
        $this->transition($entry, $actor, 'archived', function () use ($entry): void {
            $entry->status = AnswerStatus::Archived;
        });
    }

    /**
     * Restored answers come back approved: only the candidate restores.
     */
    public function restore(AnswerLibraryEntry $entry, User $actor): void
    {
        $this->transition($entry, $actor, 'restored', fn () => $this->markApproved($entry, $actor));
    }

    /**
     * @param  callable(): void  $change
     */
    private function transition(AnswerLibraryEntry $entry, User $actor, string $action, callable $change): void
    {
        DB::transaction(function () use ($entry, $actor, $action, $change): void {
            $change();
            $entry->lock_version++;
            $entry->save();

            $this->audit->record("answer.{$action}", $entry, [], $actor);
        });
    }

    private function markApproved(AnswerLibraryEntry $entry, User $actor): void
    {
        $entry->status = AnswerStatus::Approved;
        $entry->approved_by = $actor->id;
        $entry->approved_at = now();
    }

    /**
     * @param  list<string>  $claimUlids
     */
    private function appendRevision(AnswerLibraryEntry $entry, string $body, array $claimUlids, ?string $changeReason, User $actor, string $via): void
    {
        $revision = AnswerLibraryRevision::create([
            'answer_library_entry_id' => $entry->id,
            'revision_no' => (int) AnswerLibraryRevision::query()->where('answer_library_entry_id', $entry->id)->max('revision_no') + 1,
            'body' => $body,
            'claim_ulids' => $claimUlids,
            'change_reason' => $changeReason,
            'created_by' => $actor->id,
            'created_via' => $via,
        ]);

        $entry->current_revision_id = $revision->id;
        $entry->setRelation('currentRevision', $revision);
    }
}
