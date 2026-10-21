<?php

namespace App\Domain\Shared;

use Normalizer;

/**
 * Normalises user-typed or pasted plain text (D8: all input is plain text) before validation.
 */
class PlainText
{
    /**
     * Zero-width characters, bidirectional overrides and the byte-order mark. They are invisible,
     * so they only ever hide content (e.g. prompt injection) or break duplicate detection.
     */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    /** Control characters other than tab (\x09) and newline (\x0A). */
    private const CONTROL = '/[\x00-\x08\x0B-\x1F\x7F]/u';

    /**
     * Unicode NFC, strip invisible and control characters, collapse runs of spaces, trim.
     * Multi-line text keeps line breaks (at most one blank line in a row); single-line text joins lines.
     */
    public static function normalize(string $text, bool $multiline = false): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = self::replace(self::INVISIBLE, '', $text);
        $text = self::replace(self::CONTROL, '', $text);
        $text = self::replace('/[\t \x{00A0}]+/u', ' ', $text);

        if (! $multiline) {
            return trim(self::replace('/\s*\n\s*/', ' ', $text));
        }

        $lines = array_map(trim(...), explode("\n", $text));

        return trim(self::replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * `preg_replace` returns null on invalid UTF-8; keep the input unchanged in that case.
     */
    /**
     * Words as the editors count them: runs of non-whitespace.
     */
    public static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? $text;
    }
}
