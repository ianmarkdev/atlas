import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AnswerLibraryController from '@/actions/App/Http/Controllers/Knowledge/AnswerLibraryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { ProposingNotice } from '@/components/proposing-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { wordCount } from '@/lib/resume-document';
import { index } from '@/routes/knowledge/answers';
import type { Answer, EvidenceGroup, SelectOption } from '@/types';

export default function AnswerForm({
    answer,
    proposing,
    categories,
    evidence,
}: {
    answer: Answer | null;
    proposing: boolean;
    categories: SelectOption[];
    evidence: EvidenceGroup[];
}) {
    const isEditing = answer !== null;
    const [body, setBody] = useState(answer?.body ?? '');
    const [claims, setClaims] = useState<string[]>(answer?.claims ?? []);

    const toggleClaim = (ulid: string) =>
        setClaims((current) =>
            current.includes(ulid)
                ? current.filter((u) => u !== ulid)
                : [...current, ulid],
        );

    return (
        <>
            <Head title={isEditing ? 'Edit answer' : 'Add answer'} />

            <div className="flex flex-col gap-6 p-4 lg:max-w-3xl">
                <Heading
                    title={isEditing ? 'Edit answer' : 'Add answer'}
                    description="Your own wording, in plain text. Every save keeps the previous version."
                />

                <Form
                    {...(isEditing
                        ? AnswerLibraryController.update.form(answer.ulid)
                        : AnswerLibraryController.store.form())}
                    transform={(data) => ({ ...data, claims })}
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
                                    value={answer.lock_version}
                                />
                            )}

                            <div className="grid gap-4 sm:grid-cols-[1fr_14rem]">
                                <div className="grid gap-2">
                                    <Label htmlFor="question">Question</Label>
                                    <Input
                                        id="question"
                                        name="question"
                                        defaultValue={answer?.question ?? ''}
                                        placeholder="e.g. Why do you want to work here?"
                                        required
                                        maxLength={500}
                                    />
                                    <InputError message={errors.question} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="category">Category</Label>
                                    <NativeSelect
                                        id="category"
                                        name="category"
                                        options={categories}
                                        defaultValue={
                                            answer?.category ?? 'motivation'
                                        }
                                    />
                                    <InputError message={errors.category} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="body">Answer</Label>
                                <Textarea
                                    id="body"
                                    name="body"
                                    rows={10}
                                    value={body}
                                    onChange={(event) =>
                                        setBody(event.target.value)
                                    }
                                    required
                                    maxLength={5000}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {wordCount(body)} words · {body.length}{' '}
                                    characters
                                </p>
                                <InputError message={errors.body} />
                            </div>

                            {evidence.length > 0 && (
                                <details
                                    className="rounded-md border p-3"
                                    open={claims.length > 0}
                                >
                                    <summary className="cursor-pointer text-sm font-medium">
                                        Backed by bullet points (optional) —{' '}
                                        {claims.length} selected
                                    </summary>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Tick the facts this answer relies on.
                                        Later, AI-written answers use them as
                                        evidence.
                                    </p>
                                    <div className="mt-3 max-h-80 space-y-4 overflow-y-auto">
                                        {evidence.map((group) => (
                                            <fieldset
                                                key={group.label}
                                                className="space-y-1"
                                            >
                                                <legend className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                    {group.label}
                                                </legend>
                                                {group.claims.map((claim) => (
                                                    <label
                                                        key={claim.ulid}
                                                        className="flex items-start gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            className="mt-0.5"
                                                            checked={claims.includes(
                                                                claim.ulid,
                                                            )}
                                                            onCheckedChange={() =>
                                                                toggleClaim(
                                                                    claim.ulid,
                                                                )
                                                            }
                                                        />
                                                        {claim.statement}
                                                    </label>
                                                ))}
                                            </fieldset>
                                        ))}
                                    </div>
                                    <InputError message={errors.claims} />
                                </details>
                            )}

                            {isEditing && !proposing && (
                                <div className="grid gap-2">
                                    <Label htmlFor="change_reason">
                                        Reason for the change (optional)
                                    </Label>
                                    <Input
                                        id="change_reason"
                                        name="change_reason"
                                        maxLength={255}
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

AnswerForm.layout = {
    breadcrumbs: [
        { title: 'Answers', href: index() },
        { title: 'Answer', href: index() },
    ],
};
