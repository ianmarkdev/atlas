<?php

namespace App\Domain\Knowledge;

use App\Domain\Feedback\FeedbackEvent;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use App\Policies\KnowledgeProposalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A suggested change to a verified fact (bullet point, experience, project, credential, library
 * answer, resume variant), or a suggested new one (bullet point, library answer, writing
 * preference; M10). Nothing changes until the reviewer approves (D5).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $origin manual|feedback|achievement_suggestion|clarification
 * @property int|null $origin_id the feedback event, for `feedback`
 * @property string $proposal_type `revise_claim` | `revise_record` | `revise_library_answer` | `revise_resume_variant`
 *                                 | `create_claim` | `create_library_answer` | `create_writing_preference`
 * @property string $target_type
 * @property int|null $target_id null for the `create_*` types
 * @property int $base_version claim: current revision id; other records: lock_version
 * @property array<string, mixed> $payload
 * @property string|null $rationale
 * @property ProposalStatus $status
 * @property int|null $proposed_by_user_id
 * @property int|null $proposed_by_run_id the AI run that suggested it
 * @property int|null $reviewer_user_id who decides instead of the candidate (a personal writing preference's owner)
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Claim|Employment|Project|Credential|AnswerLibraryEntry|ResumeVariant|null $target
 * @property-read FeedbackEvent|null $feedbackEvent
 * @property-read User|null $proposer
 * @property-read User|null $decider
 */
#[UsePolicy(KnowledgeProposalPolicy::class)]
class KnowledgeProposal extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * Written only through ProposalService.
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

    public function isCreation(): bool
    {
        return str_starts_with($this->proposal_type, 'create_');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id');
    }

    /**
     * @return BelongsTo<FeedbackEvent, $this>
     */
    public function feedbackEvent(): BelongsTo
    {
        return $this->belongsTo(FeedbackEvent::class, 'origin_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => ProposalStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
