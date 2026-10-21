<?php

namespace Database\Factories;

use App\Domain\Applications\ApplicationStage;
use App\Domain\Applications\JobApplication;
use App\Domain\Applications\JobPosting;
use App\Domain\Applications\ReviewStatus;
use App\Domain\Knowledge\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Chain `withJobDescription()` to store a pasted job description.
 *
 * @extends Factory<JobApplication>
 */
#[UseModel(JobApplication::class)]
class JobApplicationFactory extends Factory
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
            'company_name' => fake()->company(),
            'role_title' => 'Senior Backend Engineer',
            'stage' => ApplicationStage::Saved,
            'review_status' => ReviewStatus::NotStarted,
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

    public function inStage(ApplicationStage $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
            'stage_changed_at' => now(),
            'applied_at' => $stage->isSubmitted() ? now() : null,
        ]);
    }

    public function withJobDescription(string $text): static
    {
        return $this->afterCreating(function (JobApplication $application) use ($text): void {
            JobPosting::create([
                'workspace_id' => $application->workspace_id,
                'job_application_id' => $application->id,
                'raw_text' => $text,
                'raw_sha256' => hash('sha256', $text),
            ]);
        });
    }
}
