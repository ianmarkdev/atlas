<?php

namespace App\Domain\Applications;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A job description exactly as pasted. Append-only (a database trigger rejects updates).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $job_application_id
 * @property string $raw_text
 * @property string $raw_sha256
 * @property int|null $captured_by
 * @property CarbonImmutable $captured_at
 */
#[Fillable(['workspace_id', 'job_application_id', 'raw_text', 'raw_sha256', 'captured_by'])]
class JobPosting extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }
}
