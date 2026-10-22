<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimService;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review of an AI draft's unconfirmed claims and questions, one at a time (M9, docs/04 §4.5).
 * A claim can be kept as worded (candidate only) or turned into a bullet point; an AI question
 * can be answered (the answer becomes a bullet point) or skipped. Bullet points go through
 * ClaimService, so they are verified for the candidate and proposed for a VA — a VA's addition
 * never verifies anything by itself.
 */
class ClaimReviewService
{
    public function __construct(private ClaimService $claims, private AuditLogger $audit) {}

    /**
     * @throws ValidationException when the claim is not one to check on the current AI draft
     */
    public function accept(ApplicationQuestion $question, int $index, User $actor): void
    {
        $version = $this->currentAiVersion($question);

        if (! array_key_exists($index, $version->claimsToCheck())) {
            throw ValidationException::withMessages(['review' => __('This claim does not need checking.')]);
        }

        $this->decide($version, 'claim', $index, 'accepted', null, $actor);
        $this->audit->record('answer_draft.claim_accepted', $question, ['index' => $index], $actor);
    }

    /**
     * Save a claim (as reworded by the user) or the answer to an AI question as a bullet point.
     *
     * @param  'claim'|'question'  $itemType
     *
     * @throws ValidationException for an unknown item, or a duplicate bullet point
     */
    public function addToLibrary(ApplicationQuestion $question, string $itemType, int $index, string $statement, Employment|Project|null $subject, User $actor): void
    {
        $version = $this->currentAiVersion($question);
        $items = $itemType === 'claim' ? ($version->grounding ?? []) : ($version->clarification_questions ?? []);

        if (! array_key_exists($index, $items)) {
            throw ValidationException::withMessages(['review' => __('This item is not part of the AI draft.')]);
        }

        DB::transaction(function () use ($version, $question, $itemType, $index, $statement, $subject, $actor): void {
            $claim = $this->claims->create($statement, ClaimKind::Achievement, $subject, $actor);

            $this->decide($version, $itemType, $index, 'added', $claim->id, $actor);
            $this->audit->record('answer_draft.added_to_library', $question, ['item' => $itemType, 'index' => $index], $actor);
        });
    }

    /**
     * @throws ValidationException for an unknown question
     */
    public function dismissQuestion(ApplicationQuestion $question, int $index, User $actor): void
    {
        $version = $this->currentAiVersion($question);

        if (! array_key_exists($index, $version->clarification_questions ?? [])) {
            throw ValidationException::withMessages(['review' => __('This question is not part of the AI draft.')]);
        }

        $this->decide($version, 'question', $index, 'dismissed', null, $actor);
    }

    /**
     * @throws ValidationException when the current version was not written by AI
     */
    private function currentAiVersion(ApplicationQuestion $question): AnswerDraftVersion
    {
        $version = $question->answer?->currentVersion;

        if ($version === null || $version->author_type !== 'ai') {
            throw ValidationException::withMessages(['review' => __('Only an AI draft has claims to check.')]);
        }

        return $version;
    }

    private function decide(AnswerDraftVersion $version, string $itemType, int $index, string $decision, ?int $claimId, User $actor): void
    {
        $review = AnswerClaimReview::query()
            ->where('answer_draft_version_id', $version->id)
            ->where('item_type', $itemType)
            ->where('item_index', $index)
            ->first() ?? new AnswerClaimReview;

        $review->forceFill([
            'answer_draft_version_id' => $version->id,
            'item_type' => $itemType,
            'item_index' => $index,
            'decision' => $decision,
            'claim_id' => $claimId ?? $review->claim_id,
            'decided_by' => $actor->id,
        ])->save();
    }
}
