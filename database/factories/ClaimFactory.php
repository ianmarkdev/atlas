<?php

namespace Database\Factories;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\ClaimKind;
use App\Domain\Knowledge\ClaimRevision;
use App\Domain\Knowledge\ClaimStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Always chain `stating()`: a claim's statement lives in its first revision.
 *
 * @extends Factory<Claim>
 */
#[UseModel(Claim::class)]
class ClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'workspace_id' => fn (array $attributes) => CandidateProfile::withoutGlobalScopes()
                ->whereKey($attributes['candidate_profile_id'])->value('workspace_id'),
            'kind' => ClaimKind::Achievement,
            'status' => ClaimStatus::Proposed,
            'origin' => 'user_entered',
            'lock_version' => 0,
            'normalized_hash' => fn () => Claim::hashStatement(fake()->unique()->sentence()),
        ];
    }

    /**
     * The claim's statement, stored as revision 1.
     */
    public function stating(?string $statement = null): static
    {
        $statement ??= fake()->unique()->sentence();

        return $this
            ->state(fn (array $attributes) => ['normalized_hash' => Claim::hashStatement($statement)])
            ->afterCreating(function (Claim $claim) use ($statement): void {
                $revision = ClaimRevision::create([
                    'workspace_id' => $claim->workspace_id,
                    'claim_id' => $claim->id,
                    'revision_no' => 1,
                    'statement' => $statement,
                    'created_via' => 'manual',
                ]);

                $claim->forceFill(['current_revision_id' => $revision->id])->save();
            });
    }

    public function forProfile(CandidateProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'candidate_profile_id' => $profile->id,
            'workspace_id' => $profile->workspace_id,
        ]);
    }

    public function about(Model $subject): static
    {
        return $this->state(fn (array $attributes) => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function verified(User $verifier): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClaimStatus::Verified,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClaimStatus::Archived,
        ]);
    }
}
