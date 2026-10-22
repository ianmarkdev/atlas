import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    CircleDashed,
    CircleSlash,
    Plus,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import JobAnalysisController from '@/actions/App/Http/Controllers/Applications/JobAnalysisController';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import Heading from '@/components/heading';
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
import { Checkbox } from '@/components/ui/checkbox';
import { timeAgo } from '@/lib/time';
import { create as createClaim } from '@/routes/knowledge/claims';
import { index as applicationsIndex } from '@/routes/applications';

type Requirement = {
    requirement: string;
    quote: string;
    quote_check: 'found' | 'close' | 'not_found' | 'missing';
};

type Brief = {
    summary: string;
    seniority: string;
    must_haves: Requirement[];
    nice_to_haves: Requirement[];
    employer_language: string[];
    questions: string[];
    red_flags: string[];
    injection_warnings: string[];
    server_injection_warnings: string[];
};

type Evidence = {
    ref: string | null;
    unit_type: string;
    status: string;
    text: string;
    score: number;
};

type Match = {
    kind: 'must' | 'nice';
    index: number;
    requirement: string;
    coverage: 'strong' | 'partial' | 'gap';
    evidence: Evidence[];
};

type Analysis = {
    ulid: string;
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'invalid_output';
    brief: Brief | null;
    matches: {
        semantic: boolean;
        semantic_reason: string | null;
        requirements: Match[];
    } | null;
    matched_at: string | null;
    error_message: string | null;
    created_at: string | null;
    completed_at: string | null;
    requested_by: string | null;
    model: string | null;
    latency_ms: number | null;
    is_latest_posting: boolean;
    question_already_added: boolean[];
};

