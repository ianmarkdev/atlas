import { bulletText, formatPeriod } from '@/lib/resume-document';
import type { LibraryIndex } from '@/lib/resume-document';
import type {
    Contact,
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
    const jobs = document.experience.filter((job) =>
        index.employments.has(job.employment),
    );
    const standalone = document.projects.filter((item) =>
        index.projects.has(item.project),
    );

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
            </header>

            {document.summary && (
                <p className="whitespace-pre-line">{document.summary}</p>
            )}

            {jobs.length > 0 && (
                <section className="space-y-4">
                    <h3 className="border-b pb-1 text-xs font-semibold tracking-widest uppercase">
                        Experience
                    </h3>
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
                </section>
            )}

            {standalone.length > 0 && (
                <section className="space-y-3">
                    <h3 className="border-b pb-1 text-xs font-semibold tracking-widest uppercase">
                        Projects
                    </h3>
                    {standalone.map((item) => (
                        <ProjectPreview
                            key={item.project}
                            item={item}
                            index={index}
                        />
                    ))}
                </section>
            )}

            {jobs.length === 0 && standalone.length === 0 && (
                <p className="text-muted-foreground">
                    Add experience and projects on the left to see them here.
                </p>
            )}
        </article>
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
