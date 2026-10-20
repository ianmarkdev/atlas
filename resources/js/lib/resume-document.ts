import type {
    Claim,
    Contact,
    ContactLink,
    Credential,
    Employment,
    Language,
    Project,
    ResumeBullet,
    ResumeDocument,
    ResumeLibrary,
    Skill,
} from '@/types';

/** Library items by ULID, so the editor and preview can show facts for a document's refs. */
export type LibraryIndex = {
    employments: Map<string, Employment>;
    projects: Map<string, Project>;
    claims: Map<string, Claim>;
    credentials: Map<string, Credential>;
    skills: Map<string, Skill>;
    languages: Map<string, Language>;
    links: Map<string, ContactLink>;
};

const byUlid = <T extends { ulid: string }>(items: T[]) =>
    new Map(items.map((item) => [item.ulid, item]));

export function indexLibrary(library: ResumeLibrary): LibraryIndex {
    const index: LibraryIndex = {
        employments: new Map(),
        projects: new Map(),
        claims: new Map(),
        credentials: byUlid([...library.education, ...library.certifications]),
        skills: byUlid(library.skills),
        languages: byUlid(library.languages),
        links: byUlid(library.links),
    };

    const addProject = (project: Project) => {
        index.projects.set(project.ulid, project);
        (project.claims ?? []).forEach((claim) =>
            index.claims.set(claim.ulid, claim),
        );
    };

    library.employments.forEach((employment) => {
        index.employments.set(employment.ulid, employment);
        (employment.claims ?? []).forEach((claim) =>
            index.claims.set(claim.ulid, claim),
        );
        (employment.projects ?? []).forEach(addProject);
    });
    library.standaloneProjects.forEach(addProject);

    return index;
}

/** Returns a copy of `items` with the item at `index` moved by `delta` (-1 up, +1 down). */
export function moveItem<T>(items: T[], index: number, delta: number): T[] {
    const target = index + delta;

    if (target < 0 || target >= items.length) {
        return items;
    }

    const copy = [...items];
    const [item] = copy.splice(index, 1);
    copy.splice(target, 0, item);

    return copy;
}

export function bulletText(bullet: ResumeBullet, index: LibraryIndex): string {
    return bullet.text ?? index.claims.get(bullet.claim)?.statement ?? '';
}

export function wordCount(text: string | null): number {
    return (text ?? '').trim().split(/\s+/).filter(Boolean).length;
}

export function formatMonth(value: string | null): string {
    if (value === null) {
        return '';
    }

    return new Date(`${value}-01T00:00:00`).toLocaleDateString('en-GB', {
        month: 'short',
        year: 'numeric',
    });
}

export function formatPeriod(start: string | null, end: string | null): string {
    if (start === null) {
        return end === null ? '' : formatMonth(end);
    }

    return `${formatMonth(start)} – ${end === null ? 'Present' : formatMonth(end)}`;
}

/** "BSc Computer Science — University of Leeds" / "AWS Certified Developer — Amazon Web Services". */
export function credentialHeading(credential: Credential): string {
    const name = credential.field_of_study
        ? `${credential.name}, ${credential.field_of_study}`
        : credential.name;

    return `${name} — ${credential.institution}`;
}

/** Skill groups with names resolved; unknown skills and empty groups left out. */
export function resolvedSkillGroups(
    document: ResumeDocument,
    index: LibraryIndex,
): { label: string | null; names: string[] }[] {
    return document.skills
        .map((group) => ({
            label: group.label,
            names: group.items
                .map((ulid) => index.skills.get(ulid)?.name)
                .filter((name): name is string => Boolean(name)),
        }))
        .filter((group) => group.names.length > 0);
}

export function resolvedLanguages(
    document: ResumeDocument,
    index: LibraryIndex,
): Language[] {
    return document.languages
        .map((ulid) => index.languages.get(ulid))
        .filter((language): language is Language => Boolean(language));
}

export function resolvedLinks(
    document: ResumeDocument,
    index: LibraryIndex,
): ContactLink[] {
    return document.links
        .map((ulid) => index.links.get(ulid))
        .filter((link): link is ContactLink => Boolean(link));
}

export function resolvedCredentials(
    items: ResumeDocument['education'],
    index: LibraryIndex,
): Credential[] {
    return items
        .map((item) => index.credentials.get(item.credential))
        .filter((credential): credential is Credential => Boolean(credential));
}

