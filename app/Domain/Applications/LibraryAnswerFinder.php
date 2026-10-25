<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\AnswerLibraryEntry;
use App\Domain\Knowledge\AnswerStatus;
use App\Domain\Retrieval\Embeddings\EmbeddingFailed;
use App\Domain\Retrieval\Embeddings\EmbeddingProvider;

/**
 * The approved library answer to an application's question, when the library already has one:
 * the same question (case and spacing ignored), or one whose wording is at least
 * `ai.autopilot_library_similarity` close in meaning. Questions are compared with questions —
 * not with the indexed question-and-answer text, whose answer would blur the comparison.
 * Without the embedding model only the same question is found.
 */
class LibraryAnswerFinder
{
    /** @var array<int, array<string, list<float>>> library questions embedded, by profile and entry */
    private array $vectors = [];

    public function __construct(private EmbeddingProvider $embeddings) {}

    public function find(string $question, int $candidateProfileId): ?AnswerLibraryEntry
    {
        $entries = AnswerLibraryEntry::query()
            ->where('candidate_profile_id', $candidateProfileId)
            ->where('status', AnswerStatus::Approved)
            ->with('currentRevision')
            ->orderBy('id')
            ->get()
            ->filter(fn (AnswerLibraryEntry $entry): bool => trim($entry->body()) !== '')
            ->values();

        if ($entries->isEmpty()) {
            return null;
        }

        $wanted = JobAnalysisService::normaliseQuestion($question);
        $same = $entries->first(fn (AnswerLibraryEntry $entry): bool => JobAnalysisService::normaliseQuestion($entry->question) === $wanted);

        if ($same !== null) {
            return $same;
        }

        try {
            $query = $this->embeddings->embedQuery($question);
            $vectors = $this->vectorsOf($entries->all(), $candidateProfileId);
        } catch (EmbeddingFailed) {
            return null;
        }

        $best = null;
        $bestSimilarity = (float) config('ai.autopilot_library_similarity');

        foreach ($entries as $entry) {
            $similarity = self::cosine($query, $vectors[self::key($entry)]);

            if ($similarity >= $bestSimilarity) {
                [$best, $bestSimilarity] = [$entry, $similarity];
            }
        }

        return $best;
    }

    /**
     * @param  list<AnswerLibraryEntry>  $entries
     * @return array<string, list<float>>
     *
     * @throws EmbeddingFailed
     */
    private function vectorsOf(array $entries, int $candidateProfileId): array
    {
        $known = $this->vectors[$candidateProfileId] ?? [];
        $missing = array_values(array_filter($entries, fn (AnswerLibraryEntry $entry): bool => ! isset($known[self::key($entry)])));

        if ($missing !== []) {
            $embedded = $this->embeddings->embedDocuments(array_map(fn (AnswerLibraryEntry $entry): string => $entry->question, $missing));

            foreach ($missing as $index => $entry) {
                $known[self::key($entry)] = $embedded[$index];
            }

            $this->vectors[$candidateProfileId] = $known;
        }

        return $known;
    }

    /**
     * An entry's question can be reworded: a new revision is embedded again.
     */
    private static function key(AnswerLibraryEntry $entry): string
    {
        return "{$entry->id}:{$entry->current_revision_id}:".md5($entry->question);
    }

    /**
     * @param  list<float>  $a  unit length
     * @param  list<float>  $b  unit length
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * ($b[$index] ?? 0.0);
        }

        return $dot;
    }
}
