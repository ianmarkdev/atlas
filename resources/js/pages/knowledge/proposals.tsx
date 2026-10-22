import { Form, Head, usePoll } from '@inertiajs/react';
import { ArrowRight, Check, Loader2, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProposalController from '@/actions/App/Http/Controllers/Knowledge/ProposalController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index as library } from '@/routes/knowledge';
import { index } from '@/routes/knowledge/proposals';
import type { KnowledgeProposal } from '@/types';

const statusLabels: Record<KnowledgeProposal['status'], string> = {
    pending: 'Waiting for review',
    approved: 'Approved',
    rejected: 'Rejected',
    superseded: 'Replaced by a newer suggestion',
    conflict: 'Not applied: the item changed in the meantime',
};

type SuggestingStatus = {
    status: 'queued' | 'failed';
    error: string | null;
} | null;

export default function Proposals({
    pending,
    decided,
    isReviewer,
    suggesting,
}: {
    pending: KnowledgeProposal[];
    decided: KnowledgeProposal[];
    isReviewer: boolean;
    suggesting: SuggestingStatus;
}) {
    const searching = suggesting?.status === 'queued';
    const poll = usePoll(
        4000,
        { only: ['pending', 'suggesting'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (searching) {
            poll.start();
        } else {
            poll.stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searching]);

    const suggestions = pending.filter((p) => p.suggestion !== null);
    const changes = pending.filter((p) => p.suggestion === null);

    return (
        <>
            <Head title="Suggested changes" />

            <div className="flex flex-col gap-8 p-4 lg:max-w-3xl">
                <Heading
                    title="Suggested changes"
                    description={
                        isReviewer
                            ? 'Changes your VAs suggested to verified facts, and achievements the AI thinks your bullet points are missing. Nothing changes until you approve; approving counts as your confirmation.'
                            : 'Changes you suggested to verified facts, and what the candidate decided.'
                    }
                />

                {isReviewer && (
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Heading
                                variant="small"
                                title={`Achievements to add (${suggestions.length})`}
                                description="Bullet points that say what you did but not what came of it. Answer the question, and the bullet becomes an achievement."
                            />
                            <Form
                                {...ProposalController.suggestAchievements.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={processing || searching}
                                        >
                                            {searching ? (
                                                <Loader2 className="animate-spin" />
                                            ) : (
                                                <Sparkles />
                                            )}{' '}
                                            {searching
                                                ? 'Looking…'
                                                : 'Find achievements'}
                                        </Button>
                                        <InputError message={errors.suggest} />
                                    </>
                                )}
                            </Form>
                        </div>
                        {suggesting?.status === 'failed' && (
                            <p
                                role="alert"
                                className="rounded-kraken-sm border border-border-error bg-surface-error-subtle p-3 text-sm text-text-error"
                            >
                                {suggesting.error}
                            </p>
                        )}
                        {searching && (
                            <p
                                className="type-caption text-text-secondary"
                                aria-live="polite"
                            >
                                The AI is reading your bullet points. This page
                                updates by itself (background jobs need a queue
                                worker: php artisan queue:work).
                            </p>
                        )}
                        {suggestions.length === 0 && !searching ? (
                            <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                No suggestions waiting. They are also looked for
                                every Monday.
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {suggestions.map((proposal) => (
                                    <SuggestionCard
                                        key={proposal.ulid}
                                        proposal={proposal}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <section className="space-y-3">
                    <Heading
                        variant="small"
                        title={`Waiting for review (${changes.length})`}
                    />
                    {changes.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            Nothing to review.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {changes.map((proposal) => (
                                <ProposalCard
                                    key={proposal.ulid}
                                    proposal={proposal}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                {decided.length > 0 && (
                    <section className="space-y-3">
                        <Heading variant="small" title="Recently decided" />
                        <ul className="space-y-3">
                            {decided.map((proposal) => (
                                <ProposalCard
                                    key={proposal.ulid}
                                    proposal={proposal}
                                />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

/**
 * An AI achievement suggestion: answer the question by completing the draft (or writing your
 * own wording), then save it — or say it does not apply, and it is not asked again.
 */
function SuggestionCard({ proposal }: { proposal: KnowledgeProposal }) {
    const suggestion = proposal.suggestion!;
    const [statement, setStatement] = useState(suggestion.draft);
    const hasPlaceholders = /\[confirm:[^\]]*\]/i.test(statement);

    return (
        <li className="space-y-3 rounded-lg border p-4">
            <p className="font-medium">{suggestion.question}</p>
            <p className="text-sm text-text-secondary">
                <span className="type-caption text-text-muted">Now: </span>
                {suggestion.current}
            </p>
            {proposal.can.decide && (
                <div className="space-y-2">
                    <Form
                        {...ProposalController.approve.form(proposal.ulid)}
                        options={{ preserveScroll: true }}
                        transform={(data) => ({ ...data, statement })}
                        className="space-y-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor={`suggestion_${proposal.ulid}`}>
                                    Your bullet point, with the real details
                                </Label>
                                <Textarea
                                    id={`suggestion_${proposal.ulid}`}
                                    rows={3}
                                    maxLength={1000}
                                    value={statement}
                                    onChange={(event) =>
                                        setStatement(event.target.value)
                                    }
                                />
                                {hasPlaceholders && (
                                    <p className="type-caption text-text-secondary">
                                        Replace each [confirm: …] with the real
                                        figure or detail, or remove it.
                                    </p>
                                )}
                                <InputError
                                    message={
                                        errors.statement ?? errors.proposal
                                    }
                                />
                                <Button
                                    size="sm"
                                    disabled={processing || hasPlaceholders}
                                >
                                    <Check /> Save to my library
                                </Button>
                            </>
                        )}
                    </Form>
                    <Form
                        {...ProposalController.reject.form(proposal.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                            >
                                Doesn’t apply — don’t ask again
                            </Button>
                        )}
                    </Form>
                </div>
            )}
        </li>
    );
}

function ProposalCard({ proposal }: { proposal: KnowledgeProposal }) {
    return (
        <li className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">{proposal.target_label}</p>
                    <p className="text-xs text-muted-foreground">
                        Suggested by {proposal.proposed_by ?? 'someone'}
                        {proposal.created_at &&
                            ` on ${new Date(proposal.created_at).toLocaleDateString()}`}
                    </p>
                </div>
                <Badge
                    variant={
                        proposal.status === 'pending' ? 'default' : 'outline'
                    }
                >
                    {statusLabels[proposal.status]}
                </Badge>
            </div>

            {proposal.changes.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No differences from the current version.
                </p>
            ) : (
                <dl className="space-y-2 text-sm">
                    {proposal.changes.map((change) => (
                        <div
                            key={change.label}
                            className="grid gap-1 sm:grid-cols-[9rem_1fr]"
                        >
                            <dt className="text-muted-foreground">
                                {change.label}
                            </dt>
                            <dd className="flex flex-wrap items-center gap-2">
                                <span className="rounded-kraken-sm bg-surface-error-subtle px-1.5 py-0.5 line-through decoration-border-error/60">
                                    {change.from ?? '(empty)'}
                                </span>
                                <ArrowRight
                                    className="size-3.5 text-muted-foreground"
                                    aria-label="becomes"
                                />
                                <span className="rounded-kraken-sm bg-surface-accent-subtle px-1.5 py-0.5">
                                    {change.to ?? '(empty)'}
                                </span>
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {proposal.rationale && (
                <p className="rounded-md bg-muted/50 p-2 text-sm">
                    <span className="font-medium">Why: </span>
                    {proposal.rationale}
                </p>
            )}

            {proposal.decision_note && (
                <p className="text-sm">
                    <span className="font-medium">
                        Note from {proposal.decided_by ?? 'the candidate'}:{' '}
                    </span>
                    {proposal.decision_note}
                </p>
            )}

            {proposal.can.decide && (
                <div className="flex flex-wrap items-start gap-2 border-t pt-3">
                    <Form
                        {...ProposalController.approve.form(proposal.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <Button size="sm" disabled={processing}>
                                    Approve
                                </Button>
                                <InputError
                                    message={
                                        errors.proposal ?? errors.statement
                                    }
                                />
                            </>
                        )}
                    </Form>
                    <Form
                        {...ProposalController.reject.form(proposal.ulid)}
                        options={{ preserveScroll: true }}
                        className="flex min-w-64 flex-1 gap-2"
                    >
                        {({ processing }) => (
                            <>
                                <Input
                                    name="decision_note"
                                    placeholder="Note for the VA (optional)"
                                    aria-label="Note for the VA"
                                    maxLength={1000}
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Reject
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            )}
        </li>
    );
}

Proposals.layout = {
    breadcrumbs: [
        { title: 'Library', href: library() },
        { title: 'Suggested changes', href: index() },
    ],
};
