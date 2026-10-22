<?php

namespace App\Domain\Applications;

use App\Domain\Ai\PromptInjectionScanner;

/**
 * The server's checks on a model-written brief (never trust the model alone):
 *
 * - every requirement's quote must come from the job description: `found` (verbatim, ignoring
 *   case and spacing), `close` (≥ 80% of its words appear in order-free overlap), or
 *   `not_found` (the model paraphrased or invented it — show it as unconfirmed);
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

        $brief['server_injection_warnings'] = $this->scanner->scan($jobDescription);

        return $brief;
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
