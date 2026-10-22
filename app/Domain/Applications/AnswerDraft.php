<?php

namespace App\Domain\Applications;

use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The answer to one application question. An approved answer is frozen until the candidate
 * reopens it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $application_question_id
 * @property int|null $current_version_id
 * @property int|null $approved_version_id
 * @property string $status
 * @property string|null $generation_status queued|running|failed while/after an AI draft was requested
 * @property string|null $generation_error
 * @property CarbonImmutable|null $generation_requested_at
 * @property int|null $generation_requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AnswerDraftVersion|null $currentVersion
 * @property-read ApplicationQuestion $question
 */
class AnswerDraft extends Model
{
    use BelongsToWorkspace;

    /**
     * Written only through ApplicationQuestionService.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isGenerating(): bool
    {
        return in_array($this->generation_status, ['queued', 'running'], true);
    }

    public function body(): string
    {
        return $this->currentVersion->body ?? '';
    }

    /**
     * @return BelongsTo<AnswerDraftVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AnswerDraftVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<AnswerDraftVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AnswerDraftVersion::class)->orderByDesc('version_no');
    }

    /**
     * @return BelongsTo<ApplicationQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ApplicationQuestion::class, 'application_question_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'generation_requested_at' => 'datetime',
        ];
    }
}
