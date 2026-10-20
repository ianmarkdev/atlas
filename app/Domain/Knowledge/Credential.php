<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use App\Policies\KnowledgeRecordPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A degree/school (education) or a certificate/licence (certification).
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $candidate_profile_id
 * @property CredentialType $type
 * @property string $institution school or issuer
 * @property string $name degree or certificate
 * @property string|null $field_of_study
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date graduation / award month
 * @property CarbonImmutable|null $expires_on
 * @property string|null $credential_url
 * @property string|null $summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'institution', 'name', 'field_of_study', 'start_date', 'end_date', 'expires_on', 'credential_url', 'summary'])]
#[UseFactory(CredentialFactory::class)]
#[UsePolicy(KnowledgeRecordPolicy::class)]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids, IsKnowledgeRecord;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
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
            'type' => CredentialType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'expires_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }
}
