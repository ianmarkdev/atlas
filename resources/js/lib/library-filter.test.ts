import { describe, expect, it } from 'vite-plus/test';
import type { Claim, Employment, Project, ResumeUsage } from '@/types';
import { filterLibrary } from './library-filter';

const can = {
    update: true,
    propose: false,
    verify: false,
    archive: true,
    restore: false,
};

function claim(
    ulid: string,
    statement: string,
    status: Claim['status'] = 'verified',
): Claim {
    return {
        ulid,
        statement,
        kind: 'achievement',
        status,
        revision_no: 1,
        lock_version: 0,
        can: { ...can, reject: false },
    };
}

function project(ulid: string, name: string, claims: Claim[] = []): Project {
    return {
        ulid,
        employment: null,
        name,
        client_name: null,
        role: null,
        start_date: null,
        end_date: null,
        summary: null,
        url: null,
        status: 'verified',
        lock_version: 0,
        claims,
        can,
    };
}

function job(
    ulid: string,
    employer: string,
    claims: Claim[],
    projects: Project[] = [],
): Employment {
    return {
        ulid,
        employer_name: employer,
        title: 'Developer',
        employment_type: null,
        location: null,
        start_date: '2020-01',
        end_date: null,
        summary: null,
        status: 'verified',
        lock_version: 0,
        claims,
        projects,
        can,
    };
}

const usage: ResumeUsage = {
    employments: { e1: ['PHP focused'] },
    projects: {},
    claims: { c1: ['PHP focused'] },
    credentials: {},
    skills: { s1: ['PHP focused'] },
};

const library = {
    employments: [
        job(
            'e1',
            'Northwind',
            [
                claim('c1', 'Cut the invoice run to 20 minutes'),
                claim('c2', 'Mentored two juniors', 'proposed'),
            ],
            [
                project('p1', 'Billing API', [
                    claim('c3', 'Designed the webhook retries'),
                ]),
            ],
        ),
        job('e2', 'Contoso', [claim('c4', 'Built the Rails admin')]),
    ],
    standaloneProjects: [],
    generalClaims: [claim('c5', 'Speaks German')],
    education: [],
    certifications: [],
    skills: [
        { ulid: 's1', name: 'PHP' },
        { ulid: 's2', name: 'Ruby' },
    ],
};

describe('filterLibrary', () => {
    it('returns the library unchanged when no filter is set', () => {
        expect(filterLibrary(library, usage, { text: '  ', mode: 'all' })).toBe(
            library,
        );
    });

    it('keeps a job whose bullet point matches the text, with only the matching bullet points', () => {
        const shown = filterLibrary(library, usage, {
            text: 'WEBHOOK',
            mode: 'all',
        });

        expect(shown.employments.map((e) => e.ulid)).toEqual(['e1']);
        expect(shown.employments[0].claims).toEqual([]);
        expect(
            shown.employments[0].projects?.[0].claims?.map((c) => c.ulid),
        ).toEqual(['c3']);
        expect(shown.generalClaims).toEqual([]);
    });

    it('shows all children of a job that matches the text itself', () => {
        const shown = filterLibrary(library, usage, {
            text: 'contoso',
            mode: 'all',
        });

        expect(shown.employments.map((e) => e.ulid)).toEqual(['e2']);
        expect(shown.employments[0].claims?.map((c) => c.ulid)).toEqual(['c4']);
    });

    it('"needs verification" keeps only proposed items and the jobs containing them', () => {
        const shown = filterLibrary(library, usage, {
            text: '',
            mode: 'unverified',
        });

        expect(shown.employments.map((e) => e.ulid)).toEqual(['e1']);
        expect(shown.employments[0].claims?.map((c) => c.ulid)).toEqual(['c2']);
        expect(shown.employments[0].projects).toEqual([]);
        expect(shown.skills).toEqual([]);
    });

    it('"not on any resume" hides items used by a resume', () => {
        const shown = filterLibrary(library, usage, {
            text: '',
            mode: 'unused',
        });

        expect(shown.employments[0].claims?.map((c) => c.ulid)).toEqual(['c2']);
        expect(shown.employments.map((e) => e.ulid)).toEqual(['e1', 'e2']);
        expect(shown.generalClaims.map((c) => c.ulid)).toEqual(['c5']);
        expect(shown.skills.map((s) => s.ulid)).toEqual(['s2']);
    });
});
