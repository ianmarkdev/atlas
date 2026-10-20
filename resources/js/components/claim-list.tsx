import { Form, Link } from '@inertiajs/react';
import { ListPlus, Plus } from 'lucide-react';
import ClaimController from '@/actions/App/Http/Controllers/Knowledge/ClaimController';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import { PendingChangeBadge } from '@/components/pending-changes';
import { Button } from '@/components/ui/button';
import type { Claim } from '@/types';

/**
 * Claims about one subject (`employment:{ulid}`, `project:{ulid}` or `general`), with the
 * actions the signed-in user may take, plus links to add one or several claims.
 */
export function ClaimList({
    claims,
    subject,
}: {
    claims: Claim[];
    subject: string;
}) {
    return (
        <div className="space-y-2">
            {claims.length > 0 && (
                <ul className="space-y-1.5">
                    {claims.map((claim) => (
                        <ClaimRow key={claim.ulid} claim={claim} />
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                <Link
                    href={ClaimController.create({ query: { subject } })}
                    className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                >
                    <Plus className="size-3.5" /> Add bullet point
                </Link>
                <Link
                    href={ClaimController.bulkCreate({ query: { subject } })}
                    className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                >
                    <ListPlus className="size-3.5" /> Paste a list
                </Link>
            </div>
        </div>
    );
}

function ClaimRow({ claim }: { claim: Claim }) {
    const muted = claim.status === 'archived' || claim.status === 'rejected';

    return (
        <li className="flex flex-col gap-1 rounded-md px-2 py-1.5 hover:bg-muted/50 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 gap-2">
                <span
                    aria-hidden
                    className="mt-2 size-1.5 shrink-0 rounded-full bg-foreground/60"
                />
                <div className="min-w-0">
                    <p
                        className={
                            muted
                                ? 'text-sm text-muted-foreground line-through'
                                : 'text-sm'
                        }
                    >
                        {claim.statement}
                    </p>
                    <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        <span className="capitalize">{claim.kind}</span>
                        <PendingChangeBadge itemKey={`claim:${claim.ulid}`} />
                        {claim.status !== 'verified' && (
                            <KnowledgeStatusBadge status={claim.status} />
                        )}
                    </p>
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap gap-1 sm:ml-2">
                {claim.can.verify && (
                    <ClaimAction
                        form={ClaimController.verify.form(claim.ulid)}
                        label="Verify"
                        variant="default"
                    />
                )}
                {claim.can.reject && (
                    <ClaimAction
                        form={ClaimController.reject.form(claim.ulid)}
                        label="Reject"
                    />
                )}
                {claim.can.update && (
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={ClaimController.edit(claim.ulid)}>
                            Edit
                        </Link>
                    </Button>
                )}
                {claim.can.propose && (
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={ClaimController.edit(claim.ulid)}>
                            Suggest change
                        </Link>
                    </Button>
                )}
                {claim.can.archive && (
                    <ClaimAction
                        form={ClaimController.archive.form(claim.ulid)}
                        label="Archive"
                    />
                )}
                {claim.can.restore && (
                    <ClaimAction
                        form={ClaimController.restore.form(claim.ulid)}
                        label="Restore"
                        variant="outline"
                    />
                )}
            </div>
        </li>
    );
}

function ClaimAction({
    form,
    label,
    variant = 'ghost',
}: {
    form: ReturnType<typeof ClaimController.verify.form>;
    label: string;
    variant?: 'default' | 'ghost' | 'outline';
}) {
    return (
        <Form {...form} options={{ preserveScroll: true }}>
            {({ processing, errors }) => (
                <>
                    <Button size="sm" variant={variant} disabled={processing}>
                        {label}
                    </Button>
                    {errors.statement && (
                        <p role="alert" className="text-xs text-destructive">
                            {errors.statement}
                        </p>
                    )}
                </>
            )}
        </Form>
    );
}
