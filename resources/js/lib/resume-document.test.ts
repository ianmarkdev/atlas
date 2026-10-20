import { describe, expect, it } from 'vite-plus/test';
import type { Claim, Credential, Employment, ResumeDocument } from '@/types';
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

const degree: Credential = {
    ulid: 'cred1',
    type: 'education',
    institution: 'University of Leeds',
    name: 'BSc',
    field_of_study: 'Computer Science',
    start_date: '2012-09',
    end_date: '2015-07',
    expires_on: null,
    credential_url: null,
    summary: null,
    status: 'verified',
    lock_version: 0,
    can,
};

const index = indexLibrary({
    employments: [employment],
    standaloneProjects: [],
    education: [degree],
    certifications: [],
    skills: [
        { ulid: 's1', name: 'PHP' },
        { ulid: 's2', name: 'Laravel' },
    ],
    languages: [{ ulid: 'l1', language: 'English', proficiency: 'native' }],
    links: [
        {
            ulid: 'k1',
            kind: 'github',
            label: 'GitHub',
            url: 'https://github.com/alex',
        },
    ],
});

const emptySections = {
    projects: [],
    skills: [],
    education: [],
    certifications: [],
    languages: [],
    links: [],
};

describe('resumeToPlainText', () => {
    it('uses the display title and per-resume wording, and skips experience missing from the library', () => {
        const document: ResumeDocument = {
            schema_version: 1,
            title: 'Senior PHP Engineer',
            summary: 'Ten years of Laravel.',
            ...emptySections,
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

    it('adds links, grouped skills, education and languages, leaving out unknown items', () => {
        const document: ResumeDocument = {
            schema_version: 1,
            title: null,
            summary: null,
            experience: [],
            projects: [],
            skills: [
                { label: 'Backend', items: ['s1', 's2', 'gone'] },
                { label: 'Empty', items: ['gone'] },
            ],
            education: [{ credential: 'cred1' }],
            certifications: [],
            languages: ['l1'],
            links: ['k1'],
        };

        const text = resumeToPlainText(
            document,
            {
                display_name: 'Alex Morgan',
                email: null,
                phone: null,
                location: null,
            },
            index,
        );

        expect(text).toBe(
            [
                'Alex Morgan',
                'GitHub: https://github.com/alex',
                '',
                'SKILLS',
                'Backend: PHP, Laravel',
                '',
                'EDUCATION',
                'BSc, Computer Science — University of Leeds (Sept 2012 – Jul 2015)',
                '',
                'LANGUAGES',
                'English (native)',
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
