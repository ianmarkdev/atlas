import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { index } from '@/routes/ai-activity';
import type { Run } from './ai-activity';
import { cost, duration, localTime, StatusBadge } from './ai-activity';

type RunDetail = Run & {
    prompt_key: string;
    request_hash: string;
    idempotency_key: string | null;
    error_message: string | null;
    updated_at: string | null;
};

type Payload = {
    system: string | null;
    messages: { role: string; content: string }[];
    response: string | null;
    output: Record<string, unknown> | null;
    expires_at: string;
};

export default function AiActivityRun({
    run,
    payload,
}: {
    run: RunDetail;
    payload: Payload | null;
}) {
    const details: [string, string][] = [
        ['Started', localTime(run.created_at)],
        [
            'Finished',
            run.status === 'running' ? '—' : localTime(run.updated_at),
        ],
        ['Purpose', run.purpose ?? '—'],
        ['Prompt', `${run.prompt_key} v${run.prompt_version}`],
        ['Provider / model', `${run.provider} / ${run.model}`],
        ['Time', run.status === 'running' ? '—' : duration(run.latency_ms)],
        ['Tries', String(run.attempts)],
        ['Tokens in / out', `${run.input_tokens} / ${run.output_tokens}`],
        ['Cost', cost(run.cost_micros)],
        ['Started by', run.created_by ?? 'System'],
        ['Idempotency key', run.idempotency_key ?? '—'],
    ];

    return (
        <>
            <Head title={`AI run · ${run.task}`} />

            <div className="space-y-6 p-4">
                <Link
                    href={index()}
                    className="type-body text-text-secondary underline-offset-4 hover:underline"
                >
                    ← AI activity
                </Link>

                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title={run.task}
                        description={`Run ${run.ulid}`}
                    />
                    <StatusBadge status={run.status} />
                </div>

                {run.error_message && (
                    <p className="rounded-kraken-sm border border-border-error bg-surface-error-subtle p-3 text-sm text-text-error">
                        {run.error_code && (
                            <span className="font-medium">
                                {run.error_code}:{' '}
                            </span>
                        )}
                        {run.error_message}
                    </p>
                )}

                <dl className="divide-y divide-border-subtle rounded-kraken-md border border-border-subtle text-sm">
                    {details.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex justify-between gap-4 px-3 py-2"
                        >
                            <dt className="text-text-muted">{label}</dt>
                            <dd className="text-right break-all text-text-primary tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                {payload === null ? (
                    <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                        The prompt and response are no longer kept for this run
                        (they are deleted after the retention period).
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-text-muted">
                            Prompt and response kept until{' '}
                            {localTime(payload.expires_at)}.
                        </p>
                        {payload.system && (
                            <Block
                                title="System prompt"
                                text={payload.system}
                            />
                        )}
                        {payload.messages.map((message, i) => (
                            <Block
                                key={i}
                                title={`Message ${i + 1} · ${message.role}`}
                                text={message.content}
                            />
                        ))}
                        {payload.response && (
                            <Block title="Response" text={payload.response} />
                        )}
                        {payload.output && (
                            <Block
                                title="Parsed output"
                                text={JSON.stringify(payload.output, null, 2)}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function Block({ title, text }: { title: string; text: string }) {
    return (
        <details className="rounded-kraken-md border border-border-subtle">
            <summary className="cursor-pointer px-3 py-2 type-body font-medium text-text-primary">
                {title}
            </summary>
            <pre className="max-h-[480px] overflow-auto border-t border-border-subtle p-3 text-xs whitespace-pre-wrap text-text-secondary">
                {text}
            </pre>
        </details>
    );
}

AiActivityRun.layout = {
    breadcrumbs: [{ title: 'AI activity', href: index() }],
};
