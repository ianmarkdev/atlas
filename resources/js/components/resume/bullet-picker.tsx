import { useHttp } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import ClaimController from '@/actions/App/Http/Controllers/Knowledge/ClaimController';
import ResumeController from '@/actions/App/Http/Controllers/Profile/ResumeController';
import InputError from '@/components/input-error';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import { PasteListDialog } from '@/components/resume/add-dialogs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { moveItem } from '@/lib/resume-document';
import type { Claim, ResumeBullet } from '@/types';

/**
 * Bullet points of one experience or project in one resume: include/exclude and order the
 * subject's claims, reword them for this resume only, or add new ones (saved to the library).
 */
export function BulletPicker({
    subject,
    claims,
    bullets,
    onChange,
    onClaimsAdded,
    onClaimUpdated,
    usedElsewhere,
    resumeUlid,
    canEdit,
}: {
    subject: string;
    claims: Claim[];
    bullets: ResumeBullet[];
    onChange: (bullets: ResumeBullet[]) => void;
    onClaimsAdded: (claims: Claim[], include: boolean) => void;
    onClaimUpdated: (claim: Claim) => void;
    usedElsewhere: Record<string, string[]>;
    resumeUlid: string;
    canEdit: boolean;
}) {
    const byUlid = new Map(claims.map((claim) => [claim.ulid, claim]));
    const included = bullets.filter((bullet) => byUlid.has(bullet.claim));
    const includedUlids = new Set(included.map((bullet) => bullet.claim));
    const notIncluded = claims.filter(
        (claim) => !includedUlids.has(claim.ulid),
    );

    const update = (index: number, bullet: ResumeBullet) =>
        onChange(included.map((b, i) => (i === index ? bullet : b)));

    return (
        <div className="space-y-2">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Bullet points
            </p>

            {included.length === 0 && notIncluded.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No bullet points yet.
                </p>
            )}

            <ul className="space-y-1">
                {included.map((bullet, index) => (
                    <IncludedBullet
                        key={bullet.claim}
                        bullet={bullet}
                        claim={byUlid.get(bullet.claim)!}
                        isFirst={index === 0}
                        isLast={index === included.length - 1}
                        onToggle={() =>
                            onChange(included.filter((_, i) => i !== index))
                        }
                        onMove={(delta) =>
                            onChange(moveItem(included, index, delta))
                        }
                        onReword={(text) => update(index, { ...bullet, text })}
                        onClaimUpdated={onClaimUpdated}
                        resumeUlid={resumeUlid}
                        canEdit={canEdit}
                    />
                ))}
                {notIncluded.map((claim) => (
                    <li
                        key={claim.ulid}
                        className="flex items-start gap-2 rounded-md px-1 py-1"
                    >
                        <Checkbox
                            className="mt-0.5"
                            checked={false}
                            disabled={!canEdit}
                            onCheckedChange={() =>
                                onChange([
                                    ...included,
                                    { claim: claim.ulid, text: null },
                                ])
                            }
                            aria-label={`Include: ${claim.statement}`}
                        />
                        <div className="min-w-0 text-sm text-muted-foreground">
                            {claim.statement}
                            {usedElsewhere[claim.ulid] && (
                                <span className="ml-2 text-xs">
                                    (in {usedElsewhere[claim.ulid].join(', ')})
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>

            {canEdit && (
                <AddBullet
                    subject={subject}
                    onAdded={(claim) => onClaimsAdded([claim], true)}
                    onPasted={(added) => onClaimsAdded(added, true)}
                />
            )}
        </div>
    );
}

function IncludedBullet({
    bullet,
    claim,
    isFirst,
    isLast,
    onToggle,
    onMove,
    onReword,
    onClaimUpdated,
    resumeUlid,
    canEdit,
}: {
    bullet: ResumeBullet;
    claim: Claim;
    isFirst: boolean;
    isLast: boolean;
    onToggle: () => void;
    onMove: (delta: number) => void;
    onReword: (text: string | null) => void;
    onClaimUpdated: (claim: Claim) => void;
    resumeUlid: string;
    canEdit: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(bullet.text ?? claim.statement);
    const apply = useHttp<{ text: string }, { claim: Claim }>({ text: '' });
    const isReworded = bullet.text !== null;

    const saveRewording = () => {
        const text = draft.trim();
        onReword(text === '' || text === claim.statement ? null : text);
        setEditing(false);
    };

    const applyEverywhere = async () => {
        apply.transform(() => ({ text: bullet.text ?? '' }));

        try {
            const response = await apply.post(
                ResumeController.applyBullet.url([resumeUlid, claim.ulid]),
            );
            onClaimUpdated(response.claim);
            onReword(null);
        } catch {
            // Validation errors are shown below via apply.errors.
        }
    };

    return (
        <li className="rounded-md bg-muted/40 px-1 py-1">
            <div className="flex items-start gap-2">
                <Checkbox
                    className="mt-0.5"
                    checked
                    disabled={!canEdit}
                    onCheckedChange={onToggle}
                    aria-label={`Leave out: ${claim.statement}`}
                />
                <div className="min-w-0 flex-1">
                    {editing ? (
                        <div className="space-y-2">
                            <Textarea
                                value={draft}
                                rows={2}
                                maxLength={1000}
                                onChange={(event) =>
                                    setDraft(event.target.value)
                                }
                                aria-label="Wording for this resume"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={saveRewording}
                                >
                                    Use this wording
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setEditing(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm">
                            {bullet.text ?? claim.statement}
                            {isReworded && (
                                <Badge variant="outline" className="ml-2">
                                    Reworded
                                </Badge>
                            )}
                            {claim.status !== 'verified' && (
                                <span className="ml-2">
                                    <KnowledgeStatusBadge
                                        status={claim.status}
                                    />
                                </span>
                            )}
                        </p>
                    )}
                    {isReworded && !editing && (
                        <p className="text-xs text-muted-foreground">
                            Shared wording: {claim.statement}
                        </p>
                    )}
                    <InputError message={apply.errors.text} />
                </div>

                {canEdit && !editing && (
                    <div className="flex shrink-0 items-center">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            disabled={isFirst}
                            onClick={() => onMove(-1)}
                            aria-label="Move up"
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            disabled={isLast}
                            onClick={() => onMove(1)}
                            aria-label="Move down"
                        >
                            <ArrowDown />
                        </Button>
                    </div>
                )}
            </div>

            {canEdit && !editing && (
                <div className="mt-1 flex flex-wrap gap-1 pl-6">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setDraft(bullet.text ?? claim.statement);
                            setEditing(true);
                        }}
                    >
                        Reword for this resume
                    </Button>
                    {isReworded && (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => onReword(null)}
                            >
                                Revert to shared wording
                            </Button>
                            {claim.can.update && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    disabled={apply.processing}
                                    onClick={applyEverywhere}
                                >
                                    Apply everywhere
                                </Button>
                            )}
                        </>
                    )}
                </div>
            )}
        </li>
    );
}

type ClaimForm = {
    subject: string;
    kind: string;
    statement: string;
    allow_near_duplicate: boolean;
};

function AddBullet({
    subject,
    onAdded,
    onPasted,
}: {
    subject: string;
    onAdded: (claim: Claim) => void;
    onPasted: (claims: Claim[]) => void;
}) {
    const form = useHttp<ClaimForm, { claim: Claim }>({
        subject,
        kind: 'achievement',
        statement: '',
        allow_near_duplicate: false,
    });
    const nearDuplicate = Boolean(
        (form.errors as Record<string, string | undefined>).near_duplicate,
    );

    const submit = async (allowNearDuplicate: boolean) => {
        form.transform((data) => ({
            ...data,
            allow_near_duplicate: allowNearDuplicate,
        }));

        try {
            const response = await form.post(ClaimController.store.url());
            onAdded(response.claim);
            form.reset('statement');
            form.clearErrors();
        } catch {
            // Validation errors are shown below.
        }
    };

    return (
        <div className="space-y-1 pt-1">
            <form
                className="flex gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit(false);
                }}
            >
                <Input
                    value={form.data.statement}
                    onChange={(event) =>
                        form.setData('statement', event.target.value)
                    }
                    placeholder="Add a bullet point, e.g. “Cut the invoice run from 4 h to 20 min”"
                    aria-label="New bullet point"
                    maxLength={1000}
                />
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={
                        form.processing || form.data.statement.trim() === ''
                    }
                >
                    Add
                </Button>
                <PasteListDialog subject={subject} onAdded={onPasted} />
            </form>
            <InputError message={form.errors.statement} />
            {nearDuplicate && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => void submit(true)}
                >
                    It is a different bullet point — add anyway
                </Button>
            )}
        </div>
    );
}
