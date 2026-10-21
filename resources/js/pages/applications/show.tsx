import { Form, Head, Link } from '@inertiajs/react';
import {
    Check,
    Copy,
    ExternalLink,
    FileText,
    MessageSquareText,
    Pencil,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import ApplicationResumeController from '@/actions/App/Http/Controllers/Applications/ApplicationResumeController';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import {
    DeadlineBadge,
    StageBadge,
    StageSelect,
} from '@/components/applications/stage';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDay } from '@/lib/applications';
import { timeAgo } from '@/lib/time';
import { index } from '@/routes/applications';
import type { JobApplicationDetail, SelectOption } from '@/types';

type JobDescription = {
    text: string;
    captured_at: string;
    versions: number;
};

export default function ApplicationShow({
    application,
    jobDescription,
    stages,
    employmentTypes,
    tailoredResume,
    variants,
}: {
    application: JobApplicationDetail;
    jobDescription: JobDescription | null;
    stages: SelectOption[];
    employmentTypes: SelectOption[];
    tailoredResume: TailoredResumeSummary | null;
    variants: SelectOption[];
}) {
    const employmentType = employmentTypes.find(
        (option) => option.value === application.employment_type,
    )?.label;

    const facts: [string, string | null][] = [
        ['Location', application.location],
        ['Type', employmentType ?? null],
        ['Salary', application.salary_text],
        [
            'Deadline',
            application.deadline_on ? formatDay(application.deadline_on) : null,
        ],
        [
            'Applied',
            application.applied_at
                ? new Date(application.applied_at).toLocaleDateString()
                : null,
        ],
        ['Added by', application.owner ?? null],
    ];

    return (
        <>
            <Head
                title={`${application.role_title} · ${application.company_name}`}
            />

            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="type-label text-text-label uppercase">
                            {application.company_name}
                        </p>
                        <h1 className="type-heading-2 text-text-primary">
                            {application.role_title}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <StageBadge
                                stage={application.stage}
                                stages={stages}
                            />
                            <DeadlineBadge application={application} />
                            {application.stage_changed_at && (
                                <span className="type-caption text-text-muted">
                                    Stage changed{' '}
                                    {timeAgo(application.stage_changed_at)}
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {application.can.update && (
                            <StageSelect
                                application={application}
                                stages={stages}
                            />
                        )}
                        {application.source_url && (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={application.source_url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                >
                                    <ExternalLink /> Job ad
                                </a>
                            </Button>
                        )}
                        {application.can.update && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={JobApplicationController.edit(
                                        application.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {application.can.delete && (
                            <Form
                                {...JobApplicationController.destroy.form(
                                    application.ulid,
                                )}
                                onBefore={() =>
                                    window.confirm(
                                        `Delete the ${application.company_name} application? You can restore it from “Recently deleted”.`,
                                    )
                                }
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={processing}
                                    >
                                        Delete
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-6 lg:col-span-2">
                        <TailoredResumeCard
                            application={application}
                            resume={tailoredResume}
                            variants={variants}
                        />
                        <JobDescriptionCard jobDescription={jobDescription} />
                    </div>

                    <div className="flex flex-col gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                    {facts.map(([label, value]) => (
                                        <div key={label} className="contents">
                                            <dt className="text-text-muted">
                                                {label}
                                            </dt>
                                            <dd className="min-w-0 break-words text-text-primary">
                                                {value ?? '—'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </CardContent>
                        </Card>

                        {application.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm whitespace-pre-line">
                                        {application.notes}
                                    </p>
                                </CardContent>
                            </Card>
                        )}

                        <NextStepCard
                            icon={MessageSquareText}
                            title="Questions & cover letter"
                            text="Next: add the form's questions and answer them, or insert answers from your library."
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

function JobDescriptionCard({
    jobDescription,
}: {
    jobDescription: JobDescription | null;
}) {
    const [copied, setCopied] = useState(false);

    if (jobDescription === null) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Job description</CardTitle>
                    <CardDescription>
                        Not pasted yet. Edit the application to add it.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    const copy = async () => {
        await navigator.clipboard.writeText(jobDescription.text);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle>Job description</CardTitle>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={copy}
                    >
                        {copied ? <Check /> : <Copy />}{' '}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>
                <CardDescription>
                    Pasted {timeAgo(jobDescription.captured_at)}
                    {jobDescription.versions > 1 &&
                        ` · ${jobDescription.versions} versions kept`}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="max-h-[70vh] overflow-y-auto pr-2 text-sm leading-relaxed whitespace-pre-line text-text-secondary">
                    {jobDescription.text}
                </div>
            </CardContent>
        </Card>
    );
}

type TailoredResumeSummary = {
    status: 'draft' | 'approved';
    version_no: number | null;
    title: string | null;
    jobs: number;
    based_on: string | null;
    updated_at: string | null;
};

function TailoredResumeCard({
    application,
    resume,
    variants,
}: {
    application: JobApplicationDetail;
    resume: TailoredResumeSummary | null;
    variants: SelectOption[];
}) {
    if (resume !== null) {
        return (
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <CardTitle className="flex items-center gap-2">
                            <FileText className="size-4" aria-hidden /> Tailored
                            resume
                            {resume.status === 'approved' ? (
                                <Badge>Approved</Badge>
                            ) : (
                                <Badge variant="outline">Draft</Badge>
                            )}
                        </CardTitle>
                        <Button size="sm" asChild>
                            <Link
                                href={ApplicationResumeController.show(
                                    application.ulid,
                                )}
                            >
                                {resume.status === 'approved'
                                    ? 'View'
                                    : 'Open editor'}
                            </Link>
                        </Button>
                    </div>
                    <CardDescription>
                        {[
                            resume.title,
                            `${resume.jobs} ${resume.jobs === 1 ? 'job' : 'jobs'}`,
                            resume.based_on && `from “${resume.based_on}”`,
                            `version ${resume.version_no ?? 1}`,
                            resume.updated_at &&
                                `edited ${timeAgo(resume.updated_at)}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <FileText className="size-4" aria-hidden /> Tailored resume
                </CardTitle>
                <CardDescription>
                    Copy one of your resumes into this application, then adjust
                    it for this job: summary, which jobs and bullet points to
                    show, their order and wording. Your library stays as it is.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {variants.length === 0 ? (
                    <p className="type-body text-text-muted">
                        You have no resumes yet. Create one on your Profile
                        first.
                    </p>
                ) : application.can.update ? (
                    <Form
                        {...ApplicationResumeController.store.form(
                            application.ulid,
                        )}
                        className="flex flex-wrap items-start gap-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="min-w-56 flex-1 space-y-1">
                                    <NativeSelect
                                        name="variant"
                                        options={variants}
                                        aria-label="Resume to start from"
                                        className="h-10"
                                    />
                                    {errors.variant && (
                                        <p className="text-sm text-text-error">
                                            {errors.variant}
                                        </p>
                                    )}
                                </div>
                                <Button disabled={processing}>
                                    Start from this resume
                                </Button>
                            </>
                        )}
                    </Form>
                ) : null}
            </CardContent>
        </Card>
    );
}

function NextStepCard({
    icon: Icon,
    title,
    text,
}: {
    icon: LucideIcon;
    title: string;
    text: string;
}) {
    return (
        <div className="flex gap-3 rounded-kraken-md border border-dashed border-border-input p-4">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-kraken-md bg-surface-accent-subtle text-text-accent">
                <Icon className="size-4" aria-hidden />
            </span>
            <div>
                <p className="font-medium text-text-primary">{title}</p>
                <p className="type-caption text-text-muted">{text}</p>
            </div>
        </div>
    );
}

ApplicationShow.layout = {
    breadcrumbs: [
        { title: 'Applications', href: index() },
        { title: 'Application', href: index() },
    ],
};
