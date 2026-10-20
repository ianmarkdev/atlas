<?php

namespace App\Domain\Knowledge;

/**
 * How many current (approved or draft) answers rely on each bullet point, keyed by claim ULID
 * ("Backs 2 answers").
 */
class AnswerCitations
{
    /**
     * @return array<string, int>
     */
    public static function countsByClaim(): array
    {
        $counts = [];

        $entries = AnswerLibraryEntry::query()
            ->whereIn('status', [AnswerStatus::Approved, AnswerStatus::Draft])
            ->with('currentRevision')
            ->get();

        foreach ($entries as $entry) {
            foreach (array_unique($entry->claimUlids()) as $ulid) {
                $counts[$ulid] = ($counts[$ulid] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
