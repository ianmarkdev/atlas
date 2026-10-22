import { Form, Head, Link, router } from '@inertiajs/react';
import { Check, Copy, ListPlus, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import AnswerLibraryController from '@/actions/App/Http/Controllers/Knowledge/AnswerLibraryController';
import Heading from '@/components/heading';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { wordCount } from '@/lib/resume-document';
import { copyToClipboard } from '@/lib/clipboard';
import { index } from '@/routes/knowledge/answers';
import type { Answer, SelectOption } from '@/types';

type Filters = { q: string; category: string | null; archived: boolean };

export default function Answers({
    answers,
    filters,
    categories,
}: {
    answers: Answer[];
    filters: Filters;
    categories: SelectOption[];
}) {
    const [search, setSearch] = useState(filters.q);
    const categoryLabel = new Map(categories.map((c) => [c.value, c.label]));

    const applyFilters = (next: Partial<Filters>) => {
        const merged = { ...filters, q: search, ...next };
        router.get(
            index.url(),
            {
                ...(merged.q ? { q: merged.q } : {}),
                ...(merged.category ? { category: merged.category } : {}),
                ...(merged.archived ? { archived: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Answers" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-4xl">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Answers"
                        description="Standard application questions with your answers, ready to copy into application forms."
                    />
                    <div className="flex gap-2">
                        <Button size="sm" asChild>
                            <Link href={AnswerLibraryController.create()}>
                                <Plus /> Add answer
                            </Link>
                        </Button>
                        <Button size="sm" variant="outline" asChild>
                            <Link href={AnswerLibraryController.bulkCreate()}>
                                <ListPlus /> Paste a list
                            </Link>
                        </Button>
                    </div>
                </div>

                <form
                    className="flex flex-wrap gap-2"
                    role="search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        applyFilters({});
                    }}
                >
                    <div className="relative min-w-56 flex-1">
                        <Search
                            className="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search questions and answers"
                            aria-label="Search questions and answers"
                            className="pl-8"
                        />
                    </div>
                    <NativeSelect
                        className="w-56"
                        value={filters.category ?? ''}
                        onChange={(event) =>
                            applyFilters({
                                category: event.target.value || null,
                            })
                        }
                        options={categories}
                        placeholder="All categories"
                        aria-label="Category"
                    />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </form>

                {answers.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                        {filters.q || filters.category
                            ? 'No answers match.'
                            : 'No answers yet. Start with the questions you are asked most, e.g. “Why do you want to work here?”'}
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {answers.map((answer) => (
                            <AnswerCard
                                key={answer.ulid}
                                answer={answer}
                                category={
                                    categoryLabel.get(answer.category) ??
                                    answer.category
                                }
                            />
                        ))}
                    </ul>
                )}

                <button
                    type="button"
                    onClick={() =>
                        applyFilters({ archived: !filters.archived })
                    }
                    className="self-start text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    {filters.archived
                        ? 'Show current answers'
                        : 'Show archived answers'}
                </button>
            </div>
        </>
    );
}

function AnswerCard({
    answer,
    category,
}: {
    answer: Answer;
    category: string;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        const isCopied = await copyToClipboard(answer.body);
        setCopied(isCopied);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <li className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium">{answer.question}</p>
                    <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        {category} · {wordCount(answer.body)} words
                        {answer.claims.length > 0 &&
                            ` · backed by ${answer.claims.length} bullet point${answer.claims.length === 1 ? '' : 's'}`}
                        {answer.status === 'draft' && <Badge>Draft</Badge>}
                        {answer.status === 'archived' && (
                            <Badge variant="outline">Archived</Badge>
                        )}
                    </p>
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={copy}
                >
                    {copied ? <Check /> : <Copy />} {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>

            <p className="text-sm whitespace-pre-line">{answer.body}</p>

            <div className="flex flex-wrap gap-2 border-t pt-3">
                {answer.can.approve && (
                    <Form
                        {...AnswerLibraryController.approve.form(answer.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button size="sm" disabled={processing}>
                                Approve
                            </Button>
                        )}
                    </Form>
                )}
                {(answer.can.update || answer.can.propose) && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={AnswerLibraryController.edit(answer.ulid)}>
                            {answer.can.update ? 'Edit' : 'Suggest change'}
                        </Link>
                    </Button>
                )}
                {answer.can.archive && (
                    <Form
                        {...AnswerLibraryController.archive.form(answer.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                            >
                                Archive
                            </Button>
                        )}
                    </Form>
                )}
                {answer.can.restore && (
                    <Form
                        {...AnswerLibraryController.restore.form(answer.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                Restore
                            </Button>
                        )}
                    </Form>
                )}
            </div>
        </li>
    );
}

Answers.layout = {
    breadcrumbs: [{ title: 'Answers', href: index() }],
};
