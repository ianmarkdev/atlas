<?php

namespace Database\Factories;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\EmploymentType;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employment>
 */
#[UseModel(Employment::class)]
class EmploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-12 years', '-2 years');

        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'workspace_id' => fn (array $attributes) => CandidateProfile::withoutGlobalScopes()
                ->whereKey($attributes['candidate_profile_id'])->value('workspace_id'),
            'employer_name' => fake()->company(),
            'title' => fake()->jobTitle(),
            'employment_type' => EmploymentType::FullTime,
            'start_date' => $start->format('Y-m-01'),
            'end_date' => fake()->dateTimeBetween($start, '-1 month')->format('Y-m-01'),
            'status' => KnowledgeStatus::Proposed,
            'lock_version' => 0,
        ];
    }

    public function forProfile(CandidateProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'candidate_profile_id' => $profile->id,
            'workspace_id' => $profile->workspace_id,
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

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeStatus::Archived,
        ]);
    }
}
