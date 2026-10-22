import { Form, Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Copy,
    GitCompare,
    History,
    ListChecks,
    Loader2,
    RotateCcw,
    Sparkles,
    Undo2,
    Upload,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import ApplicationResumeController from '@/actions/App/Http/Controllers/Applications/ApplicationResumeController';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import { JobDescriptionSheet } from '@/components/applications/job-description-sheet';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { ResumeEditor } from '@/components/resume/resume-editor';
import { ResumePreview } from '@/components/resume/resume-preview';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { indexLibrary, resumeToPlainText } from '@/lib/resume-document';
import { companyLabel } from '@/lib/applications';
import { copyToClipboard } from '@/lib/clipboard';
import { index as applicationsIndex } from '@/routes/applications';
import type {
    Contact,
    ResumeDocument,
    ResumeLibrary,
    ResumeUsage,
    SelectOption,
} from '@/types';

type TailoredResume = {
    ulid: string;
    status: 'draft' | 'approved';
    lock_version: number;
    version_no: number | null;
    document: ResumeDocument;
    based_on: { ulid: string; name: string } | null;
    approved_at: string | null;
    approved_by: string | null;
    generation: {
        status: 'queued' | 'running' | 'failed';
        error: string | null;
        requested_at: string | null;
    } | null;
    ai_report: AiReport | null;
};

type AiReport = {
    refused: {
        item: string;
        what: string;
        reason: string;
        kept: string | null;
    }[];
    notes: string[];
};

const refusalReasons: Record<string, string> = {
    not_item_bullet: 'not a bullet point of this item',
    no_bullets_left: 'the AI left no bullet points; the current ones were kept',
    job_kept:
        'the AI wanted to remove this job; jobs stay, so the resume has no gap',
    adds_number: 'the rewording adds a number that is not in the bullet point',
    title_candidate_only: 'only the candidate changes how a job title is shown',
    title_not_in_use: 'not a title you already use for this job',
    summary_number: 'the summary mentions a number that is not on the resume',
};

type Version = {
    id: number;
    version_no: number;
    created_at: string;
    author: string | null;
    created_via: string;
    is_approved: boolean;
};

type PageProps = {
    application: {
        ulid: string;
        company_name: string | null;
        role_title: string;
        job_description: string | null;
    };
    resume: TailoredResume;
    contact: Contact;
    library: ResumeLibrary;
    usedElsewhere: ResumeUsage;
    versions: Version[];
    variants: SelectOption[];
    changes: ResumeChange[] | null;
    baseRevisionNo: number | null;
    hasAnalysis: boolean;
    can: {
        update: boolean;
        approve: boolean;
        reopen: boolean;
        saveBack: boolean;
        headlineToVariant: boolean;
        editDisplayTitles: boolean;
    };
};

const isTailoring = (resume: TailoredResume): boolean =>
    resume.generation?.status === 'queued' ||
    resume.generation?.status === 'running';

export default function ApplicationResumePage(props: PageProps) {
    const { application, resume } = props;
    const tailoring = isTailoring(resume);
    const poll = usePoll(3000, {}, { autoStart: false });

    useEffect(() => {
        if (tailoring) {
            poll.start();
        } else {
            poll.stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tailoring]);

    return (
        <>
            <Head
                title={`Resume · ${application.role_title} at ${companyLabel(application.company_name)}`}
            />
            {/* Remount after every save or status change, so the editor starts from the server state. */}
            <TailoredResume
                key={`${resume.ulid}:${resume.version_no}:${resume.status}`}
                {...props}
            />
        </>
    );
}

function TailoredResume({
    application,
    resume,
    contact,
    library,
    usedElsewhere,
    versions,
    variants,
    changes,
    baseRevisionNo,
    hasAnalysis,
    can,
}: PageProps) {
    const tailoring = isTailoring(resume);
    const backLink = (
        <Link
            href={JobApplicationController.show(application.ulid)}
            className="inline-flex min-w-0 items-center gap-1.5 text-sm text-text-secondary focus-ring hover:text-text-primary"
        >
            <ArrowLeft className="size-4 shrink-0" aria-hidden />
            <span className="truncate">
                {application.role_title} ·{' '}
                {companyLabel(application.company_name)}
            </span>
        </Link>
    );

    const sharedTools = (
        <>
            <JobDescriptionSheet text={application.job_description} />
            <VersionsSheet versions={versions} />
            {changes !== null && resume.based_on && (
                <ChangesSheet
                    changes={changes}
                    basedOn={resume.based_on.name}
                    revisionNo={baseRevisionNo}
                />
            )}
            {resume.ai_report && <AiReportSheet report={resume.ai_report} />}
            {can.saveBack && resume.based_on && !tailoring && (
                <SaveBackButton
                    basedOn={resume.based_on.name}
                    application={application.ulid}
                />
            )}
            {can.headlineToVariant && resume.based_on && !tailoring && (
                <HeadlineButton
                    basedOn={resume.based_on.name}
                    application={application.ulid}
                    isCandidate={can.editDisplayTitles}
                />
            )}
        </>
    );

    if (!can.update || tailoring) {
        return (
            <ReadOnlyResume
                backLink={backLink}
                tools={sharedTools}
                application={application}
                resume={resume}
                contact={contact}
                library={library}
                canReopen={can.reopen}
                notice={
                    tailoring ? (
                        <p
                            className="flex items-center gap-2 rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-3 text-sm text-text-primary"
                            aria-live="polite"
                        >
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden
                            />
                            {resume.generation?.status === 'queued'
                                ? 'Waiting to start… (background jobs need a queue worker: php artisan queue:work)'
                                : 'Tailoring the resume for this job… with a local model this can take a few minutes. This page updates by itself.'}
                        </p>
                    ) : null
                }
            />
        );
    }

    const tailor = (dirty: boolean) => {
        if (
            dirty &&
            !window.confirm(
                'Your unsaved changes will be lost when the tailored version arrives. Continue?',
            )
        ) {
            return;
        }

        router.post(
            ApplicationResumeController.tailor.url(application.ulid),
            {},
            { preserveScroll: true },
        );
    };

    const save = (document: ResumeDocument, done: () => void) =>
        router.put(
            ApplicationResumeController.update.url(application.ulid),
            { document, lock_version: resume.lock_version },
            { preserveScroll: true, onFinish: done },
        );

    return (
        <>
            {(resume.generation?.status === 'failed' || !hasAnalysis) && (
                <div className="px-4 pt-4 lg:px-6">
                    {resume.generation?.status === 'failed' ? (
                        <p
                            role="alert"
                            className="rounded-kraken-sm border border-border-error bg-surface-error-subtle p-3 text-sm text-text-error"
                        >
                            AI tailoring failed: {resume.generation.error}
                        </p>
                    ) : (
                        <p className="rounded-kraken-sm border border-border-subtle bg-surface-tile p-3 type-caption text-text-secondary">
                            Tip: analyse the job description first.{' '}
                            <strong>Tailor with AI</strong> then knows the job’s
                            must-haves and the employer’s own words.
                        </p>
                    )}
                </div>
            )}
            <ResumeEditor
                initialDocument={resume.document}
                library={library}
                contact={contact}
                usedElsewhere={usedElsewhere}
                statusText={`Saved · version ${resume.version_no ?? 0}`}
                onSave={save}
                applyBulletUrl={(claimUlid) =>
                    ApplicationResumeController.applyBullet.url([
                        application.ulid,
                        claimUlid,
                    ])
                }
                newBulletUrl={(claimUlid) =>
                    ApplicationResumeController.newBullet.url([
                        application.ulid,
                        claimUlid,
                    ])
                }
                canEditDisplayTitles={can.editDisplayTitles}
                toolbarStart={
                    <>
                        {backLink}
                        <Badge variant="outline">Draft</Badge>
                        {resume.based_on && (
                            <span className="hidden type-caption text-text-muted xl:inline">
                                from “{resume.based_on.name}”
                            </span>
                        )}
                    </>
                }
                toolbarEnd={(dirty) => (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => tailor(dirty)}
                        >
                            <Sparkles /> Tailor with AI
                        </Button>
                        {sharedTools}
                        <CopyFromSheet
                            application={application.ulid}
                            variants={variants}
                            lockVersion={resume.lock_version}
                            dirty={dirty}
                        />
                        {can.approve && (
                            <Form
                                {...ApplicationResumeController.approve.form(
                                    application.ulid,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={processing || dirty}
                                        title={
                                            dirty
                                                ? 'Save your changes before approving'
                                                : undefined
                                        }
                                    >
                                        <CheckCircle2 /> Approve
                                    </Button>
                                )}
                            </Form>
                        )}
                    </>
                )}
            />
        </>
    );
}

function AiReportSheet({ report }: { report: AiReport }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="ghost">
                    <ListChecks /> AI check
                    {report.refused.length > 0 && (
                        <Badge variant="outline" className="ml-1">
                            {report.refused.length} refused
                        </Badge>
                    )}
                </Button>
            </SheetTrigger>
            <SheetContent className="sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>What the server kept from the AI</SheetTitle>
                    <SheetDescription>
                        The AI may only choose and word your bullet points.
                        Anything that would add a fact was refused, and the
                        earlier wording kept. Compare the full change with
                        “Changes”.
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-6 overflow-y-auto px-4 pb-4">
                    <section className="space-y-2">
                        <h3 className="type-label text-text-label uppercase">
                            Refused
                        </h3>
                        {report.refused.length === 0 ? (
                            <p className="type-body text-text-muted">
                                Nothing: every proposed change passed the check.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {report.refused.map((item, i) => (
                                    <li
                                        key={i}
                                        className="space-y-1 rounded-kraken-sm border border-border-subtle p-3"
                                    >
                                        <p className="type-caption text-text-muted">
                                            {item.item === 'summary'
                                                ? 'Summary'
                                                : item.item}
                                        </p>
                                        {item.what !== '' && (
                                            <p className="text-sm text-text-primary">
                                                {item.reason.startsWith('title')
                                                    ? `Shown as “${item.what}”`
                                                    : item.what}
                                            </p>
                                        )}
                                        <p className="type-caption text-text-secondary">
                                            Refused:{' '}
                                            {refusalReasons[item.reason] ??
                                                item.reason}
                                            {item.kept !== null &&
                                                item.kept !== '' &&
                                                ` — kept “${item.kept}”`}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    {report.notes.length > 0 && (
                        <section className="space-y-2">
                            <h3 className="type-label text-text-label uppercase">
                                Notes from the AI
                            </h3>
                            <ul className="list-disc space-y-1 pl-5 text-sm">
                                {report.notes.map((note, i) => (
                                    <li key={i}>{note}</li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function ReadOnlyResume({
    backLink,
    tools,
    application,
    resume,
    contact,
    library,
    canReopen,
    notice = null,
}: {
    backLink: ReactNode;
    tools: ReactNode;
    application: PageProps['application'];
    resume: TailoredResume;
    contact: Contact;
    library: ResumeLibrary;
    canReopen: boolean;
    notice?: ReactNode;
}) {
    const [copied, setCopied] = useState(false);
    const index = indexLibrary(library);
    const errors = usePage().props.errors as Record<string, string>;
    const approved = resume.status === 'approved';

    const copyText = async () => {
        const isCopied = await copyToClipboard(
            resumeToPlainText(resume.document, contact, index),
        );
        setCopied(isCopied);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="flex flex-col gap-4 p-4 lg:p-6">
            <div className="flex flex-wrap items-center gap-2">
                {backLink}
                {approved ? (
                    <Badge>
                        <CheckCircle2 /> Approved
                    </Badge>
                ) : (
                    <Badge variant="outline">Draft</Badge>
                )}
                {approved && resume.approved_at && (
                    <span className="type-caption text-text-muted">
                        Version {resume.version_no} ·{' '}
                        {new Date(resume.approved_at).toLocaleDateString()}
                        {resume.approved_by && ` by ${resume.approved_by}`}
                    </span>
                )}
                <div className="ml-auto flex flex-wrap gap-2">
                    {tools}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={copyText}
                    >
                        {copied ? <Check /> : <Copy />}{' '}
                        {copied ? 'Copied' : 'Copy as plain text'}
                    </Button>
                    {canReopen && (
                        <Form
                            {...ApplicationResumeController.reopen.form(
                                application.ulid,
                            )}
                        >
                            {({ processing }) => (
                                <Button size="sm" disabled={processing}>
                                    <RotateCcw /> Reopen to edit
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </div>

            {notice}
            {approved && (
                <p className="rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-3 text-sm text-text-primary">
                    Frozen as approved: this is exactly what goes out, even if
                    your library changes later.
                    {!canReopen && ' Only the candidate can reopen it.'}
                </p>
            )}
            {Object.values(errors ?? {}).length > 0 && (
                <p
                    role="alert"
                    className="rounded-kraken-sm border border-border-error bg-surface-error-subtle p-3 text-sm text-text-error"
                >
                    {Object.values(errors).join(' ')}
                </p>
            )}

            <div className="lg:max-w-3xl">
                <ResumePreview
                    document={resume.document}
                    contact={contact}
                    index={index}
                />
            </div>
        </div>
    );
}

function versionOrigin(createdVia: string): string {
    if (createdVia.startsWith('copied:')) {
        return 'Copied from a resume';
    }

    return createdVia.startsWith('ai') ? 'Written by AI' : 'Edited';
}

type ResumeChange = {
    section: string;
    change: 'changed' | 'added' | 'removed' | 'reworded' | 'reordered';
    item: string;
    before: string | null;
    after: string | null;
};

const changeLabels: Record<ResumeChange['change'], string> = {
    changed: 'Changed',
    added: 'Added',
    removed: 'Removed',
    reworded: 'Reworded',
    reordered: 'Reordered',
};

/**
 * What this tailored resume changed compared with the resume it was copied from.
 */
function ChangesSheet({
    changes,
    basedOn,
    revisionNo,
}: {
    changes: ResumeChange[];
    basedOn: string;
    revisionNo: number | null;
}) {
    const sections = [...new Set(changes.map((c) => c.section))];

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <GitCompare /> Changes ({changes.length})
                </Button>
            </SheetTrigger>
            <SheetContent className="sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Changes vs. “{basedOn}”</SheetTitle>
                    <SheetDescription>
                        Compared with revision {revisionNo ?? '?'} of “{basedOn}
                        ”, the version it was copied from. Saved changes only.
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-4 overflow-y-auto px-4 pb-4">
                    {changes.length === 0 ? (
                        <p className="type-body text-text-muted">
                            No changes yet: this is still an exact copy.
                        </p>
                    ) : (
                        sections.map((section) => (
                            <section key={section} className="space-y-2">
                                <h3 className="type-label text-text-label uppercase">
                                    {section}
                                </h3>
                                <ul className="space-y-2">
                                    {changes
                                        .filter((c) => c.section === section)
                                        .map((change, i) => (
                                            <li
                                                key={i}
                                                className="space-y-1 rounded-kraken-sm border border-border-subtle p-2 text-sm"
                                            >
                                                <p className="flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        variant={
                                                            change.change ===
                                                            'removed'
                                                                ? 'destructive'
                                                                : change.change ===
                                                                    'added'
                                                                  ? 'default'
                                                                  : 'outline'
                                                        }
                                                    >
                                                        {
                                                            changeLabels[
                                                                change.change
                                                            ]
                                                        }
                                                    </Badge>
                                                    <span className="text-text-primary">
                                                        {change.item}
                                                    </span>
                                                </p>
                                                {change.before !== null && (
                                                    <p className="text-text-muted line-through">
                                                        {change.before}
                                                    </p>
                                                )}
                                                {change.after !== null && (
                                                    <p className="text-text-secondary">
                                                        {change.after}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                </ul>
                            </section>
                        ))
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function VersionsSheet({ versions }: { versions: Version[] }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <History /> History
                </Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>Versions</SheetTitle>
                    <SheetDescription>
                        Every save is kept, with the facts it showed at the
                        time.
                    </SheetDescription>
                </SheetHeader>
                <ul className="space-y-2 overflow-y-auto px-4 pb-4">
                    {versions.map((version, i) => (
                        <li
                            key={version.id}
                            className="flex items-center justify-between gap-2 rounded-kraken-sm border border-border-subtle p-2 text-sm"
                        >
                            <div>
                                <p className="font-medium">
                                    Version {version.version_no}
                                </p>
                                <p className="type-caption text-text-muted">
                                    {versionOrigin(version.created_via)} ·{' '}
                                    {new Date(
                                        version.created_at,
                                    ).toLocaleString()}
                                    {version.author && ` · ${version.author}`}
                                </p>
                            </div>
                            <div className="flex gap-1">
                                {version.is_approved && <Badge>Approved</Badge>}
                                {i === 0 && (
                                    <Badge variant="secondary">Current</Badge>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </SheetContent>
        </Sheet>
    );
}

function CopyFromSheet({
    application,
    variants,
    lockVersion,
    dirty,
}: {
    application: string;
    variants: SelectOption[];
    lockVersion: number;
    dirty: boolean;
}) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="ghost">
                    <Undo2 /> Start over
                </Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>Start over from a resume</SheetTitle>
                    <SheetDescription>
                        Replaces this tailored resume with a fresh copy. The
                        current version stays in the history.
                        {dirty && ' Your unsaved changes will be lost.'}
                    </SheetDescription>
                </SheetHeader>
                <Form
                    {...ApplicationResumeController.copy.form(application)}
                    className="space-y-3 px-4 pb-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="lock_version"
                                value={lockVersion}
                            />
                            <NativeSelect
                                name="variant"
                                options={variants}
                                aria-label="Resume to copy"
                            />
                            {errors.variant && (
                                <p className="text-sm text-text-error">
                                    {errors.variant}
                                </p>
                            )}
                            <Button disabled={processing}>Copy it</Button>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}

function SaveBackButton({
    basedOn,
    application,
}: {
    basedOn: string;
    application: string;
}) {
    return (
        <Form
            {...ApplicationResumeController.saveBack.form(application)}
            options={{ preserveScroll: true }}
            onBefore={() =>
                window.confirm(
                    `Save this tailored version as the new content of “${basedOn}”? Its current content stays in that resume's history.`,
                )
            }
        >
            {({ processing }) => (
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    title={`Save to “${basedOn}”`}
                >
                    <Upload /> Save to “{basedOn}”
                </Button>
            )}
        </Form>
    );
}

/**
 * Use this resume's title and summary on the resume it came from (M10). A VA's request is
 * suggested to the candidate.
 */
function HeadlineButton({
    basedOn,
    application,
    isCandidate,
}: {
    basedOn: string;
    application: string;
    isCandidate: boolean;
}) {
    return (
        <Form
            {...ApplicationResumeController.headlineToVariant.form(application)}
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                        title={
                            isCandidate
                                ? `Use the saved title and summary on “${basedOn}”; nothing else there changes`
                                : `Suggest the saved title and summary for “${basedOn}” to the candidate`
                        }
                    >
                        <Upload />{' '}
                        {isCandidate
                            ? 'Use summary on the resume'
                            : 'Suggest summary for the resume'}
                    </Button>
                    <InputError message={errors.resume} />
                </>
            )}
        </Form>
    );
}

ApplicationResumePage.layout = {
    breadcrumbs: [
        { title: 'Applications', href: applicationsIndex() },
        { title: 'Tailored resume', href: applicationsIndex() },
    ],
};
