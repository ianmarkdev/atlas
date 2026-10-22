import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import {
    Check,
    Copy,
    ExternalLink,
    FileText,
    MessageSquareText,
    Sparkles,
    Pencil,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ApplicationQuestionController from '@/actions/App/Http/Controllers/Applications/ApplicationQuestionController';
import JdCollectionController from '@/actions/App/Http/Controllers/Applications/JdCollectionController';
import JobAnalysisController from '@/actions/App/Http/Controllers/Applications/JobAnalysisController';
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
import { companyLabel, formatDay } from '@/lib/applications';
import { timeAgo } from '@/lib/time';
import { copyToClipboard } from '@/lib/clipboard';
import { index } from '@/routes/applications';
import { index as collectionsIndex } from '@/routes/collections';
import ApplicationReviewController from '@/actions/App/Http/Controllers/Applications/ApplicationReviewController';
import { ReviewBadge, Timeline } from '@/components/applications/review';
import InputError from '@/components/input-error';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import type { ActivityItem, JobApplicationDetail, SelectOption } from '@/types';

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
    questionsSummary,
    analysisSummary,
    collections,
    review,
    timeline,
}: {
    application: JobApplicationDetail;
    jobDescription: JobDescription | null;
    stages: SelectOption[];
    employmentTypes: SelectOption[];
    tailoredResume: TailoredResumeSummary | null;
    variants: SelectOption[];
    questionsSummary: QuestionsSummary;
    analysisSummary: AnalysisSummary | null;
    collections: {
        member: { ulid: string; name: string; use_for_eval: boolean }[];
        all: SelectOption[];
    };
    review: ReviewProps;
    timeline: ActivityItem[];
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
                title={`${application.role_title} · ${companyLabel(application.company_name)}`}
            />

            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="type-label text-text-label uppercase">
                            {companyLabel(application.company_name)}
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
                                        `Delete the ${companyLabel(application.company_name)} application? You can restore it from “Recently deleted”.`,
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
                        <AnalysisCard
                            application={application}
                            summary={analysisSummary}
                            hasJobDescription={jobDescription !== null}
                        />
                        <TailoredResumeCard
                            application={application}
                            resume={tailoredResume}
                            variants={variants}
                        />
                        <QuestionsCard
                            application={application.ulid}
                            summary={questionsSummary}
                        />
                        <JobDescriptionCard jobDescription={jobDescription} />
                        <Card>
                            <CardHeader>
                                <CardTitle>Activity</CardTitle>
                                <CardDescription>
                                    Everything done on this application, its
                                    resume and its answers.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Timeline items={timeline} />
                            </CardContent>
                        </Card>
                    </div>

                    <div className="flex flex-col gap-6">
                        <ReviewCard application={application} review={review} />
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
                                <CollectionsRow
                                    application={application}
                                    collections={collections}
                                />
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
        const isCopied = await copyToClipboard(jobDescription.text);
        setCopied(isCopied);
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

type ReviewProps = {
    me: string;
    missing: string[];
    team: SelectOption[];
    can: {
        assign: boolean;
        take: boolean;
        handBack: boolean;
        start: boolean;
        submit: boolean;
        review: boolean;
    };
};

/**
 * Who prepares the application and where the review stands, with the next steps the signed-in
 * user can take.
 */
function ReviewCard({
    application,
    review,
}: {
    application: JobApplicationDetail;
    review: ReviewProps;
}) {
    const status = application.review_status;
    const assignee = application.assignee ?? null;
    const canStart =
        review.can.start &&
        (status === 'not_started' || status === 'changes_requested');
    const canSubmit =
        review.can.submit &&
        ['not_started', 'in_progress', 'changes_requested'].includes(status);
    const inReview = status === 'in_review';

    const assign = (assigneeUlid: string | null) =>
        router.patch(
            ApplicationReviewController.assign.url(application.ulid),
            { assignee: assigneeUlid },
            { preserveScroll: true },
        );

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle>Preparation</CardTitle>
                    <ReviewBadge status={status} />
                </div>
                {application.review_status_changed_at && (
                    <CardDescription>
                        Since {timeAgo(application.review_status_changed_at)}
                    </CardDescription>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {application.review_note && status !== 'approved' && (
                    <div
                        className={
                            status === 'changes_requested'
                                ? 'rounded-kraken-sm border border-border-error bg-surface-error-subtle p-3 text-sm'
                                : 'rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-3 text-sm'
                        }
                    >
                        <p className="type-label text-text-label uppercase">
                            {status === 'changes_requested'
                                ? 'Changes requested'
                                : 'Note from the VA'}
                        </p>
                        <p className="whitespace-pre-line text-text-primary">
                            {application.review_note}
                        </p>
                    </div>
                )}

                <div className="grid gap-2">
                    <p className="type-label text-text-label uppercase">
                        Assigned to
                    </p>
                    {review.can.assign ? (
                        <NativeSelect
                            aria-label="Assigned to"
                            className="h-10"
                            value={assignee?.ulid ?? ''}
                            options={review.team}
                            placeholder="Nobody"
                            onChange={(event) =>
                                assign(event.target.value || null)
                            }
                        />
                    ) : (
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-sm text-text-primary">
                                {assignee
                                    ? assignee.is_you
                                        ? 'You'
                                        : assignee.name
                                    : 'Nobody yet'}
                            </span>
                            {review.can.take && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => assign(review.me)}
                                >
                                    I’ll take it
                                </Button>
                            )}
                            {review.can.handBack && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => assign(null)}
                                >
                                    Hand back
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap gap-2">
                    {canStart && (
                        <Form
                            {...ApplicationReviewController.start.form(
                                application.ulid,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Start preparing
                                </Button>
                            )}
                        </Form>
                    )}
                    {canSubmit && (
                        <NoteDialog
                            trigger="Send for review"
                            title="Send to the candidate for review"
                            description="Say what is ready and anything they should check."
                            action={ApplicationReviewController.submit.form(
                                application.ulid,
                            )}
                            submitLabel="Send for review"
                            required={false}
                        />
                    )}
                    {review.can.review && inReview && (
                        <NoteDialog
                            trigger="Request changes"
                            title="Request changes"
                            description="The note goes back to whoever prepared the application."
                            action={ApplicationReviewController.requestChanges.form(
                                application.ulid,
                            )}
                            submitLabel="Send back"
                            required
                            variant="outline"
                        />
                    )}
                    {review.can.review && status !== 'approved' && (
                        <Form
                            {...ApplicationReviewController.approve.form(
                                application.ulid,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="space-y-1">
                                    <Button
                                        size="sm"
                                        disabled={
                                            processing ||
                                            review.missing.length > 0
                                        }
                                    >
                                        Approve application
                                    </Button>
                                    <InputError message={errors.review} />
                                </div>
                            )}
                        </Form>
                    )}
                    {review.can.review && status === 'approved' && (
                        <Form
                            {...ApplicationReviewController.reopen.form(
                                application.ulid,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={processing}
                                >
                                    Reopen
                                </Button>
                            )}
                        </Form>
                    )}
                </div>

                {review.can.review &&
                    status !== 'approved' &&
                    review.missing.length > 0 && (
                        <div className="type-caption text-text-muted">
                            <p>
                                Approve these before approving the application:
                            </p>
                            <ul className="mt-1 list-disc pl-5">
                                {review.missing.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                {status === 'approved' && (
                    <p className="type-caption text-text-muted">
                        Ready to send. Once it is sent, move it to Applied.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function NoteDialog({
    trigger,
    title,
    description,
    action,
    submitLabel,
    required,
    variant = 'default',
}: {
    trigger: string;
    title: string;
    description: string;
    action: ReturnType<typeof ApplicationReviewController.submit.form>;
    submitLabel: string;
    required: boolean;
    variant?: 'default' | 'outline';
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant={variant}>
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Textarea
                                name="note"
                                rows={4}
                                maxLength={2000}
                                required={required}
                                aria-label={
                                    required ? 'Note' : 'Note (optional)'
                                }
                                placeholder={
                                    required
                                        ? 'What should change?'
                                        : 'Optional note'
                                }
                            />
                            <InputError
                                message={errors.note ?? errors.review}
                            />
                            <Button disabled={processing}>{submitLabel}</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Which collections the application is in, with add/remove.
 */
function CollectionsRow({
    application,
    collections,
}: {
    application: JobApplicationDetail;
    collections: {
        member: { ulid: string; name: string; use_for_eval: boolean }[];
        all: SelectOption[];
    };
}) {
    const memberIds = new Set(collections.member.map((c) => c.ulid));
    const available = collections.all.filter((c) => !memberIds.has(c.value));
    const toggle = (collection: string, member: boolean) =>
        router.patch(
            JdCollectionController.toggle.url(application.ulid),
            { collection, member },
            { preserveScroll: true },
        );

    return (
        <div className="mt-4 space-y-2 border-t border-border-subtle pt-3">
            <p className="flex items-center justify-between type-label text-text-label uppercase">
                Collections
                <Link
                    href={collectionsIndex()}
                    className="text-text-accent normal-case focus-ring hover:underline"
                >
                    Manage
                </Link>
            </p>
            <div className="flex flex-wrap gap-1.5">
                {collections.member.length === 0 && (
                    <span className="type-caption text-text-muted">None</span>
                )}
                {collections.member.map((collection) => (
                    <Badge
                        key={collection.ulid}
                        variant={
                            collection.use_for_eval ? 'secondary' : 'outline'
                        }
                    >
                        {collection.name}
                        {application.can.update && (
                            <button
                                type="button"
                                onClick={() => toggle(collection.ulid, false)}
                                className="ml-1 focus-ring"
                                aria-label={`Remove from ${collection.name}`}
                            >
                                ×
                            </button>
                        )}
                    </Badge>
                ))}
            </div>
            {application.can.update && available.length > 0 && (
                <NativeSelect
                    aria-label="Add to a collection"
                    className="h-9"
                    value=""
                    placeholder="Add to a collection…"
                    options={available}
                    onChange={(event) =>
                        event.target.value && toggle(event.target.value, true)
                    }
                />
            )}
        </div>
    );
}

type AnalysisSummary = {
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'invalid_output';
    created_at: string | null;
    must_haves: number;
    strong: number;
    gaps: number;
    injection_warnings: number;
    error_message: string | null;
    is_latest_posting: boolean;
};

/**
 * The AI analysis of the job description: start it, see its state, open the brief.
 */
function AnalysisCard({
    application,
    summary,
    hasJobDescription,
}: {
    application: JobApplicationDetail;
    summary: AnalysisSummary | null;
    hasJobDescription: boolean;
}) {
    const pending =
        summary !== null &&
        (summary.status === 'queued' || summary.status === 'running');
    const poll = usePoll(
        3000,
        { only: ['analysisSummary', 'timeline'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (pending) {
            poll.start();
        } else {
            poll.stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pending]);

    const description = (() => {
        if (!hasJobDescription) {
            return 'Paste the job description to analyse it.';
        }

        if (summary === null) {
            return "Let AI read the job description: requirements, the employer's language, questions to answer, and which of your facts match each requirement.";
        }

        if (pending) {
            return 'Analysing… this can take a minute or two with a local model.';
        }

        if (summary.status !== 'succeeded') {
            return `The last analysis failed: ${summary.error_message ?? 'unknown error'}`;
        }

        return `${summary.must_haves} must-haves · ${summary.strong} with strong evidence · ${summary.gaps} gaps${summary.injection_warnings > 0 ? ' · contains instructions aimed at AI' : ''}${summary.is_latest_posting ? '' : ' · the job description changed since'}`;
    })();

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <Sparkles className="size-4" aria-hidden /> Job analysis
                        {pending && <Badge variant="secondary">Running</Badge>}
                        {summary !== null &&
                            summary.status !== 'succeeded' &&
                            !pending && (
                                <Badge variant="destructive">Failed</Badge>
                            )}
                    </CardTitle>
                    <div className="flex gap-2">
                        {summary !== null && (
                            <Button size="sm" variant="outline" asChild>
                                <Link
                                    href={JobAnalysisController.show(
                                        application.ulid,
                                    )}
                                >
                                    Open brief
                                </Link>
                            </Button>
                        )}
                        {application.can.update && hasJobDescription && (
                            <Form
                                {...JobAnalysisController.store.form(
                                    application.ulid,
                                )}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        disabled={processing || pending}
                                    >
                                        {summary === null
                                            ? 'Analyse'
                                            : 'Re-analyse'}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
        </Card>
    );
}

type QuestionsSummary = {
    total: number;
    answered: number;
    approved: number;
    has_cover_letter: boolean;
};

function QuestionsCard({
    application,
    summary,
}: {
    application: string;
    summary: QuestionsSummary;
}) {
    const percent =
        summary.total === 0
            ? 0
            : Math.round((summary.approved / summary.total) * 100);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <MessageSquareText className="size-4" aria-hidden />{' '}
                        Questions & cover letter
                    </CardTitle>
                    <Button
                        size="sm"
                        variant={summary.total === 0 ? 'default' : 'outline'}
                        asChild
                    >
                        <Link
                            href={ApplicationQuestionController.index(
                                application,
                            )}
                        >
                            {summary.total === 0
                                ? 'Add questions'
                                : 'Answer questions'}
                        </Link>
                    </Button>
                </div>
                <CardDescription>
                    {summary.total === 0
                        ? 'Paste the questions from the application form; answer them by hand or from your answer library.'
                        : `${summary.total} ${summary.total === 1 ? 'question' : 'questions'} · ${summary.answered} answered · ${summary.approved} approved${summary.has_cover_letter ? ' · includes a cover letter' : ''}`}
                </CardDescription>
            </CardHeader>
            {summary.total > 0 && (
                <CardContent>
                    <div
                        role="progressbar"
                        aria-label="Approved answers"
                        aria-valuenow={percent}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        className="h-1.5 overflow-hidden rounded-pill bg-surface-input-subtle"
                    >
                        <div
                            className="h-full rounded-pill bg-surface-accent transition-all duration-slow"
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                </CardContent>
            )}
        </Card>
    );
}

ApplicationShow.layout = {
    breadcrumbs: [
        { title: 'Applications', href: index() },
        { title: 'Application', href: index() },
    ],
};
