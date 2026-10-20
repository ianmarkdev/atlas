<?php

use App\Domain\Shared\PlainText;

it('removes zero-width characters, bidi overrides and the byte-order mark', function () {
    $text = "\u{FEFF}Built\u{200B} a Laravel\u{202E} API\u{2060}";

    expect(PlainText::normalize($text))->toBe('Built a Laravel API');
});

it('removes control characters', function () {
    expect(PlainText::normalize("Led\x07 a team\x00 of four"))->toBe('Led a team of four');
});

it('composes accented characters to NFC so equal text compares equal', function () {
    $decomposed = "Cafe\u{0301} rewrite";

    expect(PlainText::normalize($decomposed))->toBe("Caf\u{00E9} rewrite");
});

it('collapses runs of spaces, tabs and non-breaking spaces and trims', function () {
    expect(PlainText::normalize("  Senior\t\u{00A0}  developer  "))->toBe('Senior developer');
});

it('joins lines into one line for single-line fields', function () {
    expect(PlainText::normalize("Acme\r\n  Ltd"))->toBe('Acme Ltd');
});

it('keeps line breaks in multi-line fields but allows at most one blank line in a row', function () {
    $text = "First point  \r\n\r\n\r\n\r\n  Second point\nThird";

    expect(PlainText::normalize($text, multiline: true))->toBe("First point\n\nSecond point\nThird");
});
