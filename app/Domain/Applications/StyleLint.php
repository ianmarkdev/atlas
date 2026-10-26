<?php

namespace App\Domain\Applications;

/**
 * Wording a hiring manager reads as filler, found in an answer's text without a model: stock
 * phrases, paragraphs too long to skim, paragraphs that open with a filler transition, and
 * paragraphs that open with the same word. Hints for the candidate, never a block on approving. The stock phrases are also the ones
 * the cover letter rules ban, so the writer and the lint agree.
 *
 * @phpstan-type Finding array{paragraph: int, kind: 'stock_phrase'|'long_paragraph'|'filler_opening'|'repeated_opening', text: string, fix: string}
 */
class StyleLint
{
    public const STOCK_PHRASES = [
        'I am excited',
        'I am writing to',
        'strong background',
        'proven track record',
        'confident in my ability',
        'equipped me',
        'comprehensive understanding',
        'eager to',
        'look forward to the opportunity',
        'passionate',
        'fast-paced environment',
    ];

    public const FILLER_OPENINGS = ['Additionally', 'Furthermore', 'Moreover', 'In addition', 'Also'];

    /**
     * Paragraphs shorter than this (a greeting, a sign-off) are not checked for their opening.
     */
    private const MIN_WORDS_FOR_OPENING = 8;

    /**
     * A paragraph longer than either of these reads as a wall of text.
     */
    private const MAX_SENTENCES = 4;

    private const MAX_WORDS = 90;

    /**
     * The findings in paragraph order; `paragraph` is 0-based, as ParagraphRedraft splits the text,
     * and `fix` is the instruction for redrafting that paragraph.
     *
     * @return list<Finding>
     */
    public static function findings(string $body): array
    {
        $findings = [];
        $openings = [];

        foreach (ParagraphRedraft::paragraphs($body) as $index => $paragraph) {
            foreach (self::STOCK_PHRASES as $phrase) {
                if (mb_stripos($paragraph, $phrase) !== false) {
                    $findings[] = ['paragraph' => $index, 'kind' => 'stock_phrase', 'text' => $phrase, 'fix' => __('Say it plainly, without ":phrase".', ['phrase' => $phrase])];
                }
            }

            $words = count(preg_split('/\s+/u', $paragraph) ?: []);
            $sentences = preg_match_all('/[.!?]+(?=\s+\p{Lu}|\s*$)/u', $paragraph);

            if ($sentences > self::MAX_SENTENCES || $words > self::MAX_WORDS) {
                $findings[] = ['paragraph' => $index, 'kind' => 'long_paragraph', 'text' => $sentences > self::MAX_SENTENCES ? __(':n sentences', ['n' => $sentences]) : __(':n words', ['n' => $words]), 'fix' => __('Cut this paragraph to at most :n sentences and :words words, keeping its main point.', ['n' => self::MAX_SENTENCES, 'words' => self::MAX_WORDS])];
            }

            if ($words < self::MIN_WORDS_FOR_OPENING) {
                continue;
            }

            foreach (self::FILLER_OPENINGS as $opening) {
                if (preg_match('/^'.preg_quote($opening, '/').'\b/iu', $paragraph) === 1) {
                    $findings[] = ['paragraph' => $index, 'kind' => 'filler_opening', 'text' => $opening, 'fix' => __('Start this paragraph without ":opening".', ['opening' => $opening])];
                }
            }

            // "I" opens most first-person paragraphs, so only other repeated first words count.
            $firstWord = preg_match('/^[\p{L}\']+/u', $paragraph, $match) === 1 ? mb_strtolower($match[0]) : '';

            if ($firstWord === '' || $firstWord === 'i') {
                continue;
            }

            if (isset($openings[$firstWord])) {
                $findings[] = ['paragraph' => $index, 'kind' => 'repeated_opening', 'text' => $match[0], 'fix' => __('Start this paragraph differently from paragraph :n.', ['n' => $openings[$firstWord] + 1])];
            } else {
                $openings[$firstWord] = $index;
            }
        }

        return $findings;
    }
}
