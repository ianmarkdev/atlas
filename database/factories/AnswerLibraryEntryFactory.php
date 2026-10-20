<?php

namespace Database\Factories;

use App\Domain\Knowledge\AnswerCategory;
use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerLibraryRevision;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\CandidateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Chain `answering()`: the answer text lives in revision 1.
 *
 * @extends Factory<AnswerLibraryEntry>
 */
#[UseModel(AnswerLibraryEntry::class)]
class AnswerLibraryEntryFactory extends Factory
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
            'question' => 'Why do you want to work here?',
            'category' => AnswerCategory::Motivation,
            'status' => AnswerStatus::Draft,
            'lock_version' => 0,
        ];
    }

    /**
     * @param  list<string>  $claimUlids
     */
    public function answering(string $body, array $claimUlids = []): static
    {
        return $this->afterCreating(function (AnswerLibraryEntry $entry) use ($body, $claimUlids): void {
            $revision = AnswerLibraryRevision::create([
                'workspace_id' => $entry->workspace_id,
                'answer_library_entry_id' => $entry->id,
                'revision_no' => 1,
                'body' => $body,
                'claim_ulids' => $claimUlids,
                'created_via' => 'manual',
            ]);

            $entry->forceFill(['current_revision_id' => $revision->id])->save();
        });
    }

    public function forProfile(CandidateProfile $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'candidate_profile_id' => $profile->id,
            'workspace_id' => $profile->workspace_id,
        ]);
    }

    public function approved(User $approver): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AnswerStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AnswerStatus::Archived]);
    }
}
