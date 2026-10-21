import { Form, Head, router } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useState } from 'react';
import SearchPlaygroundController from '@/actions/App/Http/Controllers/Knowledge/SearchPlaygroundController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { index as libraryIndex } from '@/routes/knowledge';
import { search as searchRoute } from '@/routes/knowledge';

type Hit = {
    id: number;
    unit_type: string;
    status: string;
    text: string;
    score: number;
    rrf: number;
    semantic_rank: number | null;
    similarity: number | null;
    lexical_rank: number | null;
};

type IndexState = {
    units: number;
    active: number;
    embedded: number;
    read_model: string | null;
    configured: string;
};

const unitLabels: Record<string, string> = {
    claim: 'Bullet point',
    library_answer: 'Library answer',
    project_summary: 'Project',
    employment_summary: 'Experience',
};

export default function SearchPlayground({
    q,
    result,
    index,
}: {
    q: string;
    result: { semantic: boolean; reason: string | null; hits: Hit[] } | null;
    index: IndexState;
}) {
    const [text, setText] = useState(q);
    const coverage =
        index.active === 0
            ? 0
            : Math.round((index.embedded / index.active) * 100);

    return (
        <>
            <Head title="Search playground" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-4xl lg:p-6">
                <Heading
                    title="Search playground"
                    description="Try what the AI will retrieve from your library for a requirement or question. Keyword and meaning-based matches are combined, then verified, specific and recent facts are preferred."
                />

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <CardTitle>Index</CardTitle>
                            <Form
                                {...SearchPlaygroundController.rebuild.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <RefreshCw /> Rebuild index
                                    </Button>
                                )}
                            </Form>
                        </div>
                        <CardDescription>
                            {index.active} searchable items ({index.units}{' '}
                            indexed) · {index.embedded} embedded ({coverage}%) ·
                            reading{' '}
                            {index.read_model ?? 'no embedding model yet'} ·
                            configured {index.configured}
                        </CardDescription>
                    </CardHeader>
                </Card>

                <form
                    role="search"
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            searchRoute.url(),
                            text.trim() === '' ? {} : { q: text.trim() },
                            { preserveState: true },
                        );
                    }}
                >
                    <div className="relative flex-1">
                        <Search
                            className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            type="search"
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            placeholder="e.g. Experience scaling background job processing"
                            aria-label="Search your library"
                            className="pl-9"
                        />
                    </div>
                    <Button type="submit">Search</Button>
                </form>

                {result !== null && (
                    <section className="space-y-3" aria-live="polite">
                        <p className="type-caption text-text-muted">
                            {result.semantic
                                ? 'Hybrid: keyword + meaning-based.'
                                : `Keyword only. ${result.reason ?? ''}`}
                        </p>
                        {result.hits.length === 0 ? (
                            <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                                Nothing found.
                            </p>
                        ) : (
                            <ol className="space-y-2">
                                {result.hits.map((hit, i) => (
                                    <li
                                        key={hit.id}
                                        className="space-y-2 rounded-kraken-sm border border-border-subtle bg-card p-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="type-label text-text-label tabular-nums">
                                                #{i + 1}
                                            </span>
                                            <Badge variant="outline">
                                                {unitLabels[hit.unit_type] ??
                                                    hit.unit_type}
                                            </Badge>
                                            {hit.status !== 'verified' &&
                                                hit.status !== 'approved' && (
                                                    <Badge variant="secondary">
                                                        {hit.status}
                                                    </Badge>
                                                )}
                                        </div>
                                        <p className="text-sm whitespace-pre-line text-text-primary">
                                            {hit.text}
                                        </p>
                                        <p className="type-caption text-text-subtle tabular-nums">
                                            score {hit.score} · RRF {hit.rrf} ·
                                            meaning{' '}
                                            {hit.semantic_rank === null
                                                ? '—'
                                                : `#${hit.semantic_rank} (${hit.similarity})`}{' '}
                                            · keyword{' '}
                                            {hit.lexical_rank === null
                                                ? '—'
                                                : `#${hit.lexical_rank}`}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

SearchPlayground.layout = {
    breadcrumbs: [
        { title: 'Library', href: libraryIndex() },
        { title: 'Search playground', href: searchRoute() },
    ],
};
