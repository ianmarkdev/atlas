import { Form, Head, Link } from '@inertiajs/react';
import { Sparkles, X } from 'lucide-react';
import WritingPreferenceController from '@/actions/App/Http/Controllers/Knowledge/WritingPreferenceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as library } from '@/routes/knowledge';
import { index } from '@/routes/knowledge/preferences';
import { index as proposals } from '@/routes/knowledge/proposals';
import type { SelectOption } from '@/types';

type Preference = {
    ulid: string;
    rule: string;
    kind: string;
    kind_label: string;
    learned: boolean;
    can: { archive: boolean };
};

export default function WritingStyle({
    shared,
    personal,
    suggested,
    kinds,
    isCandidate,
}: {
    shared: Preference[];
    personal: Preference[];
    suggested: number;
    kinds: SelectOption[];
    isCandidate: boolean;
}) {
    return (
        <>
            <Head title="Writing style" />

            <div className="flex flex-col gap-8 p-4 lg:max-w-3xl">
                <Heading
                    title="Writing style"
                    description="Rules every AI draft follows: spelling, tone, words to avoid, format. Rules are also learned from how answers get edited; those wait under Suggestions until approved."
                />

                {suggested > 0 && (
                    <p className="rounded-kraken-sm border p-3 text-sm">
                        <Sparkles className="mr-1 inline size-4" />
                        {suggested === 1
                            ? '1 rule is waiting for approval under '
                            : `${suggested} rules are waiting for approval under `}
                        <Link
                            href={proposals()}
                            className="underline underline-offset-2"
                        >
                            Suggestions
                        </Link>
                        .
                    </p>
                )}

                <Form
                    {...WritingPreferenceController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['rule']}
                    className="space-y-3 rounded-lg border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2 sm:grid-cols-[1fr_12rem]">
                                <div className="grid gap-2">
                                    <Label htmlFor="rule">New rule</Label>
                                    <Input
                                        id="rule"
                                        name="rule"
                                        maxLength={200}
                                        placeholder="e.g. Use British spelling"
                                        required
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="kind">Kind</Label>
                                    <NativeSelect
                                        id="kind"
                                        name="kind"
                                        options={kinds}
                                        defaultValue="tone"
                                    />
                                </div>
                            </div>
                            <InputError message={errors.rule ?? errors.kind} />
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="personal"
                                    name="personal"
                                    value="1"
                                />
                                <Label htmlFor="personal">
                                    Only for drafts I ask for
                                </Label>
                            </div>
                            {!isCandidate && (
                                <p className="type-caption text-text-secondary">
                                    A rule for every draft is suggested to the
                                    candidate first.
                                </p>
                            )}
                            <Button size="sm" disabled={processing}>
                                Add rule
                            </Button>
                        </>
                    )}
                </Form>

                <RuleList
                    title="For every draft"
                    empty="No rules yet."
                    preferences={shared}
                />
                <RuleList
                    title="Only for your drafts"
                    empty="No personal rules."
                    preferences={personal}
                />
            </div>
        </>
    );
}

function RuleList({
    title,
    empty,
    preferences,
}: {
    title: string;
    empty: string;
    preferences: Preference[];
}) {
    return (
        <section className="space-y-3">
            <Heading
                variant="small"
                title={`${title} (${preferences.length})`}
            />
            {preferences.length === 0 ? (
                <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {preferences.map((preference) => (
                        <li
                            key={preference.ulid}
                            className="flex items-center gap-3 p-3"
                        >
                            <Badge variant="outline">
                                {preference.kind_label}
                            </Badge>
                            <span className="flex-1 text-sm">
                                {preference.rule}
                                {preference.learned && (
                                    <span className="ml-2 type-caption text-text-secondary">
                                        learned from edits
                                    </span>
                                )}
                            </span>
                            {preference.can.archive && (
                                <Form
                                    {...WritingPreferenceController.archive.form(
                                        preference.ulid,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            disabled={processing}
                                            aria-label={`Remove rule: ${preference.rule}`}
                                        >
                                            <X />
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

WritingStyle.layout = {
    breadcrumbs: [
        { title: 'Library', href: library() },
        { title: 'Writing style', href: index() },
    ],
};
