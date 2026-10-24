<?php

namespace App\Domain\Applications;

use App\Domain\Ai\PromptInjectionScanner;

/**
 * The server's checks on a model-written brief (never trust the model alone):
 *
 * - every requirement's quote must come from the job description: `found` (verbatim, ignoring
 *   case and spacing), `close` (≥ 80% of its words appear in order-free overlap), or
 *   `not_found` (the model paraphrased or invented it — show it as unconfirmed);
 * - questions and the model's injection warnings are kept only when most of their words occur
 *   in the job description (otherwise moved to `dropped_questions` / `dropped_injection_warnings`);
 * - prompt-injection passages found by the server are merged with the model's own warnings.
 */
class BriefChecker
{
    public function __construct(private PromptInjectionScanner $scanner) {}

    /**
     * @param  array<string, mixed>  $brief  validated jd_analysis output
     * @return array<string, mixed> the brief with `quote_check` on each requirement and `server_injection_warnings`
     */
    public function check(array $brief, string $jobDescription): array
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

        // Questions and the model's injection warnings must point at text that is really there
        // (the 3B model invents generic questions and warnings); the rest is kept aside, visibly.
        [$brief['questions'], $brief['dropped_questions']] = self::matchQuestions((array) ($brief['questions'] ?? []), self::askedInText($jobDescription));
        [$brief['injection_warnings'], $brief['dropped_injection_warnings']] = self::partition((array) ($brief['injection_warnings'] ?? []), $words, 0.5);

        $brief['server_injection_warnings'] = $this->scanner->scan($jobDescription);

        return $brief;
    }

    /**
     * What the job description itself asks the applicant: sentences ending in "?", prompts such
     * as "Tell us…", "Describe…", "Please explain…", and the items of a list introduced as what
     * to send ("Please provide:", "In your proposal, include:").
     *
     * @return list<list<string>> the words of each asked passage
     */
    private static function askedInText(string $text): array
    {
        $asked = [];
        $inAskedList = false;

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $isListItem = preg_match('/^(?:[-*•]|\d+[.)])\s+/u', $line) === 1;

            if ($isListItem && $inAskedList) {
                $asked[] = self::askedWords((string) preg_replace('/^(?:[-*•]|\d+[.)])\s+/u', '', $line));

                continue;
            }

            if (! $isListItem) {
                $inAskedList = self::introducesAskedList($line);
            }

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                $sentence = trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $sentence));

                if ($sentence !== '' && (str_ends_with($sentence, '?') || preg_match('/^(please\s+)?(tell us|describe|explain|share|give (us )?an example|outline|summari[sz]e)\b/iu', $sentence) === 1)) {
                    $asked[] = self::askedWords($sentence);
                }
            }
        }

        return $asked;
    }

    /**
     * A line that introduces a list of what to send with the application, such as "Please
     * provide:" or "When applying, include:" (not "Responsibilities include:").
     */
    private static function introducesAskedList(string $line): bool
    {
        return str_ends_with($line, ':')
            && preg_match('/\b(please|apply\w*|application|proposal|cover letter|answer|questions?|tell us|let us know|send us|submit)\b/iu', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private static function askedWords(string $passage): array
    {
        return array_values(array_filter(self::words($passage), fn (string $word): bool => mb_strlen($word) > 2));
    }

    /**
     * Keep the model's questions that correspond to something the text asks (≥ 60% of the
     * question's words in one asked passage); the rest are dropped as invented.
     *
     * @param  array<mixed>  $questions
     * @param  list<list<string>>  $asked
     * @return array{list<string>, list<string>}
     */
    private static function matchQuestions(array $questions, array $asked): array
    {
        $kept = [];
        $dropped = [];

        foreach ($questions as $question) {
            $text = trim(is_string($question) ? $question : '');

            if ($text === '') {
                continue;
            }

            $questionWords = array_values(array_filter(self::words($text), fn (string $word): bool => mb_strlen($word) > 2));
            $matches = false;

            foreach ($asked as $passage) {
                $passageWords = array_flip($passage);
                $present = array_filter($questionWords, fn (string $word): bool => isset($passageWords[$word]));

                if ($questionWords !== [] && count($present) / count($questionWords) >= 0.6) {
                    $matches = true;

                    break;
                }
            }

            if ($matches) {
                $kept[] = $text;
            } else {
                $dropped[] = $text;
            }
        }

        return [$kept, $dropped];
    }

    /**
     * Split model-written strings into those whose words mostly appear in the job description and
     * those that do not.
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
