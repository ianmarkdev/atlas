<?php

namespace App\Domain\Applications;

use App\Domain\Shared\PlainText;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Policies\ApplicationQuestionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One question of an application form (or its cover letter), with its length limits.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $job_application_id
 * @property string $origin
 * @property string $prompt_text
 * @property int|null $word_limit
 * @property int|null $char_limit
 * @property int $position
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AnswerDraft|null $answer
 * @property-read JobApplication $application
 */
#[UsePolicy(ApplicationQuestionPolicy::class)]
class ApplicationQuestion extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * Written only through ApplicationQuestionService.
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

    public function isCoverLetter(): bool
    {
        return $this->origin === 'cover_letter';
    }

    /**
     * Why `$text` does not fit the limits, or null when it fits.
     */
    public function limitProblem(string $text): ?string
    {
        if ($this->word_limit !== null && PlainText::wordCount($text) > $this->word_limit) {
            return __(':count words; the limit is :limit.', ['count' => PlainText::wordCount($text), 'limit' => $this->word_limit]);
        }

        if ($this->char_limit !== null && mb_strlen($text) > $this->char_limit) {
            return __(':count characters; the limit is :limit.', ['count' => mb_strlen($text), 'limit' => $this->char_limit]);
        }

        return null;
    }

    /**
     * @return HasOne<AnswerDraft, $this>
     */
    public function answer(): HasOne
    {
        return $this->hasOne(AnswerDraft::class);
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }
}
