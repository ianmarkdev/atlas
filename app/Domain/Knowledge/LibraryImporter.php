<?php

namespace App\Domain\Knowledge;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Adds what a LibraryDocument holds to the current candidate profile, through the normal
 * services (audit, duplicate checks, search index). Everything imported stays proposed until
 * the candidate verifies it, so a document never becomes confirmed fact by itself.
 *
 * Safe to run again: a job whose employer is already in the library, or a project with the same
 * name, is reused, and a claim that duplicates or nearly duplicates one about the same job or
 * project is skipped. Claims are attached by their subject: an employer, else a project name.
 *
 * @phpstan-type ImportReport array{added: list<array{string, string}>, duplicates: list<string>, unplaced: list<string>}
 */
class LibraryImporter
{
    public function __construct(private KnowledgeRecordService $records, private ClaimService $claims) {}

    /**
     * @param  ImportReport|null  $report  the report of documents imported before this one, to add to
     * @return ImportReport
     */
    public function import(LibraryDocument $document, User $actor, ?array $report = null): array
    {
        $report ??= ['added' => [], 'duplicates' => [], 'unplaced' => []];

        foreach ($document->employments as $parsed) {
            if ($this->employment($parsed['employer_name']) !== null) {
                continue;
            }

            if ($parsed['start_date'] === null) {
                $report['unplaced'][] = __('Job ":title at :employer" has no start date, so it was not added.', ['title' => $parsed['title'], 'employer' => $parsed['employer_name']]);

                continue;
            }

            $employment = new Employment;
            $employment->forceFill($parsed);
            $this->records->create($employment, $actor, imported: true);
            $report['added'][] = ['employment', "{$parsed['title']} at {$parsed['employer_name']}"];
        }

        foreach ($document->projects as $parsed) {
            if ($this->project($parsed['name']) !== null) {
                continue;
            }

            $project = new Project;
            $project->forceFill([
                ...array_diff_key($parsed, ['employment' => true]),
                'employment_id' => $parsed['employment'] === null ? null : $this->employment($parsed['employment'])?->id,
            ]);
            $this->records->create($project, $actor, imported: true);
            $report['added'][] = ['project', $parsed['name']];
        }

        foreach ($document->claims as $parsed) {
            $subject = $this->employment($parsed['subject']) ?? $this->project($parsed['subject']);

            if ($subject === null) {
                $report['unplaced'][] = __('No job or project ":subject" for: :statement', ['subject' => $parsed['subject'], 'statement' => $parsed['statement']]);

                continue;
            }

            try {
                $this->claims->create($parsed['statement'], $parsed['kind'], $subject, $actor, origin: 'imported');
                $report['added'][] = ["claim ({$parsed['kind']->value})", "{$parsed['subject']}: {$parsed['statement']}"];
            } catch (ValidationException) {
                $report['duplicates'][] = "{$parsed['subject']}: {$parsed['statement']}";
            }
        }

        return $report;
    }

    private function employment(string $employer): ?Employment
    {
        return Employment::query()
            ->notArchived()
            ->where('candidate_profile_id', CandidateProfile::current()->id)
            ->where('employer_normalized', Employment::normalizeEmployer($employer))
            ->latest('start_date')
            ->first();
    }

    /**
     * A project with this name, or one whose name starts with it ("Hospitality Safe" for
     * "Hospitality Safe — Food Compliance Platform").
     */
    private function project(string $name): ?Project
    {
        return Project::query()
            ->notArchived()
            ->where('candidate_profile_id', CandidateProfile::current()->id)
            ->where(fn ($query) => $query->whereRaw('lower(name) = lower(?)', [$name])->orWhereRaw('lower(name) like lower(?)', [addcslashes($name, '%_\\').' %']))
            ->orderByRaw('lower(name) = lower(?) desc', [$name])
            ->first();
    }
}
