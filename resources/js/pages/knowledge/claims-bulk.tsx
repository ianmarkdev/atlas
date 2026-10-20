import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ClaimController from '@/actions/App/Http/Controllers/Knowledge/ClaimController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/knowledge';
import type { SelectOption } from '@/types';

/**
 * Mirrors the server's split (BulkStoreClaimsRequest::statementLines) closely enough for a preview:
 * one claim per non-empty line, leading list markers removed.
 */
function splitLines(text: string): string[] {
    return text
        .split(/\r?\n/)
        .map((line) => line.replace(/^\s*(?:[-*•·–]|\d+[.)])\s+/u, '').trim())
        .filter((line) => line !== '');
}

export default function ClaimsBulk({
    defaultSubject,
    maxLines,
    kinds,
    subjects,
}: {
    defaultSubject: string;
    maxLines: number;
    kinds: SelectOption[];
    subjects: SelectOption[];
}) {
    const [text, setText] = useState('');
    const lines = splitLines(text);

    return (
        <>
            <Head title="Paste a list of bullet points" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-3xl">
                <Heading
                    title="Paste a list of bullet points"
                    description="Paste a list, one bullet point per line. Bullet characters are removed. Exact duplicates are skipped. Nothing is changed by AI."
                />

                <Form
                    {...ClaimController.bulkStore.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="subject">About</Label>
                                    <NativeSelect
                                        id="subject"
                                        name="subject"
                                        options={subjects}
                                        defaultValue={defaultSubject}
                                    />
                                    <InputError message={errors.subject} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="kind">
                                        Kind (for all lines)
                                    </Label>
                                    <NativeSelect
                                        id="kind"
                                        name="kind"
                                        options={kinds}
                                        defaultValue="achievement"
                                    />
                                    <InputError message={errors.kind} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="statements">Claims</Label>
                                <Textarea
                                    id="statements"
                                    name="statements"
                                    rows={10}
                                    value={text}
                                    onChange={(event) =>
                                        setText(event.target.value)
                                    }
                                    placeholder={
                                        '- Led a team of four developers\n- Migrated billing from cron jobs to queued jobs'
                                    }
                                    required
                                />
                                <InputError message={errors.statements} />
                            </div>

                            <div className="space-y-2" aria-live="polite">
                                <p className="text-sm font-medium">
                                    Preview: {lines.length}{' '}
                                    {lines.length === 1 ? 'claim' : 'claims'}
                                    {lines.length > maxLines &&
                                        ` (at most ${maxLines} at a time)`}
                                </p>
                                {lines.length > 0 && (
                                    <ol className="list-decimal space-y-1 rounded-md border p-3 pl-8 text-sm">
                                        {lines
                                            .slice(0, maxLines)
                                            .map((line, i) => (
                                                <li key={i}>{line}</li>
                                            ))}
                                    </ol>
                                )}
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    disabled={
                                        processing ||
                                        lines.length === 0 ||
                                        lines.length > maxLines
                                    }
                                >
                                    Add {lines.length}{' '}
                                    {lines.length === 1 ? 'claim' : 'claims'}
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

ClaimsBulk.layout = {
    breadcrumbs: [
        { title: 'Library', href: index() },
        { title: 'Paste a list of bullet points', href: index() },
    ],
};
