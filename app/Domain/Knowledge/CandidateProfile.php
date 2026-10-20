<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use App\Models\User;
use Database\Factories\CandidateProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The candidate whose knowledge base this is. MVP: one per workspace.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int $user_id
 * @property string $display_name
 * @property string|null $headline
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'user_id', 'display_name', 'headline'])]
#[UseFactory(CandidateProfileFactory::class)]
class CandidateProfile extends Model
{
    /** @use HasFactory<CandidateProfileFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
