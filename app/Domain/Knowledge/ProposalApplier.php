<?php

namespace App\Domain\Knowledge;

use App\Domain\Resumes\ResumeService;
use App\Domain\Resumes\ResumeVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Applies an approved proposal (docs/04 §4.7) through the normal services, so history, duplicate
 * checks, audit and the retrieval index are identical to a direct edit. Called by
 * ProposalService inside its transaction, with the proposal and its target locked.
 */
class ProposalApplier
{
    public function __construct(
        private ClaimService $claims,
        private KnowledgeRecordService $records,
        private AnswerLibraryService $answers,
        private WritingPreferenceService $preferences,
        private ResumeService $resumes,
    ) {}

    /**
     * Whether the change can still be applied safely: a new item can always be created; an
     * existing one must still be live and unchanged since the proposal was made.
     */
    public function isApplicable(KnowledgeProposal $proposal, ?Model $target): bool
    {
        if ($proposal->isCreation()) {
            return true;
        }

        $isLive = match (true) {
            $target instanceof Claim => $target->status === ClaimStatus::Verified,
            $target instanceof AnswerLibraryEntry => $target->status === AnswerStatus::Approved,
            $target instanceof ResumeVariant => ! $target->isArchived(),
            $target instanceof Employment, $target instanceof Project, $target instanceof Credential => $target->status === KnowledgeStatus::Verified,
            default => false,
        };

        return $isLive && self::versionOf($target) === $proposal->base_version;
    }

    /**
     * The version a proposal is based on: a claim's or variant's current revision id, otherwise
     * the lock version.
     */
    public static function versionOf(Model $target): int
    {
        return $target instanceof Claim || $target instanceof ResumeVariant
            ? (int) $target->current_revision_id
            : (int) $target->getAttribute('lock_version');
    }

    /**
     * @param  array{statement?: string, body?: string, rule?: string, subject?: Employment|Project|null}  $edits
     *                                                                                                             the reviewer's final wording (edit-then-approve)
     *
     * @throws ValidationException when applying fails (a duplicate, `[confirm: …]` placeholders left)
     */
    public function apply(KnowledgeProposal $proposal, ?Model $target, User $actor, array $edits): void
    {
        $payload = $proposal->payload;
        $via = "proposal:{$proposal->id}";

        match ($proposal->proposal_type) {
            'revise_claim' => $this->reviseClaim($proposal, $target, $edits['statement'] ?? (string) $payload['statement'], $actor, $via),
            'create_claim' => $this->claims->create(
                self::withoutPlaceholders($edits['statement'] ?? (string) $payload['statement']),
                ClaimKind::from((string) ($payload['kind'] ?? ClaimKind::Achievement->value)),
                array_key_exists('subject', $edits) ? $edits['subject'] : (ClaimSubject::resolve($payload['subject'] ?? null) ?: null),
                $actor,
                allowNearDuplicate: true,
            ),
            'revise_library_answer' => $this->answers->revise(
                $target,
                (string) $payload['question'],
                $edits['body'] ?? (string) $payload['body'],
                AnswerCategory::from((string) $payload['category']),
                array_values(array_filter((array) ($payload['claim_ulids'] ?? []), is_string(...))),
                $proposal->rationale,
                $target->lock_version,
                $actor,
                via: $via,
            ),
            'create_library_answer' => $this->answers->create(
                (string) $payload['question'],
                $edits['body'] ?? (string) $payload['body'],
                AnswerCategory::tryFrom((string) ($payload['category'] ?? '')) ?? AnswerCategory::Other,
                [],
                $actor,
            ),
            'create_writing_preference' => $this->preferences->create(
                $edits['rule'] ?? (string) $payload['rule'],
                WritingPreferenceKind::tryFrom((string) ($payload['kind'] ?? '')) ?? WritingPreferenceKind::Tone,
                ($payload['scope'] ?? 'profile') === 'user' ? User::query()->find($proposal->reviewer_user_id) : null,
                $actor,
                $via,
            ),
            'revise_resume_variant' => $this->resumes->save(
                $target,
                $target->name,
                [...$target->document(), ...array_intersect_key($payload, array_flip(['title', 'summary']))],
                $target->lock_version,
                $actor,
                $via,
            ),
            default => $this->records->update($target, $payload, $target->lock_version, $actor),
        };
    }

    private function reviseClaim(KnowledgeProposal $proposal, Claim $claim, string $statement, User $actor, string $via): void
    {
        $claim->load(['currentRevision', 'subject']);

        $this->claims->revise(
            $claim,
            self::withoutPlaceholders($statement),
            ClaimKind::tryFrom((string) ($proposal->payload['kind'] ?? '')) ?? $claim->kind,
            $proposal->payload['change_reason'] ?? $proposal->rationale,
            $claim->lock_version,
            $actor,
            allowNearDuplicate: true,
            via: $via,
        );
    }

    /**
     * @throws ValidationException while the wording still has `[confirm: …]` placeholders
     */
    private static function withoutPlaceholders(string $statement): string
    {
        if (preg_match('/\[confirm:[^\]]*\]/iu', $statement) === 1) {
            throw ValidationException::withMessages(['statement' => __('Replace each [confirm: …] with the real detail first.')]);
        }

        return $statement;
    }
}
