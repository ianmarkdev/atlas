import { Form, Head, router } from '@inertiajs/react';
import AiComparisonController from '@/actions/App/Http/Controllers/Settings/AiComparisonController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/ai-comparisons';

type GroundedClaim = {
    text: string;
    classification: string;
    model_classification: string;
    evidence_ids: string[];
    flags: string[];
};

type Result = {
    ulid: string;
    label: string;
    provider: string | null;
    model: string | null;
    status: 'succeeded' | 'invalid_output' | 'failed' | 'skipped';
    output: Record<string, unknown> | null;
    validation_errors: string[];
    grounding: GroundedClaim[];
    error_message: string | null;
    latency_ms: number | null;
    tokens: { input: number; output: number } | null;
    scores: Record<Score, number> | null;
    notes: string | null;
};

type Sample = { key: string; title: string; task: string; results: Result[] };

type Batch = {
    batch: string;
    started_at: string;
    results: number;
    rated: number;
};

type Summary = {
    provider: string;
    rated: number;
    accuracy: number | null;
    specificity: number | null;
    writing: number | null;
    compliance: number | null;
    median_latency_ms: number | null;
    downgraded_claims: number;
};

type Score = 'accuracy' | 'specificity' | 'writing' | 'compliance';

const scoreLabels: Record<Score, string> = {
    accuracy: 'Factual accuracy',
    specificity: 'Specificity',
    writing: 'Writing quality',
    compliance: 'Instruction compliance',
};

const scoreOptions = [1, 2, 3, 4, 5].map((n) => ({
    value: String(n),
    label: String(n),
}));

