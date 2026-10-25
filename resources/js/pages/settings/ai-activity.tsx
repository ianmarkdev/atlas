import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/ai-activity';

export type RunStatus = 'running' | 'succeeded' | 'failed' | 'invalid_output';

export type Run = {
    ulid: string;
    task: string;
    purpose: string | null;
    prompt_version: number;
    provider: string;
    model: string;
    status: RunStatus;
    error_code: string | null;
    input_tokens: number;
    output_tokens: number;
    cost_micros: number;
    latency_ms: number;
    attempts: number;
    created_by: string | null;
    created_at: string | null;
};

type TaskSummary = {
    task: string;
    runs: number;
    succeeded: number;
    failed: number;
    invalid_output: number;
    running: number;
    median_latency_ms: number | null;
    p95_latency_ms: number | null;
    max_latency_ms: number | null;
    avg_input_tokens: number;
    avg_output_tokens: number;
    cost_micros: number;
    retried: number;
};

type Accuracy = {
    drafts: number;
    claims: number;
    verified: number;
    unsupported: number;
    corrected: number;
    edited_drafts: number;
    edited_words: number;
};

type Filters = {
    days: number;
    task: string | null;
    status: RunStatus | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export const statusLabels: Record<RunStatus, string> = {
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
    invalid_output: 'Invalid output',
};

export function StatusBadge({ status }: { status: RunStatus }) {
    const variant =
        status === 'succeeded'
            ? 'secondary'
            : status === 'running'
              ? 'outline'
              : 'destructive';

    return <Badge variant={variant}>{statusLabels[status]}</Badge>;
}

/** 850 ms, 16.3 s, 3m 13s. */
export function duration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    if (ms < 1000) {
        return `${ms} ms`;
    }

    if (ms < 60_000) {
        return `${(ms / 1000).toFixed(1)} s`;
    }

    const seconds = Math.round(ms / 1000);

    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export function cost(micros: number): string {
    return micros === 0 ? '$0' : `$${(micros / 1_000_000).toFixed(4)}`;
}

/** In the viewer's own timezone (the server stores and sends UTC). */
export function localTime(iso: string | null): string {
    return iso === null ? '—' : new Date(iso).toLocaleString();
}

function percent(part: number, whole: number): string {
    return whole === 0 ? '—' : `${Math.round((part / whole) * 100)}%`;
}

export default function AiActivity({
    filters,
    periods,
    statuses,
    tasks,
    summary,
    accuracy,
    runs,
}: {
    filters: Filters;
    periods: number[];
    statuses: RunStatus[];
    tasks: string[];
    summary: TaskSummary[];
    accuracy: Accuracy;
    runs: Paginated<Run>;
}) {
    const filter = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };

        router.get(
            index.url(),
            Object.fromEntries(
                Object.entries(next).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="AI activity" />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="AI activity"
                    description="Every AI call: how often each task worked, how long it took, what it used, and how accurate the answer drafts were. Times are in your local timezone."
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="days">Period</Label>
                        <NativeSelect
                            id="days"
                            value={String(filters.days)}
                            options={periods.map((days) => ({
                                value: String(days),
                                label:
                                    days === 1
                                        ? 'Last 24 hours'
                                        : `Last ${days} days`,
                            }))}
                            onChange={(event) =>
                                filter({ days: Number(event.target.value) })
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="task">Task</Label>
                        <NativeSelect
                            id="task"
                            value={filters.task ?? ''}
                            placeholder="All tasks"
                            options={tasks.map((task) => ({
                                value: task,
                                label: task,
                            }))}
                            onChange={(event) =>
                                filter({ task: event.target.value || null })
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <NativeSelect
                            id="status"
                            value={filters.status ?? ''}
                            placeholder="All statuses"
                            options={statuses.map((status) => ({
                                value: status,
                                label: statusLabels[status],
                            }))}
                            onChange={(event) =>
                                filter({
                                    status:
                                        (event.target.value as RunStatus) ||
                                        null,
                                })
                            }
                        />
                    </div>
                </div>

                <section className="space-y-3">
                    <h2 className="type-card-title text-text-primary">
                        By task
                    </h2>
                    {summary.length === 0 ? (
                        <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                            No AI calls in this period.
                        </p>
                    ) : (
                        <SummaryTable rows={summary} />
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="type-card-title text-text-primary">
                        Answer draft accuracy
                    </h2>
                    <AccuracyList accuracy={accuracy} />
                </section>

                <section className="space-y-3">
                    <h2 className="type-card-title text-text-primary">
                        Runs{' '}
                        <span className="type-body text-text-muted">
                            ({runs.total})
                        </span>
                    </h2>
                    {runs.data.length === 0 ? (
                        <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                            No runs match these filters.
                        </p>
                    ) : (
                        <RunTable runs={runs.data} />
                    )}
                    {runs.last_page > 1 && (
                        <nav
                            className="flex items-center justify-between type-body text-text-secondary"
                            aria-label="Pages"
                        >
                            {runs.prev_page_url ? (
                                <Link href={runs.prev_page_url} preserveScroll>
                                    ← Newer
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-text-muted">
                                Page {runs.current_page} of {runs.last_page}
                            </span>
                            {runs.next_page_url ? (
                                <Link href={runs.next_page_url} preserveScroll>
                                    Older →
                                </Link>
                            ) : (
                                <span />
                            )}
                        </nav>
                    )}
                </section>
            </div>
        </>
    );
}

const cell = 'px-3 py-2';

function TableHead({ headings }: { headings: string[] }) {
    return (
        <thead>
            <tr className="border-b border-border-subtle text-left">
                {headings.map((heading) => (
                    <th
                        key={heading}
                        scope="col"
                        className={`${cell} type-label font-normal whitespace-nowrap text-text-label uppercase`}
                    >
                        {heading}
                    </th>
                ))}
            </tr>
        </thead>
    );
}

function SummaryTable({ rows }: { rows: TaskSummary[] }) {
    return (
        <div className="overflow-x-auto rounded-kraken-md border border-border-subtle">
            <table className="w-full min-w-[880px] text-sm">
                <TableHead
                    headings={[
                        'Task',
                        'Runs',
                        'Success',
                        'Failed',
                        'Invalid',
                        'Running',
                        'Median',
                        'p95',
                        'Slowest',
                        'Avg tokens in / out',
                        'Retried',
                        'Cost',
                    ]}
                />
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.task}
                            className="border-b border-border-subtle tabular-nums last:border-0"
                        >
                            <td className={`${cell} font-medium`}>
                                {row.task}
                            </td>
                            <td className={cell}>{row.runs}</td>
                            <td className={cell}>
                                {percent(row.succeeded, row.runs - row.running)}
                            </td>
                            <td className={cell}>{row.failed}</td>
                            <td className={cell}>{row.invalid_output}</td>
                            <td className={cell}>{row.running}</td>
                            <td className={cell}>
                                {duration(row.median_latency_ms)}
                            </td>
                            <td className={cell}>
                                {duration(row.p95_latency_ms)}
                            </td>
                            <td className={cell}>
                                {duration(row.max_latency_ms)}
                            </td>
                            <td className={cell}>
                                {row.avg_input_tokens} / {row.avg_output_tokens}
                            </td>
                            <td className={cell}>{row.retried}</td>
                            <td className={cell}>{cost(row.cost_micros)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function AccuracyList({ accuracy }: { accuracy: Accuracy }) {
    const items: { label: string; value: string; hint: string }[] = [
        {
            label: 'AI drafts',
            value: String(accuracy.drafts),
            hint: 'Answer drafts written by the AI',
        },
        {
            label: 'Claims backed by the library',
            value: `${percent(accuracy.verified, accuracy.claims)} of ${accuracy.claims}`,
            hint: "Claims labelled verified after the server's check",
        },
        {
            label: 'Unsupported claims',
            value: String(accuracy.unsupported),
            hint: 'Proposed or hypothetical: not in the library yet',
        },
        {
            label: 'Labels corrected by the server',
            value: String(accuracy.corrected),
            hint: 'The model claimed evidence it did not have',
        },
        {
            label: 'Drafts edited by a person',
            value: `${accuracy.edited_drafts} of ${accuracy.drafts}`,
            hint: `${accuracy.edited_words} words changed in total`,
        },
    ];

    return (
        <dl className="divide-y divide-border-subtle rounded-kraken-md border border-border-subtle">
            {items.map((item) => (
                <div
                    key={item.label}
                    className="flex items-baseline justify-between gap-4 px-3 py-2"
                >
                    <dt>
                        <span className="type-body text-text-primary">
                            {item.label}
                        </span>
                        <span className="block text-sm text-text-muted">
                            {item.hint}
                        </span>
                    </dt>
                    <dd className="text-right font-medium text-text-primary tabular-nums">
                        {item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function RunTable({ runs }: { runs: Run[] }) {
    return (
        <div className="overflow-x-auto rounded-kraken-md border border-border-subtle">
            <table className="w-full min-w-[760px] text-sm">
                <TableHead
                    headings={[
                        'Started',
                        'Task',
                        'Model',
                        'Status',
                        'Time',
                        'Tokens in / out',
                        'Tries',
                    ]}
                />
                <tbody>
                    {runs.map((run) => (
                        <tr
                            key={run.ulid}
                            className="border-b border-border-subtle tabular-nums last:border-0 hover:bg-surface-input-subtle"
                        >
                            <td className={`${cell} whitespace-nowrap`}>
                                <Link
                                    href={show(run.ulid)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {localTime(run.created_at)}
                                </Link>
                            </td>
                            <td className={cell}>
                                <span className="font-medium">{run.task}</span>
                                {run.purpose && (
                                    <span className="block text-text-muted">
                                        {run.purpose}
                                    </span>
                                )}
                            </td>
                            <td className={cell}>{run.model}</td>
                            <td className={cell}>
                                <StatusBadge status={run.status} />
                                {run.error_code && (
                                    <span className="block text-text-muted">
                                        {run.error_code}
                                    </span>
                                )}
                            </td>
                            <td className={cell}>
                                {run.status === 'running'
                                    ? '—'
                                    : duration(run.latency_ms)}
                            </td>
                            <td className={cell}>
                                {run.input_tokens} / {run.output_tokens}
                            </td>
                            <td className={cell}>{run.attempts}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
