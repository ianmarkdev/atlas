<?php

namespace App\Domain\Retrieval;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Knowledge\Claim;
use App\Domain\Knowledge\Employment;
use App\Domain\Knowledge\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a library item looks like in the search index: the text that is searched and embedded
 * (with its context, e.g. "Senior Developer at Acme (2021–present): …") and its filters.
 *
 * Bump VERSION when the text templates change, so every unit is re-embedded.
 */
class RetrievalText
{
    public const VERSION = 1;

    /**
     * Statuses that make a unit searchable (doc 04 §4.3 default filter).
     */
    public const ACTIVE_STATUSES = ['verified', 'proposed', 'approved'];

    /**
     * @return array{unit_type: string, status: string, search_text: string, source_revision_id: ?int, parent_type: ?string, parent_id: ?int, employer_normalized: ?string, date_to: ?CarbonImmutable, specificity_score: float}|null
     *                                                                                                                                                                                                                                null for models that are not indexed
     */
    public static function for(Model $source): ?array
    {
        return match (true) {
            $source instanceof Claim => self::claim($source),
            $source instanceof AnswerLibraryEntry => self::answer($source),
            $source instanceof Project => self::project($source),
            $source instanceof Employment => self::employment($source),
            default => null,
        };
    }

    public static function contentHash(string $searchText): string
    {
        return hash('sha256', self::VERSION."\n".$searchText);
    }

    /**
     * Rough "is this concrete?" score in [0, 1]: numbers, percentages or money, and named tools
     * make a bullet point better evidence than a generic statement.
     */
    public static function specificity(string $text): float
    {
        $score = 0.0;

        if (preg_match('/\d/', $text) === 1) {
            $score += 0.4;
        }

        if (preg_match('/%|[£$€]|\b\d+(?:\.\d+)?\s?(?:k|m|x|ms|hours?|minutes?|days?|weeks?)\b/iu', $text) === 1) {
            $score += 0.3;
        }

        // Capitalised words after the first one: product, tool or company names.
        if (preg_match_all('/(?<!^)(?<![.!?]\s)\b[A-Z][a-zA-Z0-9.+#]+/u', $text) >= 1) {
            $score += 0.2;
        }

        if (mb_strlen($text) >= 60) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }

    /**
     * @return array{unit_type: string, status: string, search_text: string, source_revision_id: ?int, parent_type: ?string, parent_id: ?int, employer_normalized: ?string, date_to: ?CarbonImmutable, specificity_score: float}
     */
    private static function claim(Claim $claim): array
    {
        $claim->loadMissing(['currentRevision', 'subject']);
        $subject = $claim->subject;
        $statement = $claim->statement();

        [$context, $employer, $dateTo] = match (true) {
            $subject instanceof Employment => [self::employmentContext($subject), $subject->employer_normalized, $subject->end_date],
            $subject instanceof Project => [self::projectContext($subject), $subject->employment?->employer_normalized, $subject->end_date ?? $subject->employment?->end_date],
            default => [null, null, null],
        };

        return [
            'unit_type' => 'claim',
            'status' => $claim->status->value,
            'search_text' => $context === null ? $statement : "{$context}: {$statement}",
            'source_revision_id' => $claim->current_revision_id,
            'parent_type' => $claim->subject_type,
            'parent_id' => $claim->subject_id,
            'employer_normalized' => $employer,
            'date_to' => $dateTo,
            'specificity_score' => self::specificity($statement),
        ];
    }

    /**
     * @return array{unit_type: string, status: string, search_text: string, source_revision_id: ?int, parent_type: ?string, parent_id: ?int, employer_normalized: ?string, date_to: ?CarbonImmutable, specificity_score: float}
     */
    private static function answer(AnswerLibraryEntry $entry): array
    {
        $entry->loadMissing('currentRevision');

        return [
            'unit_type' => 'library_answer',
            'status' => $entry->status === AnswerStatus::Approved ? 'approved' : $entry->status->value,
            'search_text' => "Question: {$entry->question}\nAnswer: {$entry->body()}",
            'source_revision_id' => $entry->current_revision_id,
            'parent_type' => null,
            'parent_id' => null,
            'employer_normalized' => null,
            'date_to' => null,
            'specificity_score' => self::specificity($entry->body()),
        ];
    }

    /**
     * @return array{unit_type: string, status: string, search_text: string, source_revision_id: ?int, parent_type: ?string, parent_id: ?int, employer_normalized: ?string, date_to: ?CarbonImmutable, specificity_score: float}
     */
    private static function employment(Employment $employment): array
    {
        $parts = [self::employmentContext($employment).'.', $employment->location, $employment->summary];

        return [
            'unit_type' => 'employment_summary',
            'status' => $employment->status->value,
            'search_text' => trim(implode(' ', array_filter($parts))),
            'source_revision_id' => null,
            'parent_type' => null,
            'parent_id' => null,
            'employer_normalized' => $employment->employer_normalized,
            'date_to' => $employment->end_date,
            'specificity_score' => self::specificity((string) $employment->summary),
        ];
    }

    /**
     * @return array{unit_type: string, status: string, search_text: string, source_revision_id: ?int, parent_type: ?string, parent_id: ?int, employer_normalized: ?string, date_to: ?CarbonImmutable, specificity_score: float}
     */
    private static function project(Project $project): array
    {
        $project->loadMissing('employment');

        return [
            'unit_type' => 'project_summary',
            'status' => $project->status->value,
            'search_text' => trim(self::projectContext($project).'. '.($project->summary ?? '')),
            'source_revision_id' => null,
            'parent_type' => $project->employment_id === null ? null : 'employment',
            'parent_id' => $project->employment_id,
            'employer_normalized' => $project->employment?->employer_normalized,
            'date_to' => $project->end_date ?? $project->employment?->end_date,
            'specificity_score' => self::specificity((string) $project->summary),
        ];
    }

    private static function employmentContext(Employment $employment): string
    {
        return "{$employment->title} at {$employment->employer_name} (".self::years($employment->start_date, $employment->end_date).')';
    }

    private static function projectContext(Project $project): string
    {
        $project->loadMissing('employment');
        $details = array_filter([
            $project->role,
            $project->client_name !== null ? "for {$project->client_name}" : null,
            $project->employment !== null ? "at {$project->employment->employer_name}" : null,
            $project->start_date !== null ? self::years($project->start_date, $project->end_date) : null,
        ]);

        return $details === [] ? "Project {$project->name}" : "Project {$project->name} (".implode(', ', $details).')';
    }

    private static function years(?CarbonImmutable $start, ?CarbonImmutable $end): string
    {
        $from = $start?->format('Y') ?? '?';
        $to = $end?->format('Y') ?? 'present';

        return $from === $to ? $from : "{$from}–{$to}";
    }
}
