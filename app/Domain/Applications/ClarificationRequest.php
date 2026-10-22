<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\Claim;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question for the candidate about a must-have the library has no evidence for. `open` until
 * answered (the answer becomes a bullet point), dismissed ("I don't have this"), or resolved
 * because matching found evidence after all.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $job_application_id
 * @property int|null $job_analysis_id
 * @property string $requirement
 * @property string $requirement_hash
 * @property string $question_text
 * @property string $status
 * @property string|null $answer_text
 * @property int|null $converted_claim_id
 * @property int|null $answered_by
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read JobApplication $application
 * @property-read Claim|null $claim
 */
class ClarificationRequest extends Model
{
    use BelongsToWorkspace, HasUlids;

    /**
     * Written only through ClarificationService.
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

    public static function hashOf(string $requirement): string
    {
        return hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $requirement))));
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /**
     * @return BelongsTo<Claim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'converted_claim_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
        ];
    }
}
