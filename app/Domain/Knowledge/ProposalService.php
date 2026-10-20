<?php

namespace App\Domain\Knowledge;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suggested changes to verified facts (D5): a VA proposes, the candidate approves or rejects.
 * Approving applies the change through the normal services, so history, duplicate checks and
 * audit are identical to a direct edit, and the approval counts as the candidate's confirmation.
 */
class ProposalService
{
    public function __construct(
        private ClaimService $claims,
        private KnowledgeRecordService $records,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the new values, as validated by the item's form request
     * @param  int  $expectedLockVersion  the lock version the proposer's form was loaded at
     *
     * @throws ValidationException when the item changed while the proposer was editing
     */
    public function propose(Claim|Employment|Project|Credential $target, array $payload, ?string $rationale, int $expectedLockVersion, User $actor): KnowledgeProposal
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
                'proposal_type' => $target instanceof Claim ? 'revise_claim' : 'revise_record',
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'base_version' => $this->versionOf($target),
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
     * @return bool true when the change was applied; false when the item changed after the
     *              suggestion was made (the proposal is then marked as a conflict)
     *
     * @throws ValidationException when applying fails (e.g. the new wording duplicates another claim)
     */
    public function approve(KnowledgeProposal $proposal, User $actor): bool
    {
        return DB::transaction(function () use ($proposal, $actor): bool {
            $proposal = KnowledgeProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->ensurePending($proposal);

            /** @var Claim|Employment|Project|Credential|null $target */
            $target = $proposal->target()->lockForUpdate()->first();

            if ($target === null || ! $this->isApplicable($target, $proposal)) {
                $this->decide($proposal, ProposalStatus::Conflict, $actor, null);

                return false;
            }

            if ($target instanceof Claim) {
                $target->load(['currentRevision', 'subject']);
                $this->claims->revise(
                    $target,
                    (string) $proposal->payload['statement'],
                    ClaimKind::from((string) $proposal->payload['kind']),
                    $proposal->payload['change_reason'] ?? $proposal->rationale,
                    $target->lock_version,
                    $actor,
                    allowNearDuplicate: true,
                    via: "proposal:{$proposal->id}",
                );
            } else {
                $this->records->update($target, $proposal->payload, $target->lock_version, $actor);
            }

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
     * Still verified and unchanged since the suggestion was made.
     */
    private function isApplicable(Claim|Employment|Project|Credential $target, KnowledgeProposal $proposal): bool
    {
        $isVerified = $target instanceof Claim
            ? $target->status === ClaimStatus::Verified
            : $target->status === KnowledgeStatus::Verified;

        return $isVerified && $this->versionOf($target) === $proposal->base_version;
    }

    private function versionOf(Claim|Employment|Project|Credential $target): int
    {
        return $target instanceof Claim ? (int) $target->current_revision_id : $target->lock_version;
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
