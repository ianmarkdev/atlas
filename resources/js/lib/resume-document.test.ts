import { describe, expect, it } from 'vite-plus/test';
import type { Claim, Employment, ResumeDocument } from '@/types';
import { indexLibrary, moveItem, resumeToPlainText } from './resume-document';

const can = {
    update: true,
    verify: false,
    archive: true,
    restore: false,
    reject: false,
};

function claim(ulid: string, statement: string): Claim {
    return {
        ulid,
        statement,
        kind: 'achievement',
        status: 'verified',
        revision_no: 1,
        lock_version: 0,
        can,
    };
}

const employment: Employment = {
    ulid: 'emp1',
    employer_name: 'Northwind Payments',
    title: 'Senior Backend Developer',
    employment_type: 'full_time',
    location: 'London',
    start_date: '2022-04',
    end_date: null,
    summary: null,
    status: 'verified',
    lock_version: 0,
    can,
    claims: [
        claim('c1', 'Cut the invoice run to 20 minutes'),
        claim('c2', 'Mentored two juniors'),
    ],
    projects: [],
};

const index = indexLibrary({
    employments: [employment],
    standaloneProjects: [],
});

describe('resumeToPlainText', () => {
    it('uses the display title and per-resume wording, and skips jobs missing from the library', () => {
        const document: ResumeDocument = {
            schema_version: 1,
            title: 'Senior PHP Engineer',
            summary: 'Ten years of Laravel.',
            experience: [
                {
                    employment: 'emp1',
                    display_title: 'Senior PHP Developer',
                    description: null,
                    bullets: [
                        { claim: 'c1', text: null },
                        {
                            claim: 'c2',
                            text: 'Mentored two junior PHP developers',
                        },
                    ],
                    projects: [],
                },
                {
                    employment: 'gone',
                    display_title: null,
                    description: null,
                    bullets: [],
                    projects: [],
                },
            ],
            projects: [],
        };

        const text = resumeToPlainText(
            document,
            {
                display_name: 'Alex Morgan',
                email: 'alex@example.com',
                phone: null,
                location: 'London',
            },
            index,
        );

        expect(text).toBe(
            [
                'Alex Morgan',
                'alex@example.com · London',
                '',
                'Senior PHP Engineer',
                '',
                'Ten years of Laravel.',
                '',
                'EXPERIENCE',
                '',
                'Senior PHP Developer — Northwind Payments',
                'Apr 2022 – Present · London',
                '- Cut the invoice run to 20 minutes',
                '- Mentored two junior PHP developers',
            ].join('\n'),
        );
    });
});

describe('moveItem', () => {
    it('moves an item and leaves the list unchanged at the edges', () => {
        expect(moveItem(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
        expect(moveItem(['a', 'b', 'c'], 0, -1)).toEqual(['a', 'b', 'c']);
    });
});
