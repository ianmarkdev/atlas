<?php

namespace App\Domain\Applications;

use App\Domain\Ai\GenerationRun;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI analysis of an application's job description (M7): the brief (validated model output
 * plus the server's own checks) and the requirement ↔ evidence matches.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $job_application_id
 * @property int $job_posting_id
 * @property int|null $generation_run_id
 * @property int|null $prompt_version
 * @property string $status
 * @property array<string, mixed>|null $brief
 * @property array<string, mixed>|null $matches
 * @property CarbonImmutable|null $matched_at
 * @property string|null $error_message
 * @property int|null $requested_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read JobApplication $application
 * @property-read JobPosting $posting
 * @property-read GenerationRun|null $generationRun
 * @property-read User|null $requester
 */
class JobAnalysis extends Model
{
    use BelongsToWorkspace, HasUlids;

    public const PENDING = ['queued', 'running'];

    /**
     * Written only through JobAnalysisService and AnalyseJobApplication.
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

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    /**
     * @return BelongsTo<GenerationRun, $this>
     */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brief' => 'array',
            'matches' => 'array',
            'matched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
