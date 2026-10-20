import type {
    Claim,
    Credential,
    Employment,
    Project,
    ResumeUsage,
    Skill,
} from '@/types';

export type LibraryFilterMode = 'all' | 'unverified' | 'unused';

export type LibraryFilter = { text: string; mode: LibraryFilterMode };

type Library = {
    employments: Employment[];
    standaloneProjects: Project[];
    generalClaims: Claim[];
    education: Credential[];
    certifications: Credential[];
    skills: Skill[];
};

export function isFiltering(filter: LibraryFilter): boolean {
    return filter.text.trim() !== '' || filter.mode !== 'all';
}

/**
 * Narrow the Library to what matches the search text and the mode ("needs verification",
 * "not on any resume"). A job or project stays when it matches itself or when any of its
 * projects or bullet points match; when the job itself matches the text, its children only
 * have to match the mode.
 */
export function filterLibrary(
    library: Library,
    usage: ResumeUsage,
    filter: LibraryFilter,
): Library {
    if (!isFiltering(filter)) {
        return library;
    }

    const needle = filter.text.trim().toLocaleLowerCase();
    const matchesText = (...texts: (string | null | undefined)[]) =>
        needle === '' ||
        texts.some((text) => text?.toLocaleLowerCase().includes(needle));

    const matchesMode = (status: string, usedIn: string[] | undefined) => {
        switch (filter.mode) {
            case 'unverified':
                return status === 'proposed';
            case 'unused':
                return (usedIn ?? []).length === 0;
            default:
                return true;
        }
    };

    const claimMatches = (claim: Claim, ignoreText: boolean) =>
        (ignoreText || matchesText(claim.statement)) &&
        matchesMode(claim.status, usage.claims[claim.ulid]);

    const filterProject = (
        project: Project,
        ignoreText: boolean,
    ): Project | null => {
        const textHit =
            ignoreText ||
            matchesText(
                project.name,
                project.client_name,
                project.role,
                project.summary,
            );
        const self =
            textHit &&
            matchesMode(project.status, usage.projects[project.ulid]);
        const claims = (project.claims ?? []).filter((claim) =>
            claimMatches(claim, textHit),
        );

        return self || claims.length > 0 ? { ...project, claims } : null;
    };

    const employments = library.employments.flatMap((employment) => {
        const textHit = matchesText(
            employment.title,
            employment.employer_name,
            employment.location,
            employment.summary,
        );
        const self =
            textHit &&
            matchesMode(employment.status, usage.employments[employment.ulid]);
        const projects = (employment.projects ?? []).flatMap(
            (project) => filterProject(project, textHit) ?? [],
        );
        const claims = (employment.claims ?? []).filter((claim) =>
            claimMatches(claim, textHit),
        );

        return self || projects.length > 0 || claims.length > 0
            ? [{ ...employment, projects, claims }]
            : [];
    });

    const credentialMatches = (credential: Credential) =>
        matchesText(
            credential.name,
            credential.institution,
            credential.field_of_study,
            credential.summary,
        ) && matchesMode(credential.status, usage.credentials[credential.ulid]);

    return {
        employments,
        standaloneProjects: library.standaloneProjects.flatMap(
            (project) => filterProject(project, false) ?? [],
        ),
        generalClaims: library.generalClaims.filter((claim) =>
            claimMatches(claim, false),
        ),
        education: library.education.filter(credentialMatches),
        certifications: library.certifications.filter(credentialMatches),
        skills: library.skills.filter(
            (skill) =>
                matchesText(skill.name) &&
                filter.mode !== 'unverified' &&
                matchesMode('verified', usage.skills[skill.ulid]),
        ),
    };
}
