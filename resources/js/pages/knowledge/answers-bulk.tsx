import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AnswerLibraryController from '@/actions/App/Http/Controllers/Knowledge/AnswerLibraryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/knowledge/answers';
import type { SelectOption } from '@/types';

/**
 * Mirrors BulkStoreAnswersRequest::parsedPairs() for the preview: blocks separated by a `---`
 * line; first line of a block = question, the rest = answer.
 */
function parsePairs(text: string): { question: string; body: string }[] {
    return text
        .replace(/\r\n?/g, '\n')
        .split(/^\s*-{3,}\s*$/m)
        .map((block) => {
            const [question = '', ...rest] = block.trim().split('\n');

            return { question: question.trim(), body: rest.join('\n').trim() };
        })
        .filter((pair) => pair.question !== '');
}

const example = `Why do you want to work here?
Because…

---

What is your notice period?
One month.`;

export default function AnswersBulk({
    categories,
    maxPairs,
}: {
    categories: SelectOption[];
    maxPairs: number;
}) {
    const [text, setText] = useState('');
    const pairs = parsePairs(text);
    const incomplete = pairs.filter((pair) => pair.body === '').length;

    return (
        <>
            <Head title="Paste a list of answers" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-3xl">
                <Heading
                    title="Paste a list of answers"
                    description="Separate each question/answer pair with a line containing only ---. The first line of each pair is the question; the rest is your answer. Nothing is changed by AI."
                />

                <Form
                    {...AnswerLibraryController.bulkStore.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid max-w-xs gap-2">
                                <Label htmlFor="category">
                                    Category (for all)
                                </Label>
                                <NativeSelect
                                    id="category"
                                    name="category"
                                    options={categories}
                                    defaultValue="other"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="pairs">
                                    Questions and answers
                                </Label>
                                <Textarea
                                    id="pairs"
                                    name="pairs"
                                    rows={14}
                                    value={text}
                                    onChange={(event) =>
                                        setText(event.target.value)
                                    }
                                    placeholder={example}
                                    required
                                />
                                <InputError message={errors.pairs} />
                            </div>

                            <div className="space-y-2" aria-live="polite">
                                <p className="text-sm font-medium">
                                    Preview: {pairs.length}{' '}
                                    {pairs.length === 1 ? 'answer' : 'answers'}
                                    {incomplete > 0 &&
                                        ` (${incomplete} without an answer)`}
                                    {pairs.length > maxPairs &&
                                        ` (at most ${maxPairs} at a time)`}
                                </p>
                                {pairs.length > 0 && (
                                    <ol className="list-decimal space-y-2 rounded-md border p-3 pl-8 text-sm">
                                        {pairs
                                            .slice(0, maxPairs)
                                            .map((pair, i) => (
                                                <li key={i}>
                                                    <p className="font-medium">
                                                        {pair.question}
                                                    </p>
                                                    <p
                                                        className={
                                                            pair.body
                                                                ? 'whitespace-pre-line text-muted-foreground'
                                                                : 'text-destructive'
                                                        }
                                                    >
                                                        {pair.body ||
                                                            'No answer yet'}
                                                    </p>
                                                </li>
                                            ))}
                                    </ol>
                                )}
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    disabled={
                                        processing ||
                                        pairs.length === 0 ||
                                        incomplete > 0 ||
                                        pairs.length > maxPairs
                                    }
                                >
                                    Add {pairs.length}{' '}
                                    {pairs.length === 1 ? 'answer' : 'answers'}
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

AnswersBulk.layout = {
    breadcrumbs: [
        { title: 'Answers', href: index() },
        { title: 'Paste a list', href: index() },
    ],
};