export default function AiComparisons({
    batches,
    batch,
    samples,
    summary,
}: {
    batches: Batch[];
    batch: string | null;
    samples: Sample[];
    summary: Summary[];
}) {
    return (
        <>
            <Head title="Model comparison" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Model comparison"
                    description="Outputs of the same synthetic samples from different models, labelled A/B so you can score them blind. Run a batch with: php artisan app:compare-llm"
                />

                {batches.length === 0 ? (
                    <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                        No comparisons yet. Run{' '}
                        <code>
                            php artisan app:compare-llm --providers=ollama
                        </code>{' '}
                        (add <code>claude</code> once it is configured).
                    </p>
                ) : (
                    <>
                        <div className="grid max-w-md gap-2">
                            <Label htmlFor="batch">Batch</Label>
                            <NativeSelect
                                id="batch"
                                value={batch ?? ''}
                                options={batches.map((b) => ({
                                    value: b.batch,
                                    label: `${new Date(b.started_at).toLocaleString()} · ${b.rated}/${b.results} scored`,
                                }))}
                                onChange={(event) =>
                                    router.get(index.url(), {
                                        batch: event.target.value,
                                    })
                                }
                            />
                        </div>

                        {summary.length > 0 && <SummaryTable rows={summary} />}

                        {samples.map((sample) => (
                            <section key={sample.key} className="space-y-3">
                                <h2 className="type-card-title text-text-primary">
                                    {sample.title}
                                </h2>
                                <div className="grid gap-4 xl:grid-cols-2">
                                    {sample.results.map((result) => (
                                        <ResultCard
                                            key={result.ulid}
                                            result={result}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </>
                )}
            </div>
        </>
    );
}

function SummaryTable({ rows }: { rows: Summary[] }) {
    return (
        <div className="overflow-x-auto rounded-kraken-md border border-border-subtle">
            <table className="w-full min-w-[560px] text-sm">
                <thead>
                    <tr className="border-b border-border-subtle text-left">
                        {[
                            'Provider',
                            'Scored',
                            'Accuracy',
                            'Specificity',
                            'Writing',
                            'Compliance',
                            'Median latency',
                            'Claims corrected',
                        ].map((heading) => (
                            <th
                                key={heading}
                                scope="col"
                                className="px-3 py-2 type-label font-normal text-text-label uppercase"
                            >
                                {heading}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.provider}
                            className="border-b border-border-subtle tabular-nums last:border-0"
                        >
                            <td className="px-3 py-2 font-medium">
                                {row.provider}
                            </td>
                            <td className="px-3 py-2">{row.rated}</td>
                            <td className="px-3 py-2">{row.accuracy ?? '—'}</td>
                            <td className="px-3 py-2">
                                {row.specificity ?? '—'}
                            </td>
                            <td className="px-3 py-2">{row.writing ?? '—'}</td>
                            <td className="px-3 py-2">
                                {row.compliance ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {row.median_latency_ms === null
                                    ? '—'
                                    : `${(row.median_latency_ms / 1000).toFixed(1)} s`}
                            </td>
                            <td className="px-3 py-2">
                                {row.downgraded_claims}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ResultCard({ result }: { result: Result }) {
    const answer =
        typeof result.output?.answer === 'string' ? result.output.answer : null;
    const corrected = result.grounding.filter((c) => c.flags.length > 0);

    return (
        <article className="space-y-3 rounded-kraken-md border border-border-subtle bg-card p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="type-heading-3 text-text-primary">
                    {result.label}
                </span>
                {result.provider ? (
                    <Badge variant="outline">
                        {result.provider} · {result.model}
                    </Badge>
                ) : (
                    <Badge variant="secondary">Hidden until scored</Badge>
                )}
                {result.status !== 'succeeded' && (
                    <Badge variant="destructive">{result.status}</Badge>
                )}
                {result.latency_ms !== null && (
                    <span className="type-caption text-text-muted tabular-nums">
                        {(result.latency_ms / 1000).toFixed(1)} s
                        {result.tokens &&
                            ` · ${result.tokens.input} in / ${result.tokens.output} out tokens`}
                    </span>
                )}
            </div>

            {result.error_message && (
                <p className="text-sm text-text-error">
                    {result.error_message}
                </p>
            )}
            {result.validation_errors.length > 0 && (
                <ul className="list-disc pl-5 type-caption text-text-error">
                    {result.validation_errors.slice(0, 8).map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}

            {answer !== null ? (
                <p className="text-sm whitespace-pre-line text-text-primary">
                    {answer}
                </p>
            ) : (
                result.output && (
                    <pre className="max-h-96 overflow-auto rounded-kraken-sm bg-surface-card p-3 text-xs whitespace-pre-wrap text-text-secondary">
                        {JSON.stringify(result.output, null, 2)}
                    </pre>
                )
            )}

            {result.grounding.length > 0 && (
                <details className="rounded-kraken-sm border border-border-subtle p-2">
                    <summary className="cursor-pointer type-caption text-text-secondary">
                        {result.grounding.length} claims · {corrected.length}{' '}
                        corrected by the server
                    </summary>
                    <ul className="mt-2 space-y-1 text-xs">
                        {result.grounding.map((claim, i) => (
                            <li key={i} className="text-text-secondary">
                                <Badge
                                    variant={
                                        claim.flags.length > 0
                                            ? 'destructive'
                                            : 'outline'
                                    }
                                >
                                    {claim.classification}
                                </Badge>{' '}
                                {claim.text}
                                {claim.flags.length > 0 && (
                                    <span className="text-text-error">
                                        {' '}
                                        (model said {claim.model_classification}
                                        : {claim.flags.join(', ')})
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {result.status === 'succeeded' && <RatingForm result={result} />}
        </article>
    );
}

function RatingForm({ result }: { result: Result }) {
    return (
        <Form
            {...AiComparisonController.rate.form(result.ulid)}
            options={{ preserveScroll: true }}
            className="space-y-3 border-t border-border-subtle pt-3"
        >
            {({ processing, errors, recentlySuccessful }) => (
                <>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {(Object.keys(scoreLabels) as Score[]).map((score) => (
                            <div key={score} className="grid gap-1">
                                <Label htmlFor={`${result.ulid}_${score}`}>
                                    {scoreLabels[score]}
                                </Label>
                                <NativeSelect
                                    id={`${result.ulid}_${score}`}
                                    name={score}
                                    options={scoreOptions}
                                    placeholder="–"
                                    defaultValue={
                                        result.scores
                                            ? String(result.scores[score])
                                            : ''
                                    }
                                    className="h-10"
                                    required
                                />
                                <InputError message={errors[score]} />
                            </div>
                        ))}
                    </div>
                    <Textarea
                        name="notes"
                        rows={2}
                        defaultValue={result.notes ?? ''}
                        placeholder="Notes: invented facts, generic phrases, missed instructions…"
                        aria-label={`Notes on output ${result.label}`}
                        maxLength={2000}
                    />
                    <div className="flex items-center gap-3">
                        <Button size="sm" disabled={processing}>
                            {result.scores ? 'Update scores' : 'Save scores'}
                        </Button>
                        {recentlySuccessful && (
                            <span className="type-caption text-text-muted">
                                Saved
                            </span>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

AiComparisons.layout = {
    breadcrumbs: [{ title: 'Model comparison', href: index() }],
};
