import { wordCount } from '@/lib/resume-document';

export type ParsedQuestion = {
    prompt: string;
    word_limit: number | null;
    char_limit: number | null;
};

const LIMIT =
    /\s*[([]\s*(?:max(?:imum)?\.?\s*|up to\s*|≤\s*)?(\d[\d,.\s]*)\s*(words?|characters?|chars?)\s*(?:max(?:imum)?)?\s*[)\]]\s*$/iu;

/**
 * Mirrors BulkStoreQuestionsRequest::parsedQuestions() for the preview: one question per line,
 * list markers dropped, a trailing "(300 words)" / "(max 1,500 characters)" read as the limit.
 */
export function parseQuestions(text: string): ParsedQuestion[] {
    return text
        .replace(/\r\n?/g, '\n')
        .split('\n')
        .flatMap((raw) => {
            let line = raw.replace(/^\s*(?:[-*•]|\d+[.)])\s+/u, '').trim();
            let wordLimit: number | null = null;
            let charLimit: number | null = null;
            const match = line.match(LIMIT);

            if (match) {
                const limit = Number(match[1].replace(/\D/g, ''));
                const isWords = match[2].toLowerCase().startsWith('word');
                wordLimit = isWords && limit > 0 ? limit : null;
                charLimit = !isWords && limit > 0 ? limit : null;
                line = line.slice(0, line.length - match[0].length).trim();
            }

            return line === ''
                ? []
                : [
                      {
                          prompt: line,
                          word_limit: wordLimit,
                          char_limit: charLimit,
                      },
                  ];
        });
}

/**
 * "120 / 300 words" style counters for an answer, with whether a limit is exceeded.
 */
export function lengthStatus(
    text: string,
    limits: { word_limit: number | null; char_limit: number | null },
): { words: number; chars: number; over: boolean; label: string } {
    const words = wordCount(text);
    const chars = text.length;
    const parts = [
        limits.word_limit === null
            ? `${words} words`
            : `${words} / ${limits.word_limit} words`,
        limits.char_limit === null
            ? `${chars} characters`
            : `${chars} / ${limits.char_limit} characters`,
    ];

    return {
        words,
        chars,
        over:
            (limits.word_limit !== null && words > limits.word_limit) ||
            (limits.char_limit !== null && chars > limits.char_limit),
        label: parts.join(' · '),
    };
}
