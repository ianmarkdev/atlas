import {
    bulletText,
    credentialHeading,
    formatPeriod,
    resolvedCredentials,
    resolvedLanguages,
    resolvedLinks,
    resolvedSkillGroups,
} from '@/lib/resume-document';
import type { LibraryIndex } from '@/lib/resume-document';
import type {
    Contact,
    Credential,
    ResumeBullet,
    ResumeDocument,
    ResumeProjectItem,
} from '@/types';

/**
 * How the resume reads, rebuilt from the document and the library on every change.
 */
export function ResumePreview({
    document,
    contact,
    index,
}: {
    document: ResumeDocument;
    contact: Contact;
    index: LibraryIndex;
}) {
    const contactLine = [contact.email, contact.phone, contact.location]
        .filter(Boolean)
        .join(' · ');
    const links = resolvedLinks(document, index);
    const skillGroups = resolvedSkillGroups(document, index);
    const jobs = document.experience.filter((job) =>
        index.employments.has(job.employment),
    );
    const standalone = document.projects.filter((item) =>
        index.projects.has(item.project),
    );
    const education = resolvedCredentials(document.education, index);
    const certifications = resolvedCredentials(document.certifications, index);
    const languages = resolvedLanguages(document, index);
    const isEmpty =
        jobs.length === 0 &&
        standalone.length === 0 &&
        skillGroups.length === 0 &&
        education.length === 0 &&
        certifications.length === 0;

    return (
        <article className="space-y-5 rounded-lg border bg-background p-6 text-sm leading-relaxed shadow-xs">
            <header>
                <h2 className="text-xl font-semibold">
                    {contact.display_name}
                </h2>
                {document.title && (
                    <p className="text-base">{document.title}</p>
                )}
                {contactLine && (
                    <p className="text-muted-foreground">{contactLine}</p>
                )}
                {links.length > 0 && (
                    <p className="flex flex-wrap gap-x-3 text-muted-foreground">
                        {links.map((link) => (
                            <a
                                key={link.ulid}
                                href={link.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline underline-offset-2"
                            >
                                {link.label}
                            </a>
                        ))}
                    </p>
                )}
            </header>

            {document.summary && (
                <p className="whitespace-pre-line">{document.summary}</p>
            )}

            {skillGroups.length > 0 && (
                <Section title="Skills">
                    <ul className="space-y-0.5">
                        {skillGroups.map((group, i) => (
                            <li key={i}>
                                {group.label && (
                                    <span className="font-medium">
                                        {group.label}:{' '}
                                    </span>
                                )}
                                {group.names.join(', ')}
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {jobs.length > 0 && (
                <Section title="Experience">
                    {jobs.map((job) => {
                        const employment = index.employments.get(
                            job.employment,
                        )!;

                        return (
                            <div key={job.employment} className="space-y-1">
                                <div className="flex flex-wrap justify-between gap-x-4">
                                    <p className="font-medium">
                                        {job.display_title ?? employment.title}{' '}
                                        — {employment.employer_name}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {formatPeriod(
                                            employment.start_date,
                                            employment.end_date,
                                        )}
                                    </p>
                                </div>
                                {job.description && (
                                    <p className="whitespace-pre-line">
                                        {job.description}
                                    </p>
                                )}
                                <Bullets bullets={job.bullets} index={index} />
                                {job.projects.map((item) => (
                                    <ProjectPreview
                                        key={item.project}
                                        item={item}
                                        index={index}
                                        nested
                                    />
                                ))}
                            </div>
                        );
                    })}
                </Section>
            )}

            {standalone.length > 0 && (
                <Section title="Projects">
                    {standalone.map((item) => (
                        <ProjectPreview
                            key={item.project}
                            item={item}
                            index={index}
                        />
                    ))}
                </Section>
            )}

            {education.length > 0 && (
                <Section title="Education">
                    <CredentialLines credentials={education} />
                </Section>
            )}

            {certifications.length > 0 && (
                <Section title="Certifications">
                    <CredentialLines credentials={certifications} />
                </Section>
            )}

            {languages.length > 0 && (
                <Section title="Languages">
                    <p>
                        {languages
                            .map((l) => `${l.language} (${l.proficiency})`)
                            .join(', ')}
                    </p>
                </Section>
            )}

            {isEmpty && (
                <p className="text-muted-foreground">
                    Add experience, projects, skills and education on the left
                    to see them here.
                </p>
            )}
        </article>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <h3 className="border-b pb-1 text-xs font-semibold tracking-widest uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

function CredentialLines({ credentials }: { credentials: Credential[] }) {
    return (
        <ul className="space-y-1">
            {credentials.map((credential) => (
                <li
                    key={credential.ulid}
                    className="flex flex-wrap justify-between gap-x-4"
                >
                    <span>{credentialHeading(credential)}</span>
                    <span className="text-muted-foreground">
                        {formatPeriod(
                            credential.start_date,
                            credential.end_date,
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function ProjectPreview({
    item,
    index,
    nested = false,
}: {
    item: ResumeProjectItem;
    index: LibraryIndex;
    nested?: boolean;
}) {
    const project = index.projects.get(item.project);

    if (!project) {
        return null;
    }

    return (
        <div className={nested ? 'mt-2 space-y-1 pl-4' : 'space-y-1'}>
            <p className="font-medium">
                {project.name}
                {project.role && (
                    <span className="font-normal"> — {project.role}</span>
                )}
            </p>
            {item.description && (
                <p className="whitespace-pre-line">{item.description}</p>
            )}
            <Bullets bullets={item.bullets} index={index} />
        </div>
    );
}

function Bullets({
    bullets,
    index,
}: {
    bullets: ResumeBullet[];
    index: LibraryIndex;
}) {
    const texts = bullets
        .map((bullet) => bulletText(bullet, index))
        .filter(Boolean);

    if (texts.length === 0) {
        return null;
    }

    return (
        <ul className="list-disc space-y-0.5 pl-5">
            {texts.map((text, i) => (
                <li key={i}>{text}</li>
            ))}
        </ul>
    );
}
