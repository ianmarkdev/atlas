<?php

namespace Database\Factories;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Knowledge\Credential;
use App\Domain\Knowledge\CredentialType;
use App\Domain\Knowledge\KnowledgeStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
#[UseModel(Credential::class)]
class CredentialFactory extends Factory
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
            'type' => CredentialType::Education,
            'institution' => fake()->company().' University',
            'name' => 'BSc Computer Science',
            'start_date' => '2012-09-01',
            'end_date' => '2015-07-01',
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

    public function certification(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CredentialType::Certification,
            'institution' => 'Amazon Web Services',
            'name' => 'AWS Certified Developer – Associate',
            'start_date' => null,
            'end_date' => '2023-05-01',
        ]);
    }

    public function verified(User $verifier): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeStatus::Verified,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => KnowledgeStatus::Archived]);
    }
}
