import { Form, Head, Link } from '@inertiajs/react';
import ClaimController from '@/actions/App/Http/Controllers/Knowledge/ClaimController';
import Heading from '@/components/heading';
import { ProposingNotice } from '@/components/proposing-notice';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/knowledge';
import { VersionHistory } from '@/components/version-history';
import type { Claim, SelectOption, TextRevision } from '@/types';

export default function ClaimForm({
    claim,
    subjectLabel,
    defaultSubject,
    kinds,
    subjects,
    proposing = false,
    history = [],
}: {
    proposing?: boolean;
    history?: TextRevision[];
    claim: Claim | null;
    subjectLabel: string | null;
    defaultSubject: string | null;
    kinds: SelectOption[];
    subjects: SelectOption[];
}) {
    const isEditing = claim !== null;

    return (
        <>
            <Head
                title={isEditing ? 'Edit bullet point' : 'Add bullet point'}
            />

            <div className="flex flex-col gap-6 p-4 lg:max-w-2xl">
                <Heading
                    title={isEditing ? 'Edit bullet point' : 'Add bullet point'}
                    description="One achievement or responsibility per bullet point, in plain text, e.g. “Cut the nightly invoice run from 4 hours to 20 minutes.” Add numbers only if you can stand behind them."
                />
                {history.length > 0 && (
                    <div className="-mt-4">
                        <VersionHistory revisions={history} />
                    </div>
                )}

                <Form
                    {...(isEditing
                        ? ClaimController.update.form(claim.ulid)
                        : ClaimController.store.form())}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.lock_version && (
                                <p
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                                >
                                    {errors.lock_version}
                                </p>
                            )}
                            {isEditing && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={claim.lock_version}
                                />
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="subject">About</Label>
                                {isEditing ? (
                                    <p
                                        id="subject"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {subjectLabel}
                                    </p>
                                ) : (
                                    <NativeSelect
                                        id="subject"
                                        name="subject"
                                        options={subjects}
                                        defaultValue={
                                            defaultSubject ?? 'general'
                                        }
                                    />
                                )}
                                <InputError message={errors.subject} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="kind">Kind</Label>
                                <NativeSelect
                                    id="kind"
                                    name="kind"
                                    options={kinds}
                                    defaultValue={claim?.kind ?? 'achievement'}
                                />
                                <InputError message={errors.kind} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="statement">Statement</Label>
                                <Textarea
                                    id="statement"
                                    name="statement"
                                    rows={3}
                                    maxLength={1000}
                                    defaultValue={claim?.statement ?? ''}
                                    required
                                />
                                <InputError message={errors.statement} />
                            </div>

                            {errors.near_duplicate && (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="allow_near_duplicate"
                                        name="allow_near_duplicate"
                                        value="1"
                                    />
                                    <Label htmlFor="allow_near_duplicate">
                                        It is a different bullet point — save
                                        anyway
                                    </Label>
                                </div>
                            )}

                            {isEditing && (
                                <div className="grid gap-2">
                                    <Label htmlFor="change_reason">
                                        Reason for the change (optional)
                                    </Label>
                                    <Input
                                        id="change_reason"
                                        name="change_reason"
                                        placeholder="e.g. corrected the team size"
                                    />
                                    <InputError
                                        message={errors.change_reason}
                                    />
                                </div>
                            )}

                            {proposing && (
                                <ProposingNotice error={errors.rationale} />
                            )}

                            <div className="flex gap-2">
                                <Button disabled={processing}>
                                    {proposing ? 'Send suggestion' : 'Save'}
                                </Button>
                                <Button variant="ghost" type="button" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ClaimForm.layout = {
    breadcrumbs: [
        { title: 'Library', href: index() },
        { title: 'Bullet point', href: index() },
    ],
};
