<?php

namespace App\Domain\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suggested changes to verified facts (D5): a VA proposes, or the AI learns one from an edited
 * answer (M10); the candidate approves or rejects — or, for a personal writing preference, the
 * user it belongs to. Approving applies the change through ProposalApplier, and the approval
 * counts as the candidate's confirmation.
 */
class ProposalService
{
    public function __construct(private ProposalApplier $applier, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $payload  the new values, as validated by the item's form request
     * @param  int  $expectedLockVersion  the lock version the proposer's form was loaded at
     *
     * @throws ValidationException when the item changed while the proposer was editing
     */
    public function propose(Claim|Employment|Project|Credential|AnswerLibraryEntry $target, array $payload, ?string $rationale, int $expectedLockVersion, User $actor): KnowledgeProposal
    {
        if ($target->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => __('Someone else changed this item while you were editing. Reload the page, then suggest your change again.'),
            ]);
        }

        return DB::transaction(function () use ($target, $payload, $rationale, $actor): KnowledgeProposal {
            KnowledgeProposal::query()
                ->where('target_type', $target->getMorphClass())
                ->where('target_id', $target->getKey())
                ->where('status', ProposalStatus::Pending)
                ->update(['status' => ProposalStatus::Superseded, 'updated_at' => now()]);

            $proposal = new KnowledgeProposal;
            $proposal->forceFill([
                'candidate_profile_id' => $target->candidate_profile_id,
                'origin' => 'manual',
                'proposal_type' => match (true) {
                    $target instanceof Claim => 'revise_claim',
                    $target instanceof AnswerLibraryEntry => 'revise_library_answer',
                    default => 'revise_record',
                },
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'base_version' => ProposalApplier::versionOf($target),
                'payload' => $payload,
                'rationale' => $rationale,
                'status' => ProposalStatus::Pending,
                'proposed_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('proposal.created', $proposal, ['target' => $proposal->target_type], $actor);

            return $proposal;
        });
    }

    /**
     * An AI suggestion to turn a bullet point into an achievement (M9): a question for the
     * candidate and a draft with `[confirm: …]` placeholders, waiting for the candidate's answer.
     * A bullet that already has a pending suggestion of any kind is left alone.
     */
    public function suggestAchievement(Claim $claim, string $question, string $draft): ?KnowledgeProposal
    {
        return DB::transaction(function () use ($claim, $question, $draft): ?KnowledgeProposal {
            $pending = KnowledgeProposal::query()
                ->where('target_type', $claim->getMorphClass())
                ->where('target_id', $claim->id)
                ->where('status', ProposalStatus::Pending)
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                return null;
            }

            $proposal = new KnowledgeProposal;
            $proposal->forceFill([
                'candidate_profile_id' => $claim->candidate_profile_id,
                'origin' => 'achievement_suggestion',
                'proposal_type' => 'revise_claim',
                'target_type' => $claim->getMorphClass(),
                'target_id' => $claim->id,
                'base_version' => ProposalApplier::versionOf($claim),
                'payload' => ['statement' => $draft, 'kind' => ClaimKind::Achievement->value, 'change_reason' => __('Achievement suggestion: :question', ['question' => $question])],
                'rationale' => $question,
                'status' => ProposalStatus::Pending,
            ])->save();

            $this->audit->record('proposal.suggested', $proposal, ['target' => 'claim'], null);

            return $proposal;
        });
    }