/**
 * Drops skills, education, certifications, languages and links that are no longer in the
 * library, so a resume still saves after one was deleted elsewhere. (Missing experience is
 * shown in the editor with a warning instead, because it may carry bullets and wording.)
 */
export function pruneMissing(
    document: ResumeDocument,
    index: LibraryIndex,
): ResumeDocument {
    return {
        ...document,
        skills: document.skills
            .map((group) => ({
                ...group,
                items: group.items.filter((ulid) => index.skills.has(ulid)),
            }))
            .filter((group) => group.items.length > 0),
        education: document.education.filter((item) =>
            index.credentials.has(item.credential),
        ),
        certifications: document.certifications.filter((item) =>
            index.credentials.has(item.credential),
        ),
        languages: document.languages.filter((ulid) =>
            index.languages.has(ulid),
        ),
        links: document.links.filter((ulid) => index.links.has(ulid)),
    };
}

/**
 * The resume as plain text (D6: plain-text export), in the order shown in the preview.
 * Items missing from the library are left out.
 */
export function resumeToPlainText(
    document: ResumeDocument,
    contact: Contact,
    index: LibraryIndex,
): string {
    const lines: string[] = [contact.display_name];
    const contactLine = [contact.email, contact.phone, contact.location]
        .filter(Boolean)
        .join(' · ');

    if (contactLine) {
        lines.push(contactLine);
    }

    resolvedLinks(document, index).forEach((link) =>
        lines.push(`${link.label}: ${link.url}`),
    );

    if (document.title) {
        lines.push('', document.title);
    }

    if (document.summary) {
        lines.push('', document.summary);
    }

    const skillGroups = resolvedSkillGroups(document, index);

    if (skillGroups.length > 0) {
        lines.push('', 'SKILLS');
        skillGroups.forEach((group) =>
            lines.push(
                group.label
                    ? `${group.label}: ${group.names.join(', ')}`
                    : group.names.join(', '),
            ),
        );
    }

    const bullets = (items: ResumeBullet[]) =>
        items
            .map((bullet) => bulletText(bullet, index))
            .filter(Boolean)
            .map((text) => `- ${text}`);

    const projectBlock = (
        item: ResumeDocument['projects'][number],
        indent: string,
    ): string[] => {
        const project = index.projects.get(item.project);

        if (!project) {
            return [];
        }

        const heading = [project.name, project.role]
            .filter(Boolean)
            .join(' — ');
        const period = formatPeriod(project.start_date, project.end_date);

        return [
            `${indent}${heading}${period ? ` (${period})` : ''}`,
            ...(item.description ? [`${indent}${item.description}`] : []),
            ...bullets(item.bullets).map((line) => `${indent}${line}`),
        ];
    };

    const jobs = document.experience.filter((job) =>
        index.employments.has(job.employment),
    );

    if (jobs.length > 0) {
        lines.push('', 'EXPERIENCE');

        jobs.forEach((job) => {
            const employment = index.employments.get(job.employment)!;
            const title = job.display_title ?? employment.title;
            const meta = [
                formatPeriod(employment.start_date, employment.end_date),
                employment.location,
            ]
                .filter(Boolean)
                .join(' · ');

            lines.push('', `${title} — ${employment.employer_name}`, meta);

            if (job.description) {
                lines.push(job.description);
            }

            lines.push(...bullets(job.bullets));
            job.projects.forEach((item) =>
                lines.push(...projectBlock(item, '  ')),
            );
        });
    }

    const standalone = document.projects.filter((item) =>
        index.projects.has(item.project),
    );

    if (standalone.length > 0) {
        lines.push('', 'PROJECTS');
        standalone.forEach((item) => lines.push('', ...projectBlock(item, '')));
    }

    const credentialSection = (title: string, credentials: Credential[]) => {
        if (credentials.length === 0) {
            return;
        }

        lines.push('', title);
        credentials.forEach((credential) => {
            const period = formatPeriod(
                credential.start_date,
                credential.end_date,
            );
            lines.push(
                `${credentialHeading(credential)}${period ? ` (${period})` : ''}`,
            );
        });
    };

    credentialSection(
        'EDUCATION',
        resolvedCredentials(document.education, index),
    );
    credentialSection(
        'CERTIFICATIONS',
        resolvedCredentials(document.certifications, index),
    );

    const languages = resolvedLanguages(document, index);

    if (languages.length > 0) {
        lines.push(
            '',
            'LANGUAGES',
            languages.map((l) => `${l.language} (${l.proficiency})`).join(', '),
        );
    }

    return lines
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}
