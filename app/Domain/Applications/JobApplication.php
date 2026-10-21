<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use App\Policies\JobApplicationPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job the candidate applies (or may apply) for, with the job description as pasted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $company_name
 * @property string $role_title
 * @property string|null $source_url
 * @property string|null $location
 * @property EmploymentType|null $employment_type
 * @property string|null $salary_text
 * @property ApplicationStage $stage
 * @property ReviewStatus $review_status
 * @property CarbonImmutable|null $stage_changed_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $deadline_on
 * @property string|null $notes
 * @property string|null $review_note
 * @property CarbonImmutable|null $review_status_changed_at
 * @property int|null $owner_user_id
 * @property int|null $assigned_user_id
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read JobPosting|null $posting
 * @property-read User|null $owner
 * @property-read User|null $assignee
 * @property-read ApplicationResume|null $resume
 */
#[UseFactory(JobApplicationFactory::class)]
#[UsePolicy(JobApplicationPolicy::class)]
class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids, SoftDeletes;

    /**
     * Written only through JobApplicationService.
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

    /**
     * The deadline has passed and the application was not sent or closed.
     */
    public function isOverdue(?CarbonImmutable $now = null): bool
    {
        return $this->deadline_on !== null
            && ! $this->stage->isSubmitted()
            && ! $this->stage->isClosed()
            && $this->deadline_on->isBefore(($now ?? CarbonImmutable::now())->startOfDay());
    }

    public function jobDescription(): ?string
    {
        return $this->posting?->raw_text;
    }

    /**
     * The current (newest) job description.
     *
     * @return HasOne<JobPosting, $this>
     */
    public function posting(): HasOne
    {
        return $this->hasOne(JobPosting::class)->latestOfMany();
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(JobPosting::class)->orderByDesc('id');
    }

    /**
     * In form order.
     *
     * @return HasMany<ApplicationQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ApplicationQuestion::class)->orderBy('position');
    }

    /**
     * @return HasOne<ApplicationResume, $this>
     */
    public function resume(): HasOne
    {
        return $this->hasOne(ApplicationResume::class);
    }

    /**
     * @return BelongsTo<CandidateProfile, $this>
     */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Who prepares this application (a VA, or the candidate).
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'stage' => ApplicationStage::class,
            'review_status' => ReviewStatus::class,
            'stage_changed_at' => 'datetime',
            'applied_at' => 'datetime',
            'deadline_on' => 'date',
            'review_status_changed_at' => 'datetime',
        ];
    }
}
