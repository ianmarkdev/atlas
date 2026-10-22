import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, FlaskConical } from 'lucide-react';
import { useState } from 'react';
import JdCollectionController from '@/actions/App/Http/Controllers/Applications/JdCollectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as applicationsIndex } from '@/routes/applications';
import { index } from '@/routes/collections';

type Collection = {
    ulid: string;
    name: string;
    description: string | null;
    use_for_eval: boolean;
    applications: number;
};

export default function Collections({
    collections,
    canMarkEval,
}: {
    collections: Collection[];
    canMarkEval: boolean;
}) {
    return (
        <>
            <Head title="Collections" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-3xl lg:p-6">
                <Link
                    href={applicationsIndex()}
                    className="inline-flex items-center gap-1.5 self-start text-sm text-text-secondary focus-ring hover:text-text-primary"
                >
                    <ArrowLeft className="size-4" aria-hidden /> Applications
                </Link>

                <Heading
                    title="Collections"
                    description="Group applications, e.g. “Fintech roles”. Mark a collection as an evaluation set: its job descriptions are re-analysed with php artisan app:eval-analysis whenever a prompt or model changes, so you can see whether quality went up or down."
                />

                <CollectionForm canMarkEval={canMarkEval} />

                {collections.length === 0 ? (
                    <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                        No collections yet. Add applications to a collection
                        from their page.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {collections.map((collection) => (
                            <CollectionRow
                                key={collection.ulid}
                                collection={collection}
                                canMarkEval={canMarkEval}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function CollectionForm({
    collection,
    canMarkEval,
    onDone,
}: {
    collection?: Collection;
    canMarkEval: boolean;
    onDone?: () => void;
}) {
    const [useForEval, setUseForEval] = useState(
        collection?.use_for_eval ?? false,
    );
    const id = collection?.ulid ?? 'new';

    return (
        <Form
            {...(collection
                ? JdCollectionController.update.form(collection.ulid)
                : JdCollectionController.store.form())}
            options={{ preserveScroll: true }}
            transform={(data) => ({
                ...data,
                ...(canMarkEval ? { use_for_eval: useForEval } : {}),
            })}
            resetOnSuccess={!collection}
            onSuccess={onDone}
            className="space-y-3 rounded-kraken-md border border-border-subtle p-4"
        >
            {({ processing, errors }) => (
                <>
                    {!collection && (
                        <p className="type-label text-text-label uppercase">
                            New collection
                        </p>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}_name`}>Name</Label>
                            <Input
                                id={`${id}_name`}
                                name="name"
                                defaultValue={collection?.name ?? ''}
                                maxLength={120}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}_description`}>
                                Description (optional)
                            </Label>
                            <Input
                                id={`${id}_description`}
                                name="description"
                                defaultValue={collection?.description ?? ''}
                                maxLength={1000}
                            />
                        </div>
                    </div>
                    {canMarkEval && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={useForEval}
                                onCheckedChange={(checked) =>
                                    setUseForEval(checked === true)
                                }
                            />
                            Use as an evaluation set for job analysis quality
                        </label>
                    )}
                    <div className="flex gap-2">
                        <Button size="sm" disabled={processing}>
                            {collection ? 'Save' : 'Add collection'}
                        </Button>
                        {onDone && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={onDone}
                            >
                                Cancel
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

function CollectionRow({
    collection,
    canMarkEval,
}: {
    collection: Collection;
    canMarkEval: boolean;
}) {
    const [editing, setEditing] = useState(false);

    if (editing) {
        return (
            <li>
                <CollectionForm
                    collection={collection}
                    canMarkEval={canMarkEval}
                    onDone={() => setEditing(false)}
                />
            </li>
        );
    }

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 rounded-kraken-md border border-border-subtle bg-card p-4">
            <div className="min-w-0">
                <p className="flex flex-wrap items-center gap-2 font-medium text-text-primary">
                    {collection.name}
                    {collection.use_for_eval && (
                        <Badge variant="secondary">
                            <FlaskConical aria-hidden /> Evaluation set
                        </Badge>
                    )}
                </p>
                <p className="type-caption text-text-muted">
                    {collection.applications}{' '}
                    {collection.applications === 1
                        ? 'application'
                        : 'applications'}
                    {collection.description && ` · ${collection.description}`}
                </p>
            </div>
            <div className="flex gap-2">
                <Button size="sm" variant="outline" asChild>
                    <Link
                        href={applicationsIndex({
                            query: { collection: collection.ulid },
                        })}
                    >
                        View
                    </Link>
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setEditing(true)}
                >
                    Edit
                </Button>
                <Form
                    {...JdCollectionController.destroy.form(collection.ulid)}
                    options={{ preserveScroll: true }}
                    onBefore={() =>
                        window.confirm(
                            `Delete the collection “${collection.name}”? The applications stay.`,
                        )
                    }
                >
                    {({ processing }) => (
                        <Button size="sm" variant="ghost" disabled={processing}>
                            Delete
                        </Button>
                    )}
                </Form>
            </div>
        </li>
    );
}

Collections.layout = {
    breadcrumbs: [
        { title: 'Applications', href: applicationsIndex() },
        { title: 'Collections', href: index() },
    ],
};
