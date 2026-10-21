import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Copy,
    History,
    RotateCcw,
    Undo2,
    Upload,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import ApplicationResumeController from '@/actions/App/Http/Controllers/Applications/ApplicationResumeController';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import { JobDescriptionSheet } from '@/components/applications/job-description-sheet';
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
        company_name: string;
        role_title: string;
        job_description: string | null;
    };
    resume: TailoredResume;
    contact: Contact;
    library: ResumeLibrary;
    usedElsewhere: ResumeUsage;
    versions: Version[];
    variants: SelectOption[];
    can: {
        update: boolean;
        approve: boolean;
        reopen: boolean;
        saveBack: boolean;
        editDisplayTitles: boolean;
    };
};

export default function ApplicationResumePage(props: PageProps) {
    const { application, resume } = props;

    return (
        <>
            <Head
                title={`Resume · ${application.role_title} at ${application.company_name}`}
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
    can,
}: PageProps) {
    const backLink = (
        <Link
            href={JobApplicationController.show(application.ulid)}
            className="inline-flex min-w-0 items-center gap-1.5 text-sm text-text-secondary focus-ring hover:text-text-primary"
        >
            <ArrowLeft className="size-4 shrink-0" aria-hidden />
            <span className="truncate">
                {application.role_title} · {application.company_name}
            </span>
        </Link>
    );

    const sharedTools = (
        <>
            <JobDescriptionSheet text={application.job_description} />
            <VersionsSheet versions={versions} />
            {can.saveBack && resume.based_on && (
                <SaveBackButton
                    basedOn={resume.based_on.name}
                    application={application.ulid}
                />
            )}
        </>
    );

    if (!can.update) {
        return (
            <ReadOnlyResume
                backLink={backLink}
                tools={sharedTools}
                application={application}
                resume={resume}
                contact={contact}
                library={library}
                canReopen={can.reopen}
            />
        );
    }

    const save = (document: ResumeDocument, done: () => void) =>
        router.put(
            ApplicationResumeController.update.url(application.ulid),
            { document, lock_version: resume.lock_version },
            { preserveScroll: true, onFinish: done },
        );

    return (
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
}: {
    backLink: ReactNode;
    tools: ReactNode;
    application: PageProps['application'];
    resume: TailoredResume;
    contact: Contact;
    library: ResumeLibrary;
    canReopen: boolean;
}) {
    const [copied, setCopied] = useState(false);
    const index = indexLibrary(library);
    const errors = usePage().props.errors as Record<string, string>;
    const approved = resume.status === 'approved';

    const copyText = async () => {
        await navigator.clipboard.writeText(
            resumeToPlainText(resume.document, contact, index),
        );
        setCopied(true);
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

ApplicationResumePage.layout = {
    breadcrumbs: [
        { title: 'Applications', href: applicationsIndex() },
        { title: 'Tailored resume', href: applicationsIndex() },
    ],
};
