import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowRight, Check, Loader2, Pencil, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProposalController from '@/actions/App/Http/Controllers/Knowledge/ProposalController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index as library } from '@/routes/knowledge';
import { index as questions } from '@/routes/applications/questions';
import { index } from '@/routes/knowledge/proposals';
import type { DiffPart, KnowledgeProposal, SelectOption } from '@/types';

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
    subjects,
    draftTypes,
}: {
    pending: KnowledgeProposal[];
    decided: KnowledgeProposal[];
    isReviewer: boolean;
    suggesting: SuggestingStatus;
    subjects: SelectOption[];
    draftTypes: SelectOption[];
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
                            ? 'Changes your VAs suggested, what the AI learned from edited answers, and achievements your bullet points are missing. Nothing changes until you approve; approving counts as your confirmation.'
                            : 'Changes you suggested and what the candidate decided, plus writing habits learned from your own edits, for you to approve.'
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
                                    subjects={subjects}
                                    draftTypes={draftTypes}
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
                                    subjects={subjects}
                                    draftTypes={draftTypes}
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

function ProposalCard({
    proposal,
    subjects,
    draftTypes,
}: {
    proposal: KnowledgeProposal;
    subjects: SelectOption[];
    draftTypes: SelectOption[];
}) {
    const editable = proposal.editable;
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(editable?.value ?? '');
    const [subject, setSubject] = useState(editable?.subject ?? 'general');
    const [drafts, setDrafts] = useState(editable?.drafts ?? 'all');
    const [replaces, setReplaces] = useState('');
    const replaceable = proposal.related_rules.filter(
        (rule) => rule.can_replace,
    );

    return (
        <li className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">{proposal.target_label}</p>
                    <p className="text-xs text-muted-foreground">
                        {proposal.learned_from
                            ? 'Learned by the AI from an edit'
                            : `Suggested by ${proposal.proposed_by ?? 'someone'}`}
                        {proposal.created_at &&
                            ` on ${new Date(proposal.created_at).toLocaleDateString()}`}
                        {proposal.personal && ' · only for your own drafts'}
                    </p>
                    {proposal.learned_from && (
                        <p className="text-xs text-muted-foreground">
                            From the answer to “{proposal.learned_from.question}
                            ” for{' '}
                            <Link
                                href={questions(
                                    proposal.learned_from.application_ulid,
                                )}
                                className="underline underline-offset-2"
                            >
                                {proposal.learned_from.application}
                            </Link>
                        </p>
                    )}
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
                <dl className="space-y-3 text-sm">
                    {proposal.changes.map((change) => (
                        <div
                            key={change.label}
                            className="grid gap-1 sm:grid-cols-[9rem_1fr]"
                        >
                            <dt className="text-muted-foreground">
                                {change.label}
                            </dt>
                            <dd className="space-y-1">
                                {change.diff ? (
                                    <TextDiff parts={change.diff} />
                                ) : change.from === null ? (
                                    <span className="rounded-kraken-sm bg-surface-accent-subtle px-1.5 py-0.5 whitespace-pre-line">
                                        {change.to ?? '(empty)'}
                                    </span>
                                ) : (
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-kraken-sm bg-surface-error-subtle px-1.5 py-0.5 line-through decoration-border-error/60">
                                            {change.from}
                                        </span>
                                        <ArrowRight
                                            className="size-3.5 text-muted-foreground"
                                            aria-label="becomes"
                                        />
                                        <span className="rounded-kraken-sm bg-surface-accent-subtle px-1.5 py-0.5">
                                            {change.to ?? '(empty)'}
                                        </span>
                                    </span>
                                )}
                                {change.base !== null && (
                                    <p className="type-caption text-text-secondary">
                                        Changed since this was suggested. It was
                                        based on: “{change.base}”
                                    </p>
                                )}
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

            {proposal.can.decide && proposal.related_rules.length > 0 && (
                <div className="space-y-2 text-sm">
                    <p className="font-medium">
                        Similar rules already in place
                    </p>
                    <ul className="space-y-1">
                        {proposal.related_rules.map((rule) => (
                            <li key={rule.ulid} className="flex flex-wrap gap-2">
                                <Badge variant="outline">{rule.kind_label}</Badge>
                                <span>{rule.rule}</span>
                                {rule.drafts_label && (
                                    <span className="type-caption text-text-secondary">
                                        {rule.drafts_label.toLowerCase()}
                                    </span>
                                )}
                                {rule.suggested && (
                                    <span className="type-caption text-destructive">
                                        the AI thinks the new rule contradicts
                                        this one
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                    {replaceable.length > 0 && (
                        <div className="grid gap-1 sm:max-w-md">
                            <Label htmlFor={`replaces_${proposal.ulid}`}>
                                When approving
                            </Label>
                            <NativeSelect
                                id={`replaces_${proposal.ulid}`}
                                options={[
                                    { value: '', label: 'Keep all rules' },
                                    ...replaceable.map((rule) => ({
                                        value: rule.ulid,
                                        label: `Replace “${rule.rule}”`,
                                    })),
                                ]}
                                value={replaces}
                                onChange={(event) =>
                                    setReplaces(event.target.value)
                                }
                            />
                            <p className="type-caption text-text-secondary">
                                To merge two rules, edit the wording to cover
                                both and replace the old one.
                            </p>
                        </div>
                    )}
                </div>
            )}

            {proposal.can.decide && (
                <div className="space-y-3 border-t pt-3">
                    {editable && editing && (
                        <div className="space-y-2">
                            <Label htmlFor={`edit_${proposal.ulid}`}>
                                {editLabels[editable.field]}
                            </Label>
                            <Textarea
                                id={`edit_${proposal.ulid}`}
                                rows={editable.field === 'body' ? 8 : 3}
                                value={value}
                                onChange={(event) =>
                                    setValue(event.target.value)
                                }
                            />
                            {editable.subject !== null && (
                                <>
                                    <Label htmlFor={`subject_${proposal.ulid}`}>
                                        Where did this happen?
                                    </Label>
                                    <NativeSelect
                                        id={`subject_${proposal.ulid}`}
                                        options={subjects}
                                        value={subject}
                                        onChange={(event) =>
                                            setSubject(event.target.value)
                                        }
                                    />
                                </>
                            )}
                            {editable.drafts !== null && (
                                <>
                                    <Label htmlFor={`drafts_${proposal.ulid}`}>
                                        Used for
                                    </Label>
                                    <NativeSelect
                                        id={`drafts_${proposal.ulid}`}
                                        options={draftTypes}
                                        value={drafts}
                                        onChange={(event) =>
                                            setDrafts(event.target.value)
                                        }
                                    />
                                </>
                            )}
                        </div>
                    )}
                    <div className="flex flex-wrap items-start gap-2">
                        <Form
                            {...ProposalController.approve.form(proposal.ulid)}
                            options={{ preserveScroll: true }}
                            transform={(data) => ({
                                ...(editable && editing
                                    ? {
                                          ...data,
                                          [editable.field]: value,
                                          ...(editable.subject !== null
                                              ? { subject }
                                              : {}),
                                          ...(editable.drafts !== null
                                              ? { drafts }
                                              : {}),
                                      }
                                    : data),
                                ...(replaces !== '' ? { replaces } : {}),
                            })}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Button size="sm" disabled={processing}>
                                        {editing
                                            ? 'Approve my version'
                                            : 'Approve'}
                                    </Button>
                                    <InputError
                                        message={
                                            errors.proposal ??
                                            errors.statement ??
                                            errors.body ??
                                            errors.rule ??
                                            errors.subject ??
                                            errors.replaces ??
                                            errors.lock_version
                                        }
                                    />
                                </>
                            )}
                        </Form>
                        {editable && !editing && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil /> Edit, then approve
                            </Button>
                        )}
                        <Form
                            {...ProposalController.reject.form(proposal.ulid)}
                            options={{ preserveScroll: true }}
                            className="flex min-w-64 flex-1 gap-2"
                        >
                            {({ processing }) => (
                                <>
                                    <Input
                                        name="decision_note"
                                        placeholder="Note (optional)"
                                        aria-label="Note about the rejection"
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
                </div>
            )}
        </li>
    );
}

const editLabels: Record<
    NonNullable<KnowledgeProposal['editable']>['field'],
    string
> = {
    statement: 'Your bullet point',
    body: 'Your answer',
    rule: 'Your rule',
};

/**
 * A word diff: removed words struck through, added words underlined, so the change does not
 * rely on colour alone.
 */
function TextDiff({ parts }: { parts: DiffPart[] }) {
    return (
        <p className="whitespace-pre-line">
            {parts.map((part, index) =>
                part.op === 'equal' ? (
                    <span key={index}>{part.text}</span>
                ) : part.op === 'delete' ? (
                    <del
                        key={index}
                        className="rounded-kraken-sm bg-surface-error-subtle px-0.5 decoration-border-error/60"
                    >
                        <span className="sr-only">Removed: </span>
                        {part.text}
                    </del>
                ) : (
                    <ins
                        key={index}
                        className="rounded-kraken-sm bg-surface-accent-subtle px-0.5 underline-offset-2"
                    >
                        <span className="sr-only">Added: </span>
                        {part.text}
                    </ins>
                ),
            )}
        </p>
    );
}

Proposals.layout = {
    breadcrumbs: [
        { title: 'Library', href: library() },
        { title: 'Suggested changes', href: index() },
    ],
};
