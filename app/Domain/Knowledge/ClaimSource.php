<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Provenance of one claim revision. A `user_confirmation` source by the candidate is what
 * makes a revision verified; an `answer_version` source only records where it was first suggested.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $claim_id
 * @property int $claim_revision_id
 * @property string $source_type
 * @property int|null $source_id
 * @property string|null $quote
 * @property int|null $confirmed_by
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'claim_id', 'claim_revision_id', 'source_type', 'source_id', 'quote', 'confirmed_by'])]
class ClaimSource extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;
}
