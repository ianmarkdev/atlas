<?php

namespace Database\Factories;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Domain\Knowledge\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
#[UseModel(Project::class)]
class ProjectFactory extends Factory
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
            'name' => rtrim(fake()->sentence(3), '.'),
            'role' => fake()->jobTitle(),
            'summary' => fake()->sentence(),
            'status' => KnowledgeStatus::Proposed,
        ];
    }

    public function forProfile(CandidateProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'candidate_profile_id' => $profile->id,
            'workspace_id' => $profile->workspace_id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeStatus::Archived,
        ]);
    }

    public function verified(?User $verifier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeStatus::Verified,
            'verified_by' => $verifier->id ?? User::factory(),
            'verified_at' => now(),
        ]);
    }
}
