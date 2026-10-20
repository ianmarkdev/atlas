<?php

namespace App\Domain\Resumes;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\CandidateProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes resumes. Every save appends a revision (append-only history); the first resume of a
 * profile becomes the default.
 */
class ResumeService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(string $name, ?ResumeVariant $copyFrom, User $actor): ResumeVariant
    {
        return DB::transaction(function () use ($name, $copyFrom, $actor): ResumeVariant {
            $profile = CandidateProfile::current();

            $resume = new ResumeVariant;
            $resume->forceFill([
                'candidate_profile_id' => $profile->id,
                'name' => $name,
                'is_default' => ! ResumeVariant::query()->where('candidate_profile_id', $profile->id)->active()->exists(),
                'status' => 'active',
                'created_by' => $actor->id,
            ])->save();

            $this->appendRevision(
                $resume,
                $copyFrom?->document() ?? ResumeDocumentValidator::emptyDocument(),
                $actor,
                $copyFrom === null ? 'manual' : "duplicated:{$copyFrom->id}",
            );
            $resume->save();

            $this->audit->record('resume.created', $resume, ['copied_from' => $copyFrom?->ulid], $actor);

            return $resume;
        });
    }

    /**
     * Save the editor's state that was loaded at `$expectedLockVersion` as a new revision.
     *
     * @param  array<string, mixed>  $document  normalised by ResumeDocumentValidator
     *
     * @throws ValidationException when someone saved the resume in the meantime
     */
    public function save(ResumeVariant $resume, string $name, array $document, int $expectedLockVersion, User $actor, string $via = 'manual'): void
    {
        DB::transaction(function () use ($resume, $name, $document, $expectedLockVersion, $actor, $via): void {
            $current = ResumeVariant::query()->whereKey($resume->id)->lockForUpdate()->firstOrFail();

            if ($current->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => __('This resume was saved in another tab or by someone else. Reload to see that version, then apply your changes again.'),
                ]);
            }

            $resume->name = $name;
            $this->appendRevision($resume, $document, $actor, $via);
            $resume->lock_version = $expectedLockVersion + 1;
            $resume->save();

            $this->audit->record('resume.saved', $resume, ['revision_no' => $resume->currentRevision?->revision_no], $actor);
        });
    }

    public function makeDefault(ResumeVariant $resume, User $actor): void
    {
        DB::transaction(function () use ($resume, $actor): void {
            ResumeVariant::query()
                ->where('candidate_profile_id', $resume->candidate_profile_id)
                ->whereKeyNot($resume->id)
                ->update(['is_default' => false]);

            $resume->forceFill(['is_default' => true])->save();

            $this->audit->record('resume.made_default', $resume, [], $actor);
        });
    }

    /**
     * @throws ValidationException for the default resume (make another one the default first)
     */
    public function archive(ResumeVariant $resume, User $actor): void
    {
        if ($resume->is_default) {
            throw ValidationException::withMessages([
                'resume' => __('Make another resume the default before archiving this one.'),
            ]);
        }

        $resume->forceFill(['status' => 'archived'])->save();

        $this->audit->record('resume.archived', $resume, [], $actor);
    }

    public function unarchive(ResumeVariant $resume, User $actor): void
    {
        $resume->forceFill(['status' => 'active'])->save();

        $this->audit->record('resume.restored', $resume, [], $actor);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function appendRevision(ResumeVariant $resume, array $document, User $actor, string $via): ResumeVariantRevision
    {
        $nextNumber = (int) ResumeVariantRevision::query()->where('resume_variant_id', $resume->id)->max('revision_no') + 1;

        $revision = ResumeVariantRevision::create([
            'resume_variant_id' => $resume->id,
            'revision_no' => $nextNumber,
            'document' => $document,
            'created_by' => $actor->id,
            'created_via' => $via,
        ]);

        $resume->current_revision_id = $revision->id;
        $resume->setRelation('currentRevision', $revision);

        return $revision;
    }
}