export default function AnalysisPage({
    application,
    analysis,
    history,
    canUpdate,
}: {
    application: {
        ulid: string;
        company_name: string;
        role_title: string;
        has_job_description: boolean;
    };
    analysis: Analysis | null;
    history: { ulid: string; status: string; created_at: string | null }[];
    canUpdate: boolean;
}) {
    const pending =
        analysis !== null &&
        (analysis.status === 'queued' || analysis.status === 'running');
    const poll = usePoll(
        3000,
        { only: ['analysis', 'history'] },
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

    return (
        <>
            <Head
                title={`Analysis · ${application.role_title} at ${application.company_name}`}
            />

            <div className="flex flex-col gap-6 p-4 lg:max-w-5xl lg:p-6">
                <Link
                    href={JobApplicationController.show(application.ulid)}
                    className="inline-flex items-center gap-1.5 self-start text-sm text-text-secondary focus-ring hover:text-text-primary"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    {application.role_title} · {application.company_name}
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Job analysis"
                        description="What the employer asks for, and which of your facts back each requirement. Quotes and warnings are checked by the server, not just the AI."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {history.length > 1 && (
                            <NativeSelect
                                aria-label="Analysis version"
                                className="h-9 w-auto"
                                value={analysis?.ulid ?? ''}
                                options={history.map((item, i) => ({
                                    value: item.ulid,
                                    label: `${i === 0 ? 'Latest' : `#${history.length - i}`} · ${item.created_at ? new Date(item.created_at).toLocaleString() : ''} · ${item.status}`,
                                }))}
                                onChange={(event) =>
                                    router.get(
                                        JobAnalysisController.show.url(
                                            application.ulid,
                                        ),
                                        { analysis: event.target.value },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        )}
                        {canUpdate && application.has_job_description && (
                            <Form
                                {...JobAnalysisController.store.form(
                                    application.ulid,
                                )}
                            >
                                {({ processing, errors }) => (
                                    <div className="space-y-1">
                                        <Button
                                            size="sm"
                                            disabled={processing || pending}
                                        >
                                            {analysis === null
                                                ? 'Analyse'
                                                : 'Re-analyse'}
                                        </Button>
                                        {errors.analysis && (
                                            <p className="text-xs text-text-error">
                                                {errors.analysis}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </Form>
                        )}
                    </div>
                </div>

                {analysis === null ? (
                    <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                        {application.has_job_description
                            ? 'Not analysed yet.'
                            : 'Paste the job description on the application first.'}
                    </p>
                ) : pending ? (
                    <p
                        className="rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-6 type-body text-text-primary"
                        aria-live="polite"
                    >
                        Analysing… with a local model this can take a minute or
                        two. This page updates by itself.
                    </p>
                ) : analysis.status !== 'succeeded' ||
                  analysis.brief === null ? (
                    <p
                        role="alert"
                        className="rounded-kraken-sm border border-border-error bg-surface-error-subtle p-4 text-sm text-text-error"
                    >
                        The analysis failed: {analysis.error_message}
                    </p>
                ) : (
                    <BriefView
                        application={application.ulid}
                        analysis={analysis}
                        brief={analysis.brief}
                        canUpdate={canUpdate}
                    />
                )}
            </div>
        </>
    );
}

function BriefView({
    application,
    analysis,
    brief,
    canUpdate,
}: {
    application: string;
    analysis: Analysis;
    brief: Brief;
    canUpdate: boolean;
}) {
    const warnings = [
        ...brief.server_injection_warnings,
        ...brief.injection_warnings,
    ];
    const matchFor = (kind: 'must' | 'nice', index: number) =>
        analysis.matches?.requirements.find(
            (m) => m.kind === kind && m.index === index,
        );

    return (
        <div className="space-y-6">
            <p className="type-caption text-text-muted">
                {analysis.model && `${analysis.model} · `}
                {analysis.latency_ms !== null &&
                    `${(analysis.latency_ms / 1000).toFixed(1)} s · `}
                {analysis.completed_at &&
                    `analysed ${timeAgo(analysis.completed_at)}`}
                {analysis.requested_by && ` by ${analysis.requested_by}`}
                {!analysis.is_latest_posting &&
                    ' · the job description has changed since: re-analyse to update'}
            </p>

            {warnings.length > 0 && (
                <div
                    role="alert"
                    className="space-y-1 rounded-kraken-sm border border-border-error bg-surface-error-subtle p-4 text-sm"
                >
                    <p className="flex items-center gap-2 font-medium text-text-error">
                        <AlertTriangle className="size-4" aria-hidden /> The job
                        description contains instructions aimed at AI tools
                    </p>
                    <p className="text-text-secondary">
                        They were treated as data and not followed. Review them;
                        they may be a test or a sign of a scam.
                    </p>
                    <ul className="list-disc pl-5 text-text-primary">
                        {[...new Set(warnings)].map((warning) => (
                            <li key={warning}>{warning}</li>
                        ))}
                    </ul>
                </div>
            )}

            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <CardTitle>Summary</CardTitle>
                        <Badge variant="outline">{brief.seniority}</Badge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-3">
                    <p className="text-sm text-text-primary">{brief.summary}</p>
                    {brief.employer_language.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {brief.employer_language.map((phrase) => (
                                <Badge key={phrase} variant="secondary">
                                    {phrase}
                                </Badge>
                            ))}
                        </div>
                    )}
                    {brief.red_flags.length > 0 && (
                        <ul className="list-disc pl-5 text-sm text-text-secondary">
                            {brief.red_flags.map((flag) => (
                                <li key={flag}>{flag}</li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <RequirementList
                title="Must-haves"
                kind="must"
                items={brief.must_haves}
                matchFor={matchFor}
                headerAction={
                    canUpdate && (
                        <Form
                            {...JobAnalysisController.refresh.form([
                                application,
                                analysis.ulid,
                            ])}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                    title="Match again against your current library (no AI call)"
                                >
                                    <RefreshCw /> Refresh evidence
                                </Button>
                            )}
                        </Form>
                    )
                }
                note={
                    analysis.matches && !analysis.matches.semantic
                        ? `Keyword matching only: ${analysis.matches.semantic_reason ?? 'embeddings unavailable'}`
                        : null
                }
            />
            <RequirementList
                title="Nice to have"
                kind="nice"
                items={brief.nice_to_haves}
                matchFor={matchFor}
            />

            {brief.questions.length > 0 && (
                <QuestionsFound
                    application={application}
                    analysis={analysis}
                    questions={brief.questions}
                    canUpdate={canUpdate}
                />
            )}
        </div>
    );
}

const coverageBadge = {
    strong: {
        icon: CheckCircle2,
        label: 'Strong evidence',
        variant: 'default',
    },
    partial: { icon: CircleDashed, label: 'Partial', variant: 'secondary' },
    gap: { icon: CircleSlash, label: 'Gap', variant: 'destructive' },
} as const;

function RequirementList({
    title,
    kind,
    items,
    matchFor,
    headerAction,
    note,
}: {
    title: string;
    kind: 'must' | 'nice';
    items: Requirement[];
    matchFor: (kind: 'must' | 'nice', index: number) => Match | undefined;
    headerAction?: React.ReactNode;
    note?: string | null;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle>{title}</CardTitle>
                    {headerAction}
                </div>
                {note && <CardDescription>{note}</CardDescription>}
            </CardHeader>
            <CardContent>
                <ul className="divide-y divide-border-subtle">
                    {items.map((item, index) => {
                        const match = matchFor(kind, index);
                        const coverage = match
                            ? coverageBadge[match.coverage]
                            : null;

                        return (
                            <li key={index} className="space-y-2 py-3">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p className="font-medium text-text-primary">
                                        {item.requirement}
                                    </p>
                                    {coverage && (
                                        <Badge variant={coverage.variant}>
                                            <coverage.icon aria-hidden />{' '}
                                            {coverage.label}
                                        </Badge>
                                    )}
                                </div>
                                {item.quote && (
                                    <p className="type-caption text-text-muted">
                                        “{item.quote}”
                                        {item.quote_check === 'not_found' && (
                                            <span className="text-text-error">
                                                {' '}
                                                · not found in the job
                                                description (AI paraphrase)
                                            </span>
                                        )}
                                    </p>
                                )}
                                {match && match.evidence.length > 0 ? (
                                    <ul className="space-y-1">
                                        {match.evidence
                                            .slice(0, 3)
                                            .map((evidence, i) => (
                                                <li
                                                    key={i}
                                                    className="flex gap-2 rounded-kraken-sm bg-surface-card px-2 py-1.5 text-sm text-text-secondary"
                                                >
                                                    {evidence.status ===
                                                        'proposed' && (
                                                        <Badge variant="outline">
                                                            unverified
                                                        </Badge>
                                                    )}
                                                    <span className="min-w-0">
                                                        {evidence.text}
                                                    </span>
                                                </li>
                                            ))}
                                    </ul>
                                ) : (
                                    match && (
                                        <Link
                                            href={createClaim({
                                                query: { subject: 'general' },
                                            })}
                                            className="inline-flex items-center gap-1 type-caption text-text-accent focus-ring hover:underline"
                                        >
                                            <Plus className="size-3.5" /> Add a
                                            bullet point that covers this
                                        </Link>
                                    )
                                )}
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}

function QuestionsFound({
    application,
    analysis,
    questions,
    canUpdate,
}: {
    application: string;
    analysis: Analysis;
    questions: string[];
    canUpdate: boolean;
}) {
    const [selected, setSelected] = useState<number[]>(() =>
        questions
            .map((_, i) => i)
            .filter((i) => !analysis.question_already_added[i]),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Questions found</CardTitle>
                <CardDescription>
                    Questions the job description asks you to answer. Add them
                    to the application to answer them there.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <ul className="space-y-2">
                    {questions.map((question, i) => {
                        const added = analysis.question_already_added[i];

                        return (
                            <li
                                key={i}
                                className="flex items-start gap-2 text-sm"
                            >
                                <Checkbox
                                    className="mt-0.5"
                                    checked={added || selected.includes(i)}
                                    disabled={added || !canUpdate}
                                    onCheckedChange={() =>
                                        setSelected((current) =>
                                            current.includes(i)
                                                ? current.filter((n) => n !== i)
                                                : [...current, i],
                                        )
                                    }
                                    aria-label={question}
                                />
                                <span className="text-text-primary">
                                    {question}
                                    {added && (
                                        <span className="type-caption text-text-muted">
                                            {' '}
                                            · already on the application
                                        </span>
                                    )}
                                </span>
                            </li>
                        );
                    })}
                </ul>
                {canUpdate && (
                    <Button
                        size="sm"
                        disabled={selected.length === 0}
                        onClick={() =>
                            router.post(
                                JobAnalysisController.addQuestions.url([
                                    application,
                                    analysis.ulid,
                                ]),
                                { questions: selected },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Plus /> Add {selected.length}{' '}
                        {selected.length === 1 ? 'question' : 'questions'}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

AnalysisPage.layout = {
    breadcrumbs: [
        { title: 'Applications', href: applicationsIndex() },
        { title: 'Job analysis', href: applicationsIndex() },
    ],
};
