<?php

namespace Database\Factories;

use App\Domain\Knowledge\CandidateProfile;
use App\Domain\Resumes\ResumeDocumentValidator;
use App\Domain\Resumes\ResumeVariant;
use App\Domain\Resumes\ResumeVariantRevision;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Chain `withDocument()` to give the resume revision 1; without it the resume is empty.
 *
 * @extends Factory<ResumeVariant>
 */
#[UseModel(ResumeVariant::class)]
class ResumeVariantFactory extends Factory
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
            'name' => rtrim(fake()->sentence(2), '.').' focused',
            'is_default' => false,
            'status' => 'active',
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

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'archived']);
    }

    /**
     * @param  array<string, mixed>  $document  merged over an empty document
     */
    public function withDocument(array $document = []): static
    {
        return $this->afterCreating(function (ResumeVariant $resume) use ($document): void {
            $revision = ResumeVariantRevision::create([
                'workspace_id' => $resume->workspace_id,
                'resume_variant_id' => $resume->id,
                'revision_no' => 1,
                'document' => [...ResumeDocumentValidator::emptyDocument(), ...$document],
                'created_via' => 'manual',
            ]);

            $resume->forceFill(['current_revision_id' => $revision->id])->save();
        });
    }
}
