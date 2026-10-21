import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, Copy, Lock, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import { NativeSelect } from '@/components/native-select';
import {
    AddJobDialog,
    AddProjectDialog,
} from '@/components/resume/add-dialogs';
import { BulletPicker } from '@/components/resume/bullet-picker';
import {
    ResumeEditorOptions,
    useResumeEditorOptions,
} from '@/components/resume/editor-options';
import {
    CredentialSection,
    PickListSection,
    SkillsSection,
} from '@/components/resume/extra-sections';
import { ResumePreview } from '@/components/resume/resume-preview';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    formatPeriod,
    indexLibrary,
    moveItem,
    pruneMissing,
    resumeToPlainText,
    wordCount,
} from '@/lib/resume-document';
import type {
    Claim,
    Contact,
    Employment,
    Project,
    ResumeBullet,
    ResumeDocument,
    ResumeJob,
    ResumeLibrary,
    ResumeProjectItem,
    ResumeUsage,
} from '@/types';

export type ResumeEditorProps = {
    initialDocument: ResumeDocument;
    library: ResumeLibrary;
    contact: Contact;
    /** Which other resumes use each library item ("also in React focused"). */
    usedElsewhere: ResumeUsage;
    /** Left side of the sticky toolbar, e.g. the resume name. */
    toolbarStart: ReactNode;
    /** Extra toolbar buttons (history, approve…); receives whether there are unsaved changes. */
    toolbarEnd?: (dirty: boolean) => ReactNode;
    /** Shown when there are no unsaved changes, e.g. "Saved · revision 4". */
    statusText: string;
    /** Changes held outside the document (e.g. the resume name) that also need saving. */
    extraDirty?: boolean;
    /** Persist the (pruned) document; call `done` when the request finishes. */
    onSave: (document: ResumeDocument, done: () => void) => void;
    /** Endpoint for "Apply everywhere" on a reworded bullet, or null to hide it. */
    applyBulletUrl: ((claimUlid: string) => string) | null;
    canEditDisplayTitles: boolean;
};

/**
 * The resume editor shared by resume variants (Profile) and tailored application resumes: the
 * document is edited in the page and saved as a whole; new jobs, projects and bullets are
 * written to the library through dialogs.
 */
