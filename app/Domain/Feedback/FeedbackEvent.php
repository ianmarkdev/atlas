<?php

namespace App\Domain\Feedback;

use App\Domain\Ai\GenerationRun;
use App\Domain\Applications\AnswerDraft;
use App\Domain\Applications\AnswerDraftVersion;
use App\Domain\Knowledge\KnowledgeProposal;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A human edit of an AI draft (M10, docs/04 §4.7): the AI version, the edited version, the
 * server's word diff, and what the AI learned from it (`analysis.items`, each with the outcome:
 * proposed, held until seen again, or skipped with a reason).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $answer_draft_id
 * @property int $from_version_id
 * @property int $to_version_id
 * @property int|null $user_id the user who made the edit
 * @property string $trigger approval|manual
 * @property list<array{op: string, text: string}> $diff
 * @property array{items: list<array<string, mixed>>}|null $analysis
 * @property int|null $generation_run_id
 * @property string $status pending_analysis|analysed|failed
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AnswerDraft $answerDraft
 * @property-read AnswerDraftVersion $fromVersion
 * @property-read AnswerDraftVersion $toVersion
 * @property-read User|null $user
 */
class FeedbackEvent extends Model
{
    use BelongsToWorkspace;

    /**
     * Written only through FeedbackService and AnalyseFeedback.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<AnswerDraft, $this>
     */
    public function answerDraft(): BelongsTo
    {
        return $this->belongsTo(AnswerDraft::class);
    }

    /**
     * @return BelongsTo<AnswerDraftVersion, $this>
     */
    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(AnswerDraftVersion::class, 'from_version_id');
    }

    /**
     * @return BelongsTo<AnswerDraftVersion, $this>
     */
    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(AnswerDraftVersion::class, 'to_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * @return HasMany<KnowledgeProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(KnowledgeProposal::class, 'origin_id')->where('origin', 'feedback');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'analysis' => 'array',
        ];
    }
}
