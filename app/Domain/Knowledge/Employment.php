<?php

namespace App\Domain\Knowledge;

use App\Domain\Retrieval\RetrievalSourceObserver;
use App\Domain\Tenancy\BelongsToWorkspace;
use App\Policies\KnowledgeRecordPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\EmploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property string $employer_name
 * @property string $employer_normalized
 * @property string $title
 * @property EmploymentType|null $employment_type
 * @property string|null $location
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property string $date_precision
 * @property string|null $summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['employer_name', 'title', 'employment_type', 'location', 'start_date', 'end_date', 'summary'])]
#[ObservedBy(RetrievalSourceObserver::class)]
#[UseFactory(EmploymentFactory::class)]
#[UsePolicy(KnowledgeRecordPolicy::class)]
class Employment extends Model
{
    /** @use HasFactory<EmploymentFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids, IsKnowledgeRecord;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected static function booted(): void
    {
        static::saving(function (Employment $employment): void {
            $employment->employer_normalized = self::normalizeEmployer($employment->employer_name);
        });
    }

    /**
     * Lower-case, punctuation-free employer name without a trailing legal suffix, so
     * "Acme Ltd." and "ACME" match (used for retrieval filters and duplicate checks).
     */
    public static function normalizeEmployer(string $name): string
    {
        $normalized = Str::of($name)->lower()->replaceMatches('/[^\pL\pN]+/u', ' ')->trim();

        return $normalized->replaceMatches('/\s+(ltd|limited|inc|llc|plc|gmbh|co|corp|corporation|pty|bv|sa|ag)$/', '')->toString();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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
            'employment_type' => EmploymentType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }
}