export function ResumeEditor({
    initialDocument,
    library: initialLibrary,
    contact,
    usedElsewhere,
    toolbarStart,
    toolbarEnd,
    statusText,
    extraDirty = false,
    onSave,
    applyBulletUrl,
    canEditDisplayTitles,
}: ResumeEditorProps) {
    const [document, setDocument] = useState<ResumeDocument>(initialDocument);
    const [library, setLibrary] = useState<ResumeLibrary>(initialLibrary);
    const [dirty, setDirty] = useState(false);
    const [view, setView] = useState<'edit' | 'preview'>('edit');
    const [copied, setCopied] = useState(false);
    const saving = useRef(false);
    const errors = usePage().props.errors as Record<string, string>;
    const libraryIndex = useMemo(() => indexLibrary(library), [library]);
    const isDirty = dirty || extraDirty;
    const options = useMemo(
        () => ({ applyBulletUrl, canEditDisplayTitles }),
        [applyBulletUrl, canEditDisplayTitles],
    );

    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        const removeRouterGuard = router.on('before', (event) => {
            if (
                !saving.current &&
                !window.confirm('Leave without saving your resume changes?')
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            removeRouterGuard();
        };
    }, [isDirty]);

    const change = (next: ResumeDocument) => {
        setDocument(next);
        setDirty(true);
    };

    const updateJob = (index: number, job: ResumeJob) =>
        change({
            ...document,
            experience: document.experience.map((j, i) =>
                i === index ? job : j,
            ),
        });

    const updateStandalone = (items: ResumeProjectItem[]) =>
        change({ ...document, projects: items });

    const save = () => {
        saving.current = true;
        onSave(pruneMissing(document, libraryIndex), () => {
            saving.current = false;
        });
    };

    const copyText = async () => {
        await navigator.clipboard.writeText(
            resumeToPlainText(document, contact, libraryIndex),
        );
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    /* Library updates after a dialog created something; the new item is also added to the resume. */

    const addEmployment = (employment: Employment) => {
        setLibrary((current) => ({
            ...current,
            employments: [employment, ...current.employments],
        }));
        change({
            ...document,
            experience: [
                ...document.experience,
                {
                    employment: employment.ulid,
                    display_title: null,
                    description: null,
                    bullets: [],
                    projects: [],
                },
            ],
        });
    };

    const addProject = (project: Project) => {
        const item: ResumeProjectItem = {
            project: project.ulid,
            description: null,
            bullets: [],
        };

        if (project.employment === null) {
            setLibrary((current) => ({
                ...current,
                standaloneProjects: [project, ...current.standaloneProjects],
            }));
            change({ ...document, projects: [...document.projects, item] });

            return;
        }

        setLibrary((current) => ({
            ...current,
            employments: current.employments.map((e) =>
                e.ulid === project.employment
                    ? { ...e, projects: [project, ...(e.projects ?? [])] }
                    : e,
            ),
        }));
        change({
            ...document,
            experience: document.experience.map((job) =>
                job.employment === project.employment
                    ? { ...job, projects: [...job.projects, item] }
                    : job,
            ),
        });
    };

    const addClaims = (subject: string, claims: Claim[]) => {
        const [type, ulid] = subject.split(':');
        const withClaims = <T extends { ulid: string; claims?: Claim[] }>(
            record: T,
        ): T =>
            record.ulid === ulid
                ? { ...record, claims: [...(record.claims ?? []), ...claims] }
                : record;
        const newBullets: ResumeBullet[] = claims.map((claim) => ({
            claim: claim.ulid,
            text: null,
        }));
        const withBullets = <T extends { bullets: ResumeBullet[] }>(
            item: T,
            itemUlid: string,
        ): T =>
            itemUlid === ulid
                ? { ...item, bullets: [...item.bullets, ...newBullets] }
                : item;

        setLibrary((current) => ({
            ...current,
            employments: current.employments.map((e) =>
                type === 'employment'
                    ? withClaims(e)
                    : { ...e, projects: (e.projects ?? []).map(withClaims) },
            ),
            standaloneProjects: current.standaloneProjects.map(withClaims),
        }));

        change({
            ...document,
            experience: document.experience.map((job) =>
                type === 'employment'
                    ? withBullets(job, job.employment)
                    : {
                          ...job,
                          projects: job.projects.map((p) =>
                              withBullets(p, p.project),
                          ),
                      },
            ),
            projects: document.projects.map((p) => withBullets(p, p.project)),
        });
    };

    const updateClaim = (claim: Claim) => {
        const replace = (claims?: Claim[]) =>
            (claims ?? []).map((c) => (c.ulid === claim.ulid ? claim : c));

        setLibrary((current) => ({
            ...current,
            employments: current.employments.map((e) => ({
                ...e,
                claims: replace(e.claims),
                projects: (e.projects ?? []).map((p) => ({
                    ...p,
                    claims: replace(p.claims),
                })),
            })),
            standaloneProjects: current.standaloneProjects.map((p) => ({
                ...p,
                claims: replace(p.claims),
            })),
        }));
    };

    const jobsNotOnResume = library.employments.filter(
        (employment) =>
            !document.experience.some(
                (job) => job.employment === employment.ulid,
            ),
    );
    const errorMessages = Object.values(errors ?? {});

    return (
        <ResumeEditorOptions value={options}>
            <div className="flex flex-col gap-4 p-4">
                <div className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-b bg-background/95 px-4 py-2 backdrop-blur">
                    {toolbarStart}
                    <span
                        className="text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        {isDirty ? 'Unsaved changes' : statusText}
                    </span>
                    <div className="ml-auto flex flex-wrap gap-2">
                        <div className="flex rounded-md border lg:hidden">
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    view === 'edit' ? 'secondary' : 'ghost'
                                }
                                onClick={() => setView('edit')}
                            >
                                Edit
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={
                                    view === 'preview' ? 'secondary' : 'ghost'
                                }
                                onClick={() => setView('preview')}
                            >
                                Preview
                            </Button>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={copyText}
                        >
                            {copied ? <Check /> : <Copy />}{' '}
                            {copied ? 'Copied' : 'Copy as plain text'}
                        </Button>
                        {toolbarEnd?.(isDirty)}
                        <Button
                            type="button"
                            size="sm"
                            onClick={save}
                            disabled={!isDirty}
                        >
                            Save
                        </Button>
                    </div>
                </div>

                {errorMessages.length > 0 && (
                    <div
                        role="alert"
                        className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                    >
                        <p className="font-medium">The resume was not saved:</p>
                        <ul className="list-disc pl-5">
                            {[...new Set(errorMessages)].map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div
                        className={
                            view === 'preview'
                                ? 'hidden space-y-6 lg:block'
                                : 'space-y-6'
                        }
                    >
                        <section className="space-y-4 rounded-lg border p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="resume_title">Title</Label>
                                <Input
                                    id="resume_title"
                                    value={document.title ?? ''}
                                    onChange={(event) =>
                                        change({
                                            ...document,
                                            title: event.target.value || null,
                                        })
                                    }
                                    placeholder="e.g. Senior Software Engineer"
                                    maxLength={255}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="resume_summary">Summary</Label>
                                <Textarea
                                    id="resume_summary"
                                    rows={4}
                                    value={document.summary ?? ''}
                                    onChange={(event) =>
                                        change({
                                            ...document,
                                            summary: event.target.value || null,
                                        })
                                    }
                                    placeholder="2–4 sentences on what you do and what you are best at, for this focus."
                                    maxLength={2000}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {wordCount(document.summary)} words
                                </p>
                            </div>
                        </section>

                        <SkillsSection
                            groups={document.skills}
                            skills={library.skills}
                            onChange={(skills) =>
                                change({ ...document, skills })
                            }
                            onSkillsCreated={(created) =>
                                setLibrary((current) => ({
                                    ...current,
                                    skills: [
                                        ...current.skills,
                                        ...created.filter(
                                            (skill) =>
                                                !current.skills.some(
                                                    (s) =>
                                                        s.ulid === skill.ulid,
                                                ),
                                        ),
                                    ].sort((a, b) =>
                                        a.name.localeCompare(b.name),
                                    ),
                                }))
                            }
                        />

                        <section className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="type-card-title text-text-primary">
                                    Experience
                                </h2>
                                <AddJobDialog onAdded={addEmployment} />
                            </div>

                            {jobsNotOnResume.length > 0 && (
                                <IncludeFromLibrary
                                    jobs={jobsNotOnResume}
                                    usedElsewhere={usedElsewhere.employments}
                                    onInclude={(ulid) =>
                                        change({
                                            ...document,
                                            experience: [
                                                ...document.experience,
                                                {
                                                    employment: ulid,
                                                    display_title: null,
                                                    description: null,
                                                    bullets: [],
                                                    projects: [],
                                                },
                                            ],
                                        })
                                    }
                                />
                            )}

                            {document.experience.length === 0 && (
                                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    No experience on this resume yet. Add an
                                    experience, or include one from your
                                    library.
                                </p>
                            )}

                            {document.experience.map((job, index) => (
                                <JobCard
                                    key={job.employment}
                                    job={job}
                                    employment={libraryIndex.employments.get(
                                        job.employment,
                                    )}
                                    isFirst={index === 0}
                                    isLast={
                                        index === document.experience.length - 1
                                    }
                                    onChange={(next) => updateJob(index, next)}
                                    onMove={(delta) =>
                                        change({
                                            ...document,
                                            experience: moveItem(
                                                document.experience,
                                                index,
                                                delta,
                                            ),
                                        })
                                    }
                                    onRemove={() =>
                                        change({
                                            ...document,
                                            experience:
                                                document.experience.filter(
                                                    (_, i) => i !== index,
                                                ),
                                        })
                                    }
                                    onProjectAdded={addProject}
                                    onClaimsAdded={addClaims}
                                    onClaimUpdated={updateClaim}
                                    usedElsewhere={usedElsewhere}
                                />
                            ))}
                        </section>

                        <section className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 className="type-card-title text-text-primary">
                                        Other projects
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Freelance, open-source and side projects
                                        not tied to an experience.
                                    </p>
                                </div>
                                <AddProjectDialog
                                    employment={null}
                                    onAdded={addProject}
                                />
                            </div>
                            <ProjectList
                                projects={library.standaloneProjects}
                                items={document.projects}
                                onChange={updateStandalone}
                                onClaimsAdded={addClaims}
                                onClaimUpdated={updateClaim}
                                usedElsewhere={usedElsewhere}
                            />
                        </section>

                        <CredentialSection
                            title="Education"
                            type="education"
                            credentials={library.education}
                            items={document.education}
                            onChange={(education) =>
                                change({ ...document, education })
                            }
                            onCreated={(credential) =>
                                setLibrary((current) => ({
                                    ...current,
                                    education: [
                                        credential,
                                        ...current.education,
                                    ],
                                }))
                            }
                            usedElsewhere={usedElsewhere.credentials}
                        />

                        <CredentialSection
                            title="Certifications"
                            type="certification"
                            credentials={library.certifications}
                            items={document.certifications}
                            onChange={(certifications) =>
                                change({ ...document, certifications })
                            }
                            onCreated={(credential) =>
                                setLibrary((current) => ({
                                    ...current,
                                    certifications: [
                                        credential,
                                        ...current.certifications,
                                    ],
                                }))
                            }
                            usedElsewhere={usedElsewhere.credentials}
                        />

                        <PickListSection
                            title="Languages"
                            options={library.languages.map((language) => ({
                                ulid: language.ulid,
                                label: `${language.language} (${language.proficiency})`,
                            }))}
                            selected={document.languages}
                            onChange={(languages) =>
                                change({ ...document, languages })
                            }
                            emptyHint="Add the languages you speak in your contact details first."
                        />

                        <PickListSection
                            title="Links"
                            options={library.links.map((link) => ({
                                ulid: link.ulid,
                                label: `${link.label} — ${link.url}`,
                            }))}
                            selected={document.links}
                            onChange={(links) => change({ ...document, links })}
                            emptyHint="Add your portfolio, GitHub or LinkedIn in your contact details first."
                        />
                    </div>

                    <div className={view === 'edit' ? 'hidden lg:block' : ''}>
                        <div className="lg:sticky lg:top-16">
                            <ResumePreview
                                document={document}
                                contact={contact}
                                index={libraryIndex}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </ResumeEditorOptions>
    );
}

function JobCard({
    job,
    employment,
    isFirst,
    isLast,
    onChange,
    onMove,
    onRemove,
    onProjectAdded,
    onClaimsAdded,
    onClaimUpdated,
    usedElsewhere,
}: {
    job: ResumeJob;
    employment: Employment | undefined;
    isFirst: boolean;
    isLast: boolean;
    onChange: (job: ResumeJob) => void;
    onMove: (delta: number) => void;
    onRemove: () => void;
    onProjectAdded: (project: Project) => void;
    onClaimsAdded: (subject: string, claims: Claim[]) => void;
    onClaimUpdated: (claim: Claim) => void;
    usedElsewhere: ResumeUsage;
}) {
    const { canEditDisplayTitles } = useResumeEditorOptions();
    const controls = (
        <div className="flex shrink-0 items-center">
            <Button
                type="button"
                size="icon"
                variant="ghost"
                disabled={isFirst}
                onClick={() => onMove(-1)}
                aria-label="Move experience up"
            >
                <ArrowUp />
            </Button>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                disabled={isLast}
                onClick={() => onMove(1)}
                aria-label="Move experience down"
            >
                <ArrowDown />
            </Button>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                onClick={onRemove}
                aria-label="Remove experience from this resume"
            >
                <Trash2 />
            </Button>
        </div>
    );

    if (!employment) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                This experience was removed from your library. Remove it from
                the resume to save.
                {controls}
            </div>
        );
    }

    return (
        <article className="space-y-4 rounded-lg border p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        {job.display_title ?? employment.title} ·{' '}
                        {employment.employer_name}
                        {employment.status !== 'verified' && (
                            <KnowledgeStatusBadge status={employment.status} />
                        )}
                    </p>
                    <p className="flex items-center gap-1 text-sm text-muted-foreground">
                        <Lock className="size-3" aria-hidden />
                        {formatPeriod(
                            employment.start_date,
                            employment.end_date,
                        )}
                        {employment.location && ` · ${employment.location}`}
                        <span className="sr-only">
                            (dates and employer come from your library)
                        </span>
                    </p>
                </div>
                {controls}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`title_${job.employment}`}>Shown as</Label>
                    <Input
                        id={`title_${job.employment}`}
                        value={job.display_title ?? ''}
                        onChange={(event) =>
                            onChange({
                                ...job,
                                display_title: event.target.value || null,
                            })
                        }
                        placeholder={employment.title}
                        maxLength={255}
                        disabled={!canEditDisplayTitles}
                        aria-describedby={`title_hint_${job.employment}`}
                    />
                    <p
                        id={`title_hint_${job.employment}`}
                        className="text-xs text-muted-foreground"
                    >
                        Official title: {employment.title}
                        {!canEditDisplayTitles &&
                            ' · only the candidate changes this'}
                    </p>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`desc_${job.employment}`}>
                        Short description
                    </Label>
                    <Textarea
                        id={`desc_${job.employment}`}
                        rows={2}
                        value={job.description ?? ''}
                        onChange={(event) =>
                            onChange({
                                ...job,
                                description: event.target.value || null,
                            })
                        }
                        placeholder={
                            employment.summary ??
                            '1–2 sentences. Put achievements in bullet points.'
                        }
                        maxLength={1000}
                    />
                </div>
            </div>

            <BulletPicker
                subject={`employment:${employment.ulid}`}
                claims={employment.claims ?? []}
                bullets={job.bullets}
                onChange={(bullets) => onChange({ ...job, bullets })}
                onClaimsAdded={(claims) =>
                    onClaimsAdded(`employment:${employment.ulid}`, claims)
                }
                onClaimUpdated={onClaimUpdated}
                usedElsewhere={usedElsewhere.claims}
                canEdit
            />

            <div className="space-y-2 border-l-2 pl-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Projects at {employment.employer_name}
                    </p>
                    <AddProjectDialog
                        employment={employment.ulid}
                        onAdded={onProjectAdded}
                    />
                </div>
                <ProjectList
                    projects={employment.projects ?? []}
                    items={job.projects}
                    onChange={(projects) => onChange({ ...job, projects })}
                    onClaimsAdded={onClaimsAdded}
                    onClaimUpdated={onClaimUpdated}
                    usedElsewhere={usedElsewhere}
                />
            </div>
        </article>
    );
}

