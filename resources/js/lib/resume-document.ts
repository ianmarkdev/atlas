import type {
    Claim,
    Contact,
    Employment,
    Project,
    ResumeBullet,
    ResumeDocument,
    ResumeLibrary,
} from '@/types';

/** Library items by ULID, so the editor and preview can show facts for a document's refs. */
export type LibraryIndex = {
    employments: Map<string, Employment>;
    projects: Map<string, Project>;
    claims: Map<string, Claim>;
};

export function indexLibrary(library: ResumeLibrary): LibraryIndex {
    const index: LibraryIndex = {
        employments: new Map(),
        projects: new Map(),
        claims: new Map(),
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
        return end === null ? '' : `until ${formatMonth(end)}`;
    }

    return `${formatMonth(start)} – ${end === null ? 'Present' : formatMonth(end)}`;
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

    if (document.title) {
        lines.push('', document.title);
    }

    if (document.summary) {
        lines.push('', document.summary);
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

    return lines
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}
