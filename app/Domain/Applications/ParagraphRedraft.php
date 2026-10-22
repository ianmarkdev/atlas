<?php

namespace App\Domain\Applications;

/**
 * Redrafting one paragraph of an answer: the AI rewrites that paragraph only, the server puts it
 * back in place, and what was already checked in the other paragraphs stays checked — the
 * claims of the AI version it was cut from that still appear word for word elsewhere keep their
 * labels and review decisions, and that version's questions and their decisions are kept.
 */
class ParagraphRedraft
{
    /**
     * The answer's paragraphs (split on blank lines), trimmed, empty ones dropped.
     *
     * @return list<string>
     */
    public static function paragraphs(string $body): array
    {
        $parts = preg_split('/\R\s*\R/u', trim($body)) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), fn (string $part): bool => $part !== ''));
    }

    /**
     * The answer with paragraph `$index` replaced, paragraphs separated by one blank line.
     */
    public static function splice(string $body, int $index, string $paragraph): string
    {
        $paragraphs = self::paragraphs($body);
        $paragraphs[$index] = trim($paragraph);

        return implode("\n\n", $paragraphs);
    }

    /**
     * The prompt context: the paragraph to rewrite on its own, between the rest of the answer
     * (shown apart, so a small model does not copy a list back).
     *
     * @return array{before: string, current: string, after: string}
     */
    public static function context(string $body, int $index): array
    {
        $paragraphs = self::paragraphs($body);

        return [
            'before' => implode("\n\n", array_slice($paragraphs, 0, $index)) ?: '(this is the first paragraph)',
            'current' => $paragraphs[$index] ?? '',
            'after' => implode("\n\n", array_slice($paragraphs, $index + 1)) ?: '(this is the last paragraph)',
        ];
    }

    /**
     * The model's paragraph as one plain paragraph: tags it echoed and blank lines removed.
     */
    public static function clean(string $paragraph): string
    {
        $text = (string) preg_replace('/<\/?(?:paragraph|paragraph_to_rewrite)\b[^>]*>/iu', '', $paragraph);

        return trim((string) preg_replace('/\R\s*\R/u', ' ', $text));
    }

    /**
     * Whether the model handed the paragraph back without a real change.
     */
    public static function isUnchanged(string $before, string $after): bool
    {
        $normalize = fn (string $text): string => (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($text)));

        return $normalize($before) === $normalize($after);
    }

    /**
     * Merge the checked claims of the new paragraph with what is kept from the base version.
     *
     * @param  list<array<string, mixed>>  $newClaims  the new paragraph's claims, already checked
     * @param  list<array{question: string, why: string}>  $newQuestions
     * @return array{grounding: list<array<string, mixed>>, questions: list<array{question: string, why: string}>, reviews: list<array{item_type: string, item_index: int, decision: string, claim_id: ?int, decided_by: ?int}>}
     *                                                                                                                                                                                                                           reviews to copy onto the new version
     */
    public function merge(AnswerDraft $draft, AnswerDraftVersion $base, string $newBody, int $index, array $newClaims, array $newQuestions): array
    {
        $grounding = [];
        $reviews = [];
        $isAiBase = $base->author_type === 'ai';
        $wasBulkReviewed = $isAiBase && $draft->claims_reviewed_version_id === $base->id;
        $baseReviews = $isAiBase ? $base->reviews->keyBy(fn (AnswerClaimReview $review): string => "{$review->item_type}:{$review->item_index}") : collect();
        $kept = mb_strtolower(implode("\n\n", array_diff_key(self::paragraphs($newBody), [$index => true])));

        foreach ($isAiBase ? ($base->grounding ?? []) : [] as $oldIndex => $claim) {
            $text = mb_strtolower(trim((string) ($claim['text'] ?? '')));

            if ($text === '' || ! str_contains($kept, $text)) {
                continue;
            }

            $newIndex = count($grounding);
            $grounding[] = $claim;
            $review = $baseReviews->get("claim:{$oldIndex}");

            if ($review !== null) {
                $reviews[] = ['item_type' => 'claim', 'item_index' => $newIndex, 'decision' => $review->decision, 'claim_id' => $review->claim_id, 'decided_by' => $review->decided_by];
            } elseif ($wasBulkReviewed) {
                // "Keep all as worded" on the base version covered this claim too.
                $reviews[] = ['item_type' => 'claim', 'item_index' => $newIndex, 'decision' => 'accepted', 'claim_id' => null, 'decided_by' => null];
            }
        }

        $seen = array_flip(array_map(fn (array $claim): string => mb_strtolower(trim((string) $claim['text'])), $grounding));

        foreach ($newClaims as $claim) {
            $key = mb_strtolower(trim((string) ($claim['text'] ?? '')));

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $grounding[] = $claim;
            }
        }

        $questions = $isAiBase ? array_values($base->clarification_questions ?? []) : [];

        foreach ($questions as $questionIndex => $question) {
            $review = $baseReviews->get("question:{$questionIndex}");

            if ($review !== null) {
                $reviews[] = ['item_type' => 'question', 'item_index' => $questionIndex, 'decision' => $review->decision, 'claim_id' => $review->claim_id, 'decided_by' => $review->decided_by];
            }
        }

        $asked = array_flip(array_map(fn (array $question): string => mb_strtolower($question['question']), $questions));

        foreach ($newQuestions as $question) {
            if (! isset($asked[mb_strtolower($question['question'])])) {
                $questions[] = $question;
            }
        }

        return ['grounding' => $grounding, 'questions' => $questions, 'reviews' => $reviews];
    }
}