/**
 * Projects available for one place in the resume (a job, or "Other projects"): included ones
 * in resume order with their own bullets, then the rest with an include checkbox.
 */
function ProjectList({
    projects,
    items,
    onChange,
    onClaimsAdded,
    onClaimUpdated,
    usedElsewhere,
}: {
    projects: Project[];
    items: ResumeProjectItem[];
    onChange: (items: ResumeProjectItem[]) => void;
    onClaimsAdded: (subject: string, claims: Claim[]) => void;
    onClaimUpdated: (claim: Claim) => void;
    usedElsewhere: ResumeUsage;
}) {
    const byUlid = new Map(projects.map((project) => [project.ulid, project]));
    const included = items.filter((item) => byUlid.has(item.project));
    const includedUlids = new Set(included.map((item) => item.project));
    const others = projects.filter(
        (project) => !includedUlids.has(project.ulid),
    );

    if (projects.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">No projects yet.</p>
        );
    }

    return (
        <ul className="space-y-2">
            {included.map((item, index) => {
                const project = byUlid.get(item.project)!;
                const update = (next: ResumeProjectItem) =>
                    onChange(included.map((p, i) => (i === index ? next : p)));

                return (
                    <li
                        key={item.project}
                        className="space-y-3 rounded-md bg-muted/40 p-3"
                    >
                        <div className="flex items-start gap-2">
                            <Checkbox
                                className="mt-1"
                                checked
                                onCheckedChange={() =>
                                    onChange(
                                        included.filter((_, i) => i !== index),
                                    )
                                }
                                aria-label={`Leave out project ${project.name}`}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    {project.name}
                                    {project.role && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            — {project.role}
                                        </span>
                                    )}
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                disabled={index === 0}
                                onClick={() =>
                                    onChange(moveItem(included, index, -1))
                                }
                                aria-label="Move project up"
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                disabled={index === included.length - 1}
                                onClick={() =>
                                    onChange(moveItem(included, index, 1))
                                }
                                aria-label="Move project down"
                            >
                                <ArrowDown />
                            </Button>
                        </div>
                        <Textarea
                            rows={2}
                            value={item.description ?? ''}
                            onChange={(event) =>
                                update({
                                    ...item,
                                    description: event.target.value || null,
                                })
                            }
                            placeholder={
                                project.summary ??
                                'Short description for this resume (optional).'
                            }
                            aria-label={`Short description of ${project.name}`}
                            maxLength={1000}
                        />
                        <BulletPicker
                            subject={`project:${project.ulid}`}
                            claims={project.claims ?? []}
                            bullets={item.bullets}
                            onChange={(bullets) => update({ ...item, bullets })}
                            onClaimsAdded={(claims) =>
                                onClaimsAdded(`project:${project.ulid}`, claims)
                            }
                            onClaimUpdated={onClaimUpdated}
                            usedElsewhere={usedElsewhere.claims}
                            canEdit
                        />
                    </li>
                );
            })}
            {others.map((project) => (
                <li
                    key={project.ulid}
                    className="flex items-start gap-2 px-1 text-sm text-muted-foreground"
                >
                    <Checkbox
                        className="mt-0.5"
                        checked={false}
                        onCheckedChange={() =>
                            onChange([
                                ...included,
                                {
                                    project: project.ulid,
                                    description: null,
                                    bullets: [],
                                },
                            ])
                        }
                        aria-label={`Include project ${project.name}`}
                    />
                    <span>
                        {project.name}
                        {usedElsewhere.projects[project.ulid] && (
                            <span className="ml-2 text-xs">
                                (in{' '}
                                {usedElsewhere.projects[project.ulid].join(
                                    ', ',
                                )}
                                )
                            </span>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function IncludeFromLibrary({
    jobs,
    usedElsewhere,
    onInclude,
}: {
    jobs: Employment[];
    usedElsewhere: Record<string, string[]>;
    onInclude: (ulid: string) => void;
}) {
    const [selected, setSelected] = useState('');

    return (
        <div className="flex flex-wrap items-end gap-2 rounded-lg bg-muted/40 p-3">
            <div className="grid min-w-64 flex-1 gap-2">
                <Label htmlFor="include_job">
                    Include an experience from your library
                </Label>
                <NativeSelect
                    id="include_job"
                    value={selected}
                    onChange={(event) => setSelected(event.target.value)}
                    placeholder="Choose an experience…"
                    options={jobs.map((job) => ({
                        value: job.ulid,
                        label: `${job.title} — ${job.employer_name} (${formatPeriod(job.start_date, job.end_date)})${
                            usedElsewhere[job.ulid]
                                ? ` · in ${usedElsewhere[job.ulid].join(', ')}`
                                : ''
                        }`,
                    }))}
                />
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={selected === ''}
                onClick={() => {
                    onInclude(selected);
                    setSelected('');
                }}
            >
                Include
            </Button>
        </div>
    );
}
