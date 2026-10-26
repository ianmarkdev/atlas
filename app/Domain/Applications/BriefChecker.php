<?php

namespace App\Domain\Applications;

use App\Domain\Ai\PromptInjectionScanner;

/**
 * The server's checks on a model-written brief (never trust the model alone):
 *
 * - every requirement's quote must come from the job description: `found` (verbatim, ignoring
 *   case and spacing), `close` (≥ 80% of its words appear in order-free overlap), or
 *   `not_found` (the model paraphrased or invented it — show it as unconfirmed);
 * - a question is kept only when its quote is copied from the job description or the notes: its
 *   words, in order, ignoring case and punctuation. The model understands how
 *   the text asks (lists, headings, layouts); the server only checks it did not invent the ask.
 *   The rest go to `dropped_questions`;
 * - the model's injection warnings are kept only when most of their words occur in the texts
 *   (otherwise moved to `dropped_injection_warnings`). They are hints: only the server's own
 *   `server_injection_warnings`, from both texts, hold questions back (docs/04 §4.8).
 */
class BriefChecker
{
    /** A shorter quote ("Your rate") could be found by chance in any text. */
    private const MIN_QUESTION_QUOTE_WORDS = 2;

    /** "Please provide:", "Include all of the following:" — longer text before a colon is part of the quote. */
    private const MAX_HEADING_WORDS = 6;

    public function __construct(private PromptInjectionScanner $scanner) {}

    /**
     * @param  array<string, mixed>  $brief  validated jd_analysis output
     * @param  string  $notes  the application's notes on how to apply, analysed with the job description
     * @return array<string, mixed> the brief with `quote_check` on each requirement, the kept questions as text with their `question_sources`, and `server_injection_warnings`
     */
    public function check(array $brief, string $jobDescription, string $notes = ''): array
    {
        $haystack = self::normalise($jobDescription);
        $words = array_flip(self::words($jobDescription));

        foreach (['must_haves', 'nice_to_haves'] as $list) {
            $brief[$list] = array_map(function (mixed $item) use ($haystack, $words): array {
                $item = is_array($item) ? $item : [];
                $item['quote_check'] = self::quoteCheck((string) ($item['quote'] ?? ''), $haystack, $words);

                return $item;
            }, (array) ($brief[$list] ?? []));
        }

        [$brief['questions'], $brief['question_sources'], $brief['dropped_questions']] = self::checkQuestions((array) ($brief['questions'] ?? []), ['job_description' => $jobDescription, 'notes' => $notes]);
        [$brief['injection_warnings'], $brief['dropped_injection_warnings']] = self::partition((array) ($brief['injection_warnings'] ?? []), $words + array_flip(self::words($notes)), 0.5);

        $brief['server_injection_warnings'] = array_values(array_unique([...$this->scanner->scan($jobDescription), ...$this->scanner->scan($notes)]));

        return $brief;
    }

    /**
     * The questions whose quote is in the job description or the notes, as text, with the name
     * of the text it is in (models name the wrong one); the rest as text, dropped. A question
     * seen twice is kept once.
     *
     * @param  array<mixed>  $questions
     * @param  array{job_description: string, notes: string}  $texts
     * @return array{list<string>, list<'job_description'|'notes'>, list<string>}
     */
    private static function checkQuestions(array $questions, array $texts): array
    {
        $sequences = array_map(fn (string $text): string => ' '.implode(' ', self::words($text)).' ', $texts);
        $kept = [];
        $sources = [];
        $dropped = [];

        foreach ($questions as $question) {
            $question = is_array($question) ? $question : ['question' => $question];
            $text = trim(is_string($question['question'] ?? null) ? $question['question'] : '');
            $source = self::sourceOf(is_string($question['quote'] ?? null) ? $question['quote'] : '', $sequences);

            if ($text === '') {
                continue;
            }

            if ($source === null) {
                $dropped[] = $text;

                continue;
            }

            if (! in_array(JobAnalysisService::normaliseQuestion($text), array_map(JobAnalysisService::normaliseQuestion(...), $kept), true)) {
                $kept[] = $text;
                $sources[] = $source;
            }
        }

        return [$kept, $sources, $dropped];
    }

    /**
     * The text the quote is copied from: its words, in order, in that text. Models often copy a
     * list's heading in front of the item ("Please provide: Your rate"), so the quote also counts
     * without a short heading ending in ":".
     *
     * @param  array<'job_description'|'notes', string>  $sequences  each text's words, space-separated with a space at each end
     * @return 'job_description'|'notes'|null
     */
    private static function sourceOf(string $quote, array $sequences): ?string
    {
        $candidates = [$quote];

        if (preg_match('/^([^:]{1,60}):\s*(\S.*)$/su', $quote, $match) === 1 && count(self::words($match[1])) <= self::MAX_HEADING_WORDS) {
            $candidates[] = $match[2];
        }

        foreach ($candidates as $candidate) {
            $words = self::words($candidate);

            if (count($words) < self::MIN_QUESTION_QUOTE_WORDS) {
                continue;
            }

            foreach ($sequences as $name => $sequence) {
                if (str_contains($sequence, ' '.implode(' ', $words).' ')) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Split model-written strings into those whose words mostly appear in the texts and those
     * that do not.
     *
     * @param  array<mixed>  $items
     * @param  array<string, int>  $words
     * @return array{list<string>, list<string>}
     */
    private static function partition(array $items, array $words, float $threshold): array
    {
        $kept = [];
        $dropped = [];

        foreach ($items as $item) {
            $text = trim((string) (is_string($item) ? $item : ''));

            if ($text === '') {
                continue;
            }

            $itemWords = array_values(array_filter(self::words($text), fn (string $word): bool => mb_strlen($word) > 2));
            $present = array_filter($itemWords, fn (string $word): bool => isset($words[$word]));

            if ($itemWords !== [] && count($present) / count($itemWords) >= $threshold) {
                $kept[] = $text;
            } else {
                $dropped[] = $text;
            }
        }

        return [$kept, $dropped];
    }

    /**
     * @param  array<string, int>  $words
     */
    private static function quoteCheck(string $quote, string $haystack, array $words): string
    {
        $needle = self::normalise($quote);

        if ($needle === '') {
            return 'missing';
        }

        if (str_contains($haystack, $needle)) {
            return 'found';
        }

        $quoteWords = self::words($quote);
        $present = array_filter($quoteWords, fn (string $word): bool => isset($words[$word]));

        return $quoteWords !== [] && count($present) / count($quoteWords) >= 0.8 ? 'close' : 'not_found';
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[“”"\'‘’`]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}+#]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }
}
