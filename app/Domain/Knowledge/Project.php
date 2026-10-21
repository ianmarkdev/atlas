<?php

namespace App\Domain\Knowledge;

use App\Domain\Retrieval\RetrievalSourceObserver;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Policies\KnowledgeRecordPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project, either within an employment or standalone (freelance, side project).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property int|null $employment_id
 * @property string $name
 * @property string|null $client_name
 * @property string|null $role
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property string|null $summary
 * @property string|null $url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Employment|null $employment
 */
#[Fillable(['employment_id', 'name', 'client_name', 'role', 'start_date', 'end_date', 'summary', 'url'])]
#[ObservedBy(RetrievalSourceObserver::class)]
#[UseFactory(ProjectFactory::class)]
#[UsePolicy(KnowledgeRecordPolicy::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids, IsKnowledgeRecord;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * @return BelongsTo<Employment, $this>
     */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(Employment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KnowledgeStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }
}