    /**
     * A suggestion that is not a VA's form edit (M10): learned from an edited answer
     * (`feedback`), or a VA's "use everywhere" from a tailored resume (`manual`). Creating
     * types have no target. For an item with a pending proposal, a newer manual one replaces it;
     * an AI one is dropped, so the AI never overrides a person.
     *
     * @param  'revise_claim'|'create_claim'|'create_library_answer'|'create_writing_preference'|'revise_resume_variant'  $type
     * @param  array<string, mixed>  $payload
     * @param  array{origin: string, origin_id?: int|null, run_id?: int|null, proposer?: User|null, reviewer?: User|null}  $from
     */
    public function suggest(string $type, Claim|ResumeVariant|null $target, array $payload, ?string $rationale, array $from): ?KnowledgeProposal
    {
        return DB::transaction(function () use ($type, $target, $payload, $rationale, $from): ?KnowledgeProposal {
            if ($target !== null) {
                $pending = KnowledgeProposal::query()
                    ->where('target_type', $target->getMorphClass())
                    ->where('target_id', $target->getKey())
                    ->where('status', ProposalStatus::Pending)
                    ->lockForUpdate();

                if ($from['origin'] !== 'manual') {
                    if ($pending->exists()) {
                        return null;
                    }
                } else {
                    $pending->update(['status' => ProposalStatus::Superseded, 'updated_at' => now()]);
                }
            }

            $proposal = new KnowledgeProposal;
            $proposal->forceFill([
                'candidate_profile_id' => $target->candidate_profile_id ?? CandidateProfile::current()->id,
                'origin' => $from['origin'],
                'origin_id' => $from['origin_id'] ?? null,
                'proposal_type' => $type,
                'target_type' => $target?->getMorphClass() ?? match ($type) {
                    'create_claim' => 'claim',
                    'create_library_answer' => 'answer_library_entry',
                    default => 'writing_preference',
                },
                'target_id' => $target?->getKey(),
                'base_version' => $target === null ? 0 : ProposalApplier::versionOf($target),
                'payload' => $payload,
                'rationale' => $rationale,
                'status' => ProposalStatus::Pending,
                'proposed_by_user_id' => ($from['proposer'] ?? null)?->id,
                'proposed_by_run_id' => $from['run_id'] ?? null,
                'reviewer_user_id' => ($from['reviewer'] ?? null)?->id,
            ])->save();

            $this->audit->record('proposal.created', $proposal, ['target' => $proposal->target_type, 'origin' => $proposal->origin], $from['proposer'] ?? null);

            return $proposal;
        });
    }

    /**
     * @param  array{statement?: string, body?: string, rule?: string, subject?: Employment|Project|null, drafts?: WritingPreferenceDrafts, replaces?: WritingPreference}  $edits
     *                                                                                                                                                                             the reviewer's final wording (edit-then-approve); a statement is required for achievement suggestions
     * @return bool true when the change was applied; false when the item changed after the
     *              suggestion was made (the proposal is then marked as a conflict)
     *
     * @throws ValidationException when applying fails (e.g. the new wording duplicates another claim),
     *                             or the wording still has `[confirm: …]` placeholders
     */
    public function approve(KnowledgeProposal $proposal, User $actor, array $edits = []): bool
    {
        return DB::transaction(function () use ($proposal, $actor, $edits): bool {
            $proposal = KnowledgeProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($proposal);

            $target = $proposal->isCreation() ? null : $proposal->target()->lockForUpdate()->first();

            if ((! $proposal->isCreation() && $target === null) || ! $this->applier->isApplicable($proposal, $target)) {
                $this->decide($proposal, ProposalStatus::Conflict, $actor, null);

                return false;
            }

            $this->applier->apply($proposal, $target, $actor, $edits);
            $this->decide($proposal, ProposalStatus::Approved, $actor, null);

            return true;
        });
    }

    public function reject(KnowledgeProposal $proposal, ?string $note, User $actor): void
    {
        DB::transaction(function () use ($proposal, $note, $actor): void {
            $proposal = KnowledgeProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($proposal);

            $this->decide($proposal, ProposalStatus::Rejected, $actor, $note);
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensurePending(KnowledgeProposal $proposal): void
    {
        if ($proposal->status !== ProposalStatus::Pending) {
            throw ValidationException::withMessages(['proposal' => __('This suggestion has already been decided.')]);
        }
    }

    private function decide(KnowledgeProposal $proposal, ProposalStatus $status, User $actor, ?string $note): void
    {
        $proposal->forceFill([
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->record("proposal.{$status->value}", $proposal, ['target' => $proposal->target_type], $actor);
    }
}
