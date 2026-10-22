<?php

namespace App\Domain\Shared;

/**
 * A word-level diff of two plain texts (M10): what the human changed in an AI draft, and how a
 * suggested change differs from the current text. Whitespace is kept, so the parts joined back
 * together give both texts exactly.
 */
class WordDiff
{
    /** Beyond this many words per side the texts are shown as one replacement (the LCS table is n×m). */
    private const MAX_TOKENS = 1500;

    /**
     * @return list<array{op: 'equal'|'insert'|'delete', text: string}>
     */
    public static function compute(string $from, string $to): array
    {
        $a = self::tokens($from);
        $b = self::tokens($to);

        if (count($a) > self::MAX_TOKENS || count($b) > self::MAX_TOKENS) {
            return self::merge([['op' => 'delete', 'text' => $from], ['op' => 'insert', 'text' => $to]]);
        }

        $n = count($a);
        $m = count($b);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $a[$i] === $b[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $parts = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $parts[] = ['op' => 'equal', 'text' => $a[$i++]];
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $parts[] = ['op' => 'delete', 'text' => $a[$i++]];
            } else {
                $parts[] = ['op' => 'insert', 'text' => $b[$j++]];
            }
        }

        while ($i < $n) {
            $parts[] = ['op' => 'delete', 'text' => $a[$i++]];
        }

        while ($j < $m) {
            $parts[] = ['op' => 'insert', 'text' => $b[$j++]];
        }

        return self::coalesce(self::merge($parts));
    }

    /**
     * Whether anything other than whitespace changed.
     *
     * @param  list<array{op: string, text: string}>  $diff
     */
    public static function hasChanges(array $diff): bool
    {
        foreach ($diff as $part) {
            if ($part['op'] !== 'equal' && trim($part['text']) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The diff as lines for a prompt: `- removed` / `+ added`, unchanged text left out.
     *
     * @param  list<array{op: string, text: string}>  $diff
     */
    public static function render(array $diff): string
    {
        $lines = [];

        foreach ($diff as $part) {
            $text = trim($part['text']);

            if ($part['op'] === 'equal' || $text === '') {
                continue;
            }

            $lines[] = ($part['op'] === 'delete' ? '- ' : '+ ').$text;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> words and the whitespace between them
     */
    private static function tokens(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Read a run of changes split only by spaces as one change ("- a b" / "+ c d" rather than
     * four one-word changes): the spaces go to both sides.
     *
     * @param  list<array{op: 'equal'|'insert'|'delete', text: string}>  $parts
     * @return list<array{op: 'equal'|'insert'|'delete', text: string}>
     */
    private static function coalesce(array $parts): array
    {
        $result = [];
        $deleted = '';
        $inserted = '';
        $inChange = false;

        foreach ($parts as $index => $part) {
            $isGap = $part['op'] === 'equal' && trim($part['text']) === '' && $inChange
                && isset($parts[$index + 1]) && $parts[$index + 1]['op'] !== 'equal';

            if ($part['op'] !== 'equal' || $isGap) {
                $inChange = true;
                $deleted .= $part['op'] !== 'insert' ? $part['text'] : '';
                $inserted .= $part['op'] !== 'delete' ? $part['text'] : '';

                continue;
            }

            $result = [...$result, ...self::change($deleted, $inserted), $part];
            [$deleted, $inserted, $inChange] = ['', '', false];
        }

        return self::merge([...$result, ...self::change($deleted, $inserted)]);
    }

    /**
     * @return list<array{op: 'insert'|'delete', text: string}>
     */
    private static function change(string $deleted, string $inserted): array
    {
        return array_values(array_filter([
            ['op' => 'delete', 'text' => $deleted],
            ['op' => 'insert', 'text' => $inserted],
        ], fn (array $part): bool => $part['text'] !== ''));
    }

    /**
     * Join neighbouring parts of the same kind.
     *
     * @param  list<array{op: string, text: string}>  $parts
     * @return list<array{op: 'equal'|'insert'|'delete', text: string}>
     */
    private static function merge(array $parts): array
    {
        $merged = [];

        foreach ($parts as $part) {
            if ($part['text'] === '') {
                continue;
            }

            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['op'] === $part['op']) {
                $merged[$last]['text'] .= $part['text'];
            } else {
                $merged[] = $part;
            }
        }

        return $merged;
    }
}
