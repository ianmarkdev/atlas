<?php

namespace App\Domain\Ai;

/**
 * How much text a prompt may carry. A local model's prompt must fit its window
 * with the output (`context` minus `max_tokens`) — Ollama silently drops the start of a longer
 * one; an external model's window is far larger, so `ai.external_input_tokens` caps what one
 * request sends instead. Estimated like BudgetGuard, at ~4 characters per token, with a margin.
 */
class PromptBudget
{
    public const CHARACTERS_PER_TOKEN = 4;

    /** Share of the window kept free, for text that takes more tokens than estimated. */
    public const MARGIN = 0.15;

    /**
     * Characters left for the flexible parts of a prompt: the window, less the system prompt
     * and the user prompt rendered with `$variables` (flexible parts given as '').
     *
     * @param  array<string, string>  $variables
     */
    public function remaining(TaskRoute $route, Prompt $prompt, array $variables): int
    {
        $tokens = $route->external || $route->contextTokens === null
            ? (int) config('ai.external_input_tokens')
            : $route->contextTokens - $route->maxTokens;
        $capacity = (int) floor($tokens * (1 - self::MARGIN)) * self::CHARACTERS_PER_TOKEN;

        return max(0, $capacity - mb_strlen($prompt->system) - mb_strlen($prompt->render($variables)));
    }

    /**
     * `$text` cut to at most `$characters`, at a line or word break, ending with `$note` when
     * anything was left out.
     */
    public static function shorten(string $text, int $characters, string $note): string
    {
        if (mb_strlen($text) <= $characters) {
            return $text;
        }

        $kept = mb_substr($text, 0, max(0, $characters - mb_strlen($note) - 1));
        $lineBreak = mb_strrpos($kept, "\n");
        $space = mb_strrpos($kept, ' ');

        if ($lineBreak !== false && $lineBreak > mb_strlen($kept) / 2) {
            $kept = mb_substr($kept, 0, $lineBreak);
        } elseif ($space !== false) {
            $kept = mb_substr($kept, 0, $space);
        }

        $kept = rtrim($kept);

        return $kept === '' ? $note : "{$kept}\n{$note}";
    }
}
