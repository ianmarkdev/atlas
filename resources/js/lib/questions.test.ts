import { describe, expect, it } from 'vite-plus/test';
import { lengthStatus, parseQuestions } from './questions';

describe('parseQuestions', () => {
    it('reads one question per line with an optional trailing limit', () => {
        expect(
            parseQuestions(
                '1. Why do you want to join us? (300 words)\n\n- Tell us about a project [max 1,500 characters]\nNotice period?',
            ),
        ).toEqual([
            {
                prompt: 'Why do you want to join us?',
                word_limit: 300,
                char_limit: null,
            },
            {
                prompt: 'Tell us about a project',
                word_limit: null,
                char_limit: 1500,
            },
            { prompt: 'Notice period?', word_limit: null, char_limit: null },
        ]);
    });

    it('leaves brackets that are not a limit in the question', () => {
        expect(parseQuestions('Describe your role (if any) in hiring')).toEqual(
            [
                {
                    prompt: 'Describe your role (if any) in hiring',
                    word_limit: null,
                    char_limit: null,
                },
            ],
        );
    });
});

describe('lengthStatus', () => {
    it('flags answers over a limit', () => {
        expect(
            lengthStatus('one two three', { word_limit: 2, char_limit: null }),
        ).toMatchObject({
            words: 3,
            over: true,
            label: '3 / 2 words · 13 characters',
        });
        expect(
            lengthStatus('short', { word_limit: null, char_limit: 10 }).over,
        ).toBe(false);
    });
});
