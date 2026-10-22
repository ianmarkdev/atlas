<?php

namespace App\Domain\Applications;

use App\Domain\Resumes\ResumeDocumentValidator;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use App\Policies\ApplicationResumePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The resume tailored for one application, copied from a resume variant. An approved resume is
 * frozen until the candidate reopens it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $job_application_id
 * @property int|null $based_on_variant_id
 * @property int|null $based_on_revision_id
 * @property int|null $current_version_id
 * @property int|null $approved_version_id
 * @property string $status
 * @property string|null $generation_status queued|running|failed while/after AI tailoring was requested
 * @property string|null $generation_error
 * @property CarbonImmutable|null $generation_requested_at
 * @property int|null $generation_requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ApplicationResumeVersion|null $currentVersion
 * @property-read ApplicationResumeVersion|null $approvedVersion
 * @property-read ResumeVariant|null $basedOn
 * @property-read JobApplication $application
 */
#[UsePolicy(ApplicationResumePolicy::class)]
class ApplicationResume extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * Written only through ApplicationResumeService.
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

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isGenerating(): bool
    {
        return in_array($this->generation_status, ['queued', 'running'], true);
    }

    /**
     * The current document with every section present.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return [...ResumeDocumentValidator::emptyDocument(), ...($this->currentVersion->document ?? [])];
    }

    /**
     * @return BelongsTo<ApplicationResumeVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationResumeVersion::class, 'current_version_id');
    }

    /**
     * @return BelongsTo<ApplicationResumeVersion, $this>
     */
    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationResumeVersion::class, 'approved_version_id');
    }

    /**
     * @return HasMany<ApplicationResumeVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ApplicationResumeVersion::class)->orderByDesc('version_no');
    }

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class, 'based_on_variant_id');
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
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
