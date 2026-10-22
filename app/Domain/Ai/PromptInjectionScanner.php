<?php

namespace App\Domain\Ai;

/**
 * Server-side check of untrusted text (job descriptions) for instructions aimed at an AI model.
 * Independent of the model's own `injection_warnings`, which a successful injection could
 * suppress. Matches are reported to the user; the text is still sent as data, never obeyed.
 */
class PromptInjectionScanner
{
    private const PATTERNS = [
        '/\b(ignore|disregard|forget|override)\b[^.\n]{0,40}\b(previous|prior|above|earlier|all|any|the)\b[^.\n]{0,20}\b(instructions?|prompts?|rules|directions)\b/i',
        '/\b(you are now|from now on you|act as|pretend to be|roleplay as)\b/i',
        '/\b(system prompt|developer message|hidden instructions?|new instructions?)\b/i',
        '/<\/?\s*(system|assistant|instructions?|prompt)\s*>/i',
        '/\b(say|state|write|claim)\s+(that\s+)?the\s+(candidate|applicant)\b/i',
        '/\b(reveal|print|output|repeat)\b[^.\n]{0,30}\b(prompt|instructions|api key|secret)\b/i',
    ];

    /**
     * @return list<string> the suspicious passages (trimmed), in order of appearance
     */
    public function scan(string $text): array
    {
        $findings = [];

        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $sentence) {
            $sentence = trim($sentence);

            foreach (self::PATTERNS as $pattern) {
                if ($sentence !== '' && preg_match($pattern, $sentence) === 1) {
                    $findings[] = mb_strimwidth($sentence, 0, 300, '…');

                    break;
                }
            }
        }

        return array_values(array_unique($findings));
    }
}
