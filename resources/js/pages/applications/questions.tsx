import { Form, Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    BookmarkPlus,
    Check,
    CheckCircle2,
    Copy,
    Library,
    Lightbulb,
    ListChecks,
    ListPlus,
    Loader2,
    Mail,
    Pencil,
    RotateCcw,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import ApplicationQuestionController from '@/actions/App/Http/Controllers/Applications/ApplicationQuestionController';
import ClaimReviewController from '@/actions/App/Http/Controllers/Applications/ClaimReviewController';
import JobAnalysisController from '@/actions/App/Http/Controllers/Applications/JobAnalysisController';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import { JobDescriptionSheet } from '@/components/applications/job-description-sheet';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { VersionHistory } from '@/components/version-history';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { lengthStatus, parseQuestions } from '@/lib/questions';
import { cn } from '@/lib/utils';
import { companyLabel } from '@/lib/applications';
import { copyToClipboard } from '@/lib/clipboard';
import { index as applicationsIndex } from '@/routes/applications';
import { index as proposals } from '@/routes/knowledge/proposals';
import type { SelectOption, TextRevision } from '@/types';

type Answer = {
    body: string;
    status: 'draft' | 'approved';
    lock_version: number;
    version_no: number | null;
    from_library: string | null;
    library_entry: string | null;
    approved_at: string | null;
    approved_by: string | null;
    generation: {
        status: 'queued' | 'running' | 'checking' | 'failed';
        error: string | null;
        requested_at: string | null;
        /** 1-based paragraph being redrafted; null for the whole answer. */
        paragraph: number | null;
    } | null;
    ai: AiCheck | null;
    /** Stock phrases and repeated paragraph openings in the saved text (StyleLint). */
    style: StyleFinding[];
    /** The latest edit of an AI draft the AI learned from (M10). */
    feedback: {
        status: 'pending_analysis' | 'analysed' | 'failed';
        error: string | null;
        suggestions: number;
        pending: number;
    } | null;
};

type StyleFinding = {
    /** 0-based, as the server splits paragraphs. */
    paragraph: number;
    kind:
        | 'stock_phrase'
        | 'long_paragraph'
        | 'filler_opening'
        | 'repeated_opening';
    text: string;
    /** The instruction for redrafting that paragraph. */
    fix: string;
};

type ClaimLabel = 'verified' | 'inferred' | 'proposed' | 'hypothetical';

type Review = {
    decision: 'accepted' | 'added' | 'dismissed';
    claim_status: string | null;
};

type AiCheck = {
    evidence: { id: string; status: string; text: string }[];
    claims: {
        index: number;
        text: string;
        classification: ClaimLabel;
        model_classification: string;
        evidence_ids: string[];
        flags: string[];
        to_check: boolean;
        review: Review | null;
    }[];
    questions: {
        index: number;
        question: string;
        why: string;
        review: Review | null;
    }[];
    instruction: string | null;
    from_resume_version: number | null;
    to_check: number;
    needs_review: boolean;
    /** False until the separate claim check has labelled this draft (or when it failed). */
    labelled: boolean;
};

type Question = {
    ulid: string;
    prompt_text: string;
    origin: 'manual' | 'cover_letter' | 'extracted';
    word_limit: number | null;
    char_limit: number | null;
    answer: Answer | null;
    history: TextRevision[];
    can: { update: boolean; approve: boolean; reopen: boolean; learn: boolean };
};

type LibraryAnswer = {
    ulid: string;
    question: string;
    body: string;
    category: string;
    status: 'approved' | 'draft';
};

type PageProps = {
    application: {
        ulid: string;
        company_name: string | null;
        role_title: string;
        job_description: string | null;
    };
    questions: Question[];
    library: LibraryAnswer[];
    categories: SelectOption[];
    hasCoverLetter: boolean;
    canAdd: boolean;
    hasAnalysis: boolean;
    /** Questions the job analysis found that are not on the list yet. */
    found: {
        analysis: string;
        questions: { index: number; text: string }[];
    } | null;
    subjects: SelectOption[];
    maxQuestions: number;
};

const isGenerating = (question: Question): boolean =>
    question.answer?.generation?.status === 'queued' ||
    question.answer?.generation?.status === 'running';

/** An AI draft is written and its claims are being labelled. */
const isChecking = (question: Question): boolean =>
    question.answer?.generation?.status === 'checking';

/** An edit of an AI draft is being turned into suggestions (M10). */
const isLearning = (question: Question): boolean =>
    question.answer?.feedback?.status === 'pending_analysis';

export default function ApplicationQuestions({
    application,
    questions,
    library,
    categories,
    hasCoverLetter,
    canAdd,
    hasAnalysis,
    found,
    subjects,
    maxQuestions,
}: PageProps) {
    const answered = questions.filter(
        (q) => (q.answer?.body ?? '').trim() !== '',
    ).length;
    const approved = questions.filter(
        (q) => q.answer?.status === 'approved',
    ).length;
    const generating = questions.some(isGenerating);
    const learning = questions.some(isLearning);
    const checking = questions.some(isChecking);
    // Same rule as the server: no text yet, not approved, no draft on the way.
    const unanswered = questions.filter(
        (q) =>
            (q.answer?.body ?? '').trim() === '' &&
            q.answer?.status !== 'approved' &&
            !isGenerating(q),
    ).length;
    const [drafting, setDrafting] = useState(false);
    // The sidebar's suggestion count changes when learning finishes.
    const poll = usePoll(
        3000,
        { only: ['questions', 'pendingProposals'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (generating || learning || checking) {
            poll.start();
        } else {
            poll.stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [generating, learning, checking]);

    return (
        <>
            <Head
                title={`Questions · ${application.role_title} at ${companyLabel(application.company_name)}`}
            />

            <div className="flex flex-col gap-6 p-4 lg:max-w-4xl lg:p-6">
                <Link
                    href={JobApplicationController.show(application.ulid)}
                    className="inline-flex items-center gap-1.5 self-start text-sm text-text-secondary focus-ring hover:text-text-primary"
                >
                    <ArrowLeft className="size-4" aria-hidden />
                    {application.role_title} ·{' '}
                    {companyLabel(application.company_name)}
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Questions & answers"
                        description={
                            questions.length === 0
                                ? 'Add the questions from the application form, and the cover letter if they ask for one.'
                                : `${questions.length} ${questions.length === 1 ? 'question' : 'questions'} · ${answered} answered · ${approved} approved`
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        <JobDescriptionSheet
                            text={application.job_description}
                        />
                        {answered > 0 && (
                            <CopyAllButton questions={questions} />
                        )}
                        {canAdd && unanswered > 0 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={drafting}
                                onClick={() => {
                                    if (
                                        !window.confirm(
                                            `Draft ${unanswered} ${unanswered === 1 ? 'answer' : 'answers'} with AI? A local model writes them one after another, about 2–3 minutes each. You can keep working meanwhile.`,
                                        )
                                    ) {
                                        return;
                                    }

                                    router.post(
                                        ApplicationQuestionController.draftAll.url(
                                            application.ulid,
                                        ),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setDrafting(true),
                                            onFinish: () => setDrafting(false),
                                        },
                                    );
                                }}
                            >
                                <Sparkles /> Draft {unanswered} with AI
                            </Button>
                        )}
                        {canAdd && !hasCoverLetter && (
                            <Form
                                {...ApplicationQuestionController.coverLetter.form(
                                    application.ulid,
                                )}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <Mail /> Add cover letter
                                    </Button>
                                )}
                            </Form>
                        )}
                        {canAdd && (
                            <PasteQuestionsDialog
                                application={application.ulid}
                                maxQuestions={maxQuestions}
                            />
                        )}
                    </div>
                </div>

                {questions.length > 0 && canAdd && !hasAnalysis && (
                    <p className="rounded-kraken-sm border border-border-subtle bg-surface-tile p-3 type-caption text-text-secondary">
                        Tip: analyse the job description first.{' '}
                        <strong>Draft with AI</strong> then uses the facts
                        matched to each requirement, not just a search of your
                        profile.{' '}
                        <Link
                            href={JobApplicationController.show(
                                application.ulid,
                            )}
                            className="underline focus-ring"
                        >
                            Back to the application
                        </Link>
                    </p>
                )}

                {found && found.questions.length > 0 && (
                    <FoundQuestions
                        application={application.ulid}
                        analysis={found.analysis}
                        questions={found.questions}
                    />
                )}

                {questions.length > 0 && (
                    <ol className="space-y-4">
                        {questions.map((question, index) => (
                            <li
                                key={`${question.ulid}:${question.answer?.version_no ?? 0}:${question.answer?.status ?? 'none'}`}
                                id={question.ulid}
                            >
                                <QuestionCard
                                    application={application.ulid}
                                    question={question}
                                    number={index + 1}
                                    isFirst={index === 0}
                                    isLast={index === questions.length - 1}
                                    library={library}
                                    categories={categories}
                                    subjects={subjects}
                                />
                            </li>
                        ))}
                    </ol>
                )}

                {canAdd && <AddQuestionForm application={application.ulid} />}
            </div>
        </>
    );
}

/**
 * Every question with its saved answer, as plain text for pasting into a form or an email.
 */
/**
 * Questions the job analysis found, offered for confirmation: a job description can hold text
 * aimed at the AI, so nothing it "asks" becomes a question without a person adding it.
 */
function FoundQuestions({
    application,
    analysis,
    questions,
}: {
    application: string;
    analysis: string;
    questions: { index: number; text: string }[];
}) {
    const [selected, setSelected] = useState<number[]>(() =>
        questions.map((question) => question.index),
    );
    const [processing, setProcessing] = useState(false);

    return (
        <section className="space-y-3 rounded-lg border border-border-subtle bg-surface-tile p-4">
            <div>
                <h2 className="type-card-title text-text-primary">
                    The job description asks{' '}
                    {questions.length === 1
                        ? '1 question'
                        : `${questions.length} questions`}{' '}
                    not on this list yet
                </h2>
                <p className="type-caption text-text-secondary">
                    Found by the job analysis. Untick any that are not real
                    application questions, then add the rest. The AI cover
                    letter covers any you don&apos;t add.
                </p>
            </div>
            <ul className="space-y-2">
                {questions.map((question) => (
                    <li
                        key={question.index}
                        className="flex items-start gap-2 text-sm"
                    >
                        <Checkbox
                            id={`found_${question.index}`}
                            className="mt-0.5"
                            checked={selected.includes(question.index)}
                            onCheckedChange={() =>
                                setSelected((current) =>
                                    current.includes(question.index)
                                        ? current.filter(
                                              (n) => n !== question.index,
                                          )
                                        : [...current, question.index],
                                )
                            }
                        />
                        <Label
                            htmlFor={`found_${question.index}`}
                            className="font-normal"
                        >
                            {question.text}
                        </Label>
                    </li>
                ))}
            </ul>
            <Button
                size="sm"
                disabled={selected.length === 0 || processing}
                onClick={() =>
                    router.post(
                        JobAnalysisController.addQuestions.url([
                            application,
                            analysis,
                        ]),
                        { questions: selected },
                        {
                            preserveScroll: true,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                        },
                    )
                }
            >
                <ListPlus /> Add {selected.length}{' '}
                {selected.length === 1 ? 'question' : 'questions'}
            </Button>
        </section>
    );
}

function CopyAllButton({ questions }: { questions: Question[] }) {
    const [copied, setCopied] = useState(false);

    const copyAll = async () => {
        const text = questions
            .filter((q) => (q.answer?.body ?? '').trim() !== '')
            .map((q) =>
                q.origin === 'cover_letter'
                    ? `Cover letter\n\n${q.answer?.body ?? ''}`
                    : `${q.prompt_text}\n\n${q.answer?.body ?? ''}`,
            )
            .join('\n\n---\n\n');

        const isCopied = await copyToClipboard(text);
        setCopied(isCopied);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Button type="button" size="sm" variant="outline" onClick={copyAll}>
            {copied ? <Check /> : <Copy />} {copied ? 'Copied' : 'Copy all'}
        </Button>
    );
}

function AddQuestionForm({ application }: { application: string }) {
    return (
        <Form
            {...ApplicationQuestionController.store.form(application)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="space-y-3 rounded-kraken-md border border-dashed border-border-input p-4"
        >
            {({ processing, errors }) => (
                <>
                    <p className="type-label text-text-label uppercase">
                        Add a question
                    </p>
                    <div className="grid gap-2">
                        <Label htmlFor="new_prompt">Question</Label>
                        <Textarea
                            id="new_prompt"
                            name="prompt_text"
                            rows={2}
                            maxLength={2000}
                            placeholder="Copy it from the application form, e.g. “Why do you want to work at …?”"
                            required
                        />
                        <InputError message={errors.prompt_text} />
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <LimitFields idPrefix="new" errors={errors} />
                        <Button disabled={processing}>Add question</Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function LimitFields({
    idPrefix,
    errors,
    defaults,
}: {
    idPrefix: string;
    errors: Record<string, string>;
    defaults?: { word_limit: number | null; char_limit: number | null };
}) {
    return (
        <>
            <div className="grid w-36 gap-2">
                <Label htmlFor={`${idPrefix}_word_limit`}>Word limit</Label>
                <Input
                    id={`${idPrefix}_word_limit`}
                    name="word_limit"
                    type="number"
                    min={1}
                    inputMode="numeric"
                    defaultValue={defaults?.word_limit ?? ''}
                    placeholder="None"
                />
                <InputError message={errors.word_limit} />
            </div>
            <div className="grid w-36 gap-2">
                <Label htmlFor={`${idPrefix}_char_limit`}>
                    Character limit
                </Label>
                <Input
                    id={`${idPrefix}_char_limit`}
                    name="char_limit"
                    type="number"
                    min={1}
                    inputMode="numeric"
                    defaultValue={defaults?.char_limit ?? ''}
                    placeholder="None"
                />
                <InputError message={errors.char_limit} />
            </div>
        </>
    );
}

function PasteQuestionsDialog({
    application,
    maxQuestions,
}: {
    application: string;
    maxQuestions: number;
}) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const parsed = parseQuestions(text);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <ListPlus /> Paste questions
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Paste the form’s questions</DialogTitle>
                    <DialogDescription>
                        One question per line. Put a limit at the end if the
                        form has one, e.g. “Why us? (300 words)” or “(max 1,500
                        characters)”.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...ApplicationQuestionController.bulkStore.form(
                        application,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setText('');
                        setOpen(false);
                    }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Textarea
                                name="questions"
                                rows={8}
                                value={text}
                                onChange={(event) =>
                                    setText(event.target.value)
                                }
                                aria-label="Questions, one per line"
                                placeholder={
                                    'Why do you want to work here? (300 words)\nDescribe a project you are proud of (max 2000 characters)\nWhat is your notice period?'
                                }
                            />
                            <InputError message={errors.questions} />
                            {parsed.length > 0 && (
                                <ol className="max-h-56 list-decimal space-y-1 overflow-y-auto rounded-kraken-sm border border-border-subtle p-3 pl-8 text-sm">
                                    {parsed
                                        .slice(0, maxQuestions)
                                        .map((q, i) => (
                                            <li key={i}>
                                                {q.prompt}
                                                {(q.word_limit ??
                                                    q.char_limit) !== null && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                    >
                                                        {q.word_limit !== null
                                                            ? `${q.word_limit} words`
                                                            : `${q.char_limit} characters`}
                                                    </Badge>
                                                )}
                                            </li>
                                        ))}
                                </ol>
                            )}
                            <Button
                                disabled={
                                    processing ||
                                    parsed.length === 0 ||
                                    parsed.length > maxQuestions
                                }
                            >
                                Add {parsed.length}{' '}
                                {parsed.length === 1 ? 'question' : 'questions'}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function QuestionCard({
    application,
    question,
    number,
    isFirst,
    isLast,
    library,
    categories,
    subjects,
}: {
    application: string;
    question: Question;
    number: number;
    isFirst: boolean;
    isLast: boolean;
    library: LibraryAnswer[];
    categories: SelectOption[];
    subjects: SelectOption[];
}) {
    const saved = question.answer?.body ?? '';
    const [body, setBody] = useState(saved);
    const [libraryEntry, setLibraryEntry] = useState<string | null>(
        question.answer?.library_entry ?? null,
    );
    const [editingPrompt, setEditingPrompt] = useState(false);
    const [copied, setCopied] = useState(false);
    const [saving, setSaving] = useState(false);
    const errorBag = `question_${question.ulid}`;
    const allErrors = usePage().props.errors as unknown as Record<
        string,
        Record<string, string> | undefined
    >;
    const errors = allErrors[errorBag] ?? {};
    const approved = question.answer?.status === 'approved';
    const dirty = body !== saved;
    const length = lengthStatus(body, question);
    const routeArgs: [string, string] = [application, question.ulid];
    const generating = isGenerating(question);
    const generation = question.answer?.generation ?? null;
    const hasPlaceholders = /\[confirm:[^\]]*\]/i.test(body);

    const needsReview = question.answer?.ai?.needs_review ?? false;
    const style = question.answer?.style ?? [];

    const draftWithAi = (
        instruction: string | null,
        paragraph: number | null = null,
    ) => {
        if (
            dirty &&
            !window.confirm(
                'Your unsaved changes will be lost when the AI draft arrives. Continue?',
            )
        ) {
            return;
        }

        router.post(
            ApplicationQuestionController.draft.url(routeArgs),
            { instruction, paragraph },
            { preserveScroll: true, errorBag },
        );
    };

    const save = () => {
        setSaving(true);
        router.put(
            ApplicationQuestionController.saveAnswer.url(routeArgs),
            {
                body,
                library_entry: libraryEntry,
                lock_version: question.answer?.lock_version ?? 0,
            },
            {
                preserveScroll: true,
                errorBag,
                onFinish: () => setSaving(false),
            },
        );
    };

    const copy = async () => {
        const isCopied = await copyToClipboard(body);
        setCopied(isCopied);
        window.setTimeout(() => setCopied(false), 2000);
    };

    const move = (direction: 'up' | 'down') =>
        router.patch(
            ApplicationQuestionController.move.url(routeArgs),
            { direction },
            { preserveScroll: true, errorBag },
        );

    return (
        <article
            className={cn(
                'space-y-3 rounded-kraken-md border bg-card p-4',
                approved
                    ? 'border-border-accent-subtle'
                    : 'border-border-subtle',
            )}
        >
            <div className="flex items-start gap-3">
                <span className="mt-0.5 type-label text-text-label tabular-nums">
                    {question.origin === 'cover_letter' ? (
                        <Mail className="size-4" aria-label="Cover letter" />
                    ) : (
                        `${number}.`
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    {editingPrompt ? (
                        <EditPromptForm
                            question={question}
                            routeArgs={routeArgs}
                            errorBag={errorBag}
                            onDone={() => setEditingPrompt(false)}
                        />
                    ) : (
                        <>
                            <p className="font-medium whitespace-pre-line text-text-primary">
                                {question.prompt_text}
                            </p>
                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                {approved ? (
                                    <Badge>
                                        <CheckCircle2 /> Approved
                                    </Badge>
                                ) : saved.trim() !== '' ? (
                                    <Badge variant="outline">Draft</Badge>
                                ) : (
                                    <Badge variant="outline">
                                        Not answered
                                    </Badge>
                                )}
                                {question.answer?.from_library && (
                                    <span className="type-caption text-text-muted">
                                        From your library: “
                                        {question.answer.from_library}”
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                </div>
                {question.can.update && !editingPrompt && (
                    <div className="flex shrink-0 items-center">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            disabled={isFirst}
                            onClick={() => move('up')}
                            aria-label="Move question up"
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            disabled={isLast}
                            onClick={() => move('down')}
                            aria-label="Move question down"
                        >
                            <ArrowDown />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            onClick={() => setEditingPrompt(true)}
                            aria-label="Edit question"
                        >
                            <Pencil />
                        </Button>
                        <Form
                            {...ApplicationQuestionController.destroy.form(
                                routeArgs,
                            )}
                            options={{ preserveScroll: true }}
                            onBefore={() =>
                                window.confirm(
                                    'Delete this question and its answer?',
                                )
                            }
                        >
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="Delete question"
                            >
                                <Trash2 />
                            </Button>
                        </Form>
                    </div>
                )}
            </div>

            <div className="grid gap-1">
                <Textarea
                    value={body}
                    onChange={(event) => {
                        setBody(event.target.value);
                        setLibraryEntry(null);
                    }}
                    readOnly={approved || generating || !question.can.update}
                    rows={question.origin === 'cover_letter' ? 14 : 6}
                    aria-label={`Answer to: ${question.prompt_text}`}
                    placeholder="Write your answer, or insert one from your library."
                    maxLength={50000}
                />
                <p
                    className={cn(
                        'type-caption',
                        length.over
                            ? 'font-medium text-text-error'
                            : 'text-text-muted',
                    )}
                    aria-live="polite"
                >
                    {length.label}
                    {length.over && ' — over the limit'}
                    {dirty && ' · unsaved'}
                </p>
                {errors.body && (
                    <p role="alert" className="text-sm text-text-error">
                        {errors.body}
                    </p>
                )}
                {errors.lock_version && (
                    <p role="alert" className="text-sm text-text-error">
                        {errors.lock_version}
                    </p>
                )}
                {generating && (
                    <p
                        className="flex items-center gap-2 type-caption text-text-secondary"
                        aria-live="polite"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        {generation?.status === 'queued'
                            ? 'Waiting to start… (background jobs need a queue worker: php artisan queue:work)'
                            : generation?.paragraph
                              ? `Redrafting paragraph ${generation.paragraph}… the rest of the answer stays as it is.`
                              : 'Writing a draft from your evidence… with a local model this can take a minute or two.'}
                    </p>
                )}
                {generation?.status === 'checking' && (
                    <p
                        className="flex items-center gap-2 type-caption text-text-secondary"
                        aria-live="polite"
                    >
                        <Loader2 className="size-4 animate-spin" aria-hidden />
                        Draft ready. Labelling its claims against your facts…
                        you can read and edit it meanwhile; approving waits for
                        the labels.
                    </p>
                )}
                {generation?.status === 'failed' && (
                    <p role="alert" className="text-sm text-text-error">
                        The AI draft failed: {generation.error}
                    </p>
                )}
                {hasPlaceholders && !approved && (
                    <p className="flex items-center gap-2 type-caption text-text-secondary">
                        <AlertTriangle className="size-4" aria-hidden />
                        Replace each [confirm: …] with the real detail, or
                        remove it, before approving.
                    </p>
                )}
                {needsReview && !dirty && !approved && (
                    <p className="flex items-center gap-2 type-caption text-text-secondary">
                        <AlertTriangle className="size-4" aria-hidden />
                        {question.answer?.ai?.to_check}{' '}
                        {question.answer?.ai?.to_check === 1
                            ? 'claim is'
                            : 'claims are'}{' '}
                        not backed by a verified fact. Open “AI check” to review
                        them, or edit the text, before approving.
                    </p>
                )}
                {style.length > 0 && !dirty && !approved && (
                    <div className="flex flex-col gap-1 type-caption text-text-secondary">
                        <p className="flex items-center gap-2">
                            <AlertTriangle className="size-4" aria-hidden />
                            Wording a hiring manager may read as filler:
                        </p>
                        <ul className="flex flex-col gap-1 pl-6">
                            {style.map((finding) => (
                                <li
                                    key={`${finding.kind}-${finding.paragraph}-${finding.text}`}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span>
                                        Paragraph {finding.paragraph + 1}:{' '}
                                        {finding.kind === 'repeated_opening'
                                            ? `opens with “${finding.text}”, like an earlier paragraph`
                                            : finding.kind === 'filler_opening'
                                              ? `opens with “${finding.text}”`
                                              : finding.kind ===
                                                  'long_paragraph'
                                                ? `too long to skim (${finding.text})`
                                                : `“${finding.text}”`}
                                    </span>
                                    {question.can.update && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="h-6 px-2"
                                            onClick={() =>
                                                draftWithAi(
                                                    finding.fix,
                                                    finding.paragraph,
                                                )
                                            }
                                            disabled={generating}
                                        >
                                            <Sparkles /> Redraft paragraph
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {question.can.update && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            onClick={save}
                            disabled={!dirty || saving || generating}
                        >
                            Save
                        </Button>
                        {saved.trim() === '' ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => draftWithAi(null)}
                                disabled={generating}
                            >
                                <Sparkles /> Draft with AI
                            </Button>
                        ) : (
                            <RedraftDialog
                                disabled={generating}
                                paragraphs={paragraphsOf(saved)}
                                onSubmit={draftWithAi}
                            />
                        )}
                        <InsertFromLibrary
                            library={library}
                            onInsert={(entry) => {
                                setBody(entry.body);
                                setLibraryEntry(entry.ulid);
                            }}
                        />
                    </>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={copy}
                    disabled={body.trim() === ''}
                >
                    {copied ? <Check /> : <Copy />} {copied ? 'Copied' : 'Copy'}
                </Button>
                {question.can.update && saved.trim() !== '' && !dirty && (
                    <SaveToLibraryDialog
                        routeArgs={routeArgs}
                        categories={categories}
                        errorBag={errorBag}
                    />
                )}
                {question.answer?.ai && (
                    <AiCheckSheet
                        check={question.answer.ai}
                        body={saved}
                        routeArgs={routeArgs}
                        errorBag={errorBag}
                        canReview={question.can.approve && !approved}
                        canUpdate={question.can.update}
                        subjects={subjects}
                    />
                )}
                <VersionHistory revisions={question.history} />
                {question.can.learn && !dirty && (
                    <Form
                        {...ApplicationQuestionController.learn.form(routeArgs)}
                        options={{ preserveScroll: true }}
                        errorBag={errorBag}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                                title="Turn what you changed in the AI draft into suggestions for your library and writing style. Approving does this too."
                            >
                                <Lightbulb /> Learn from my edits
                            </Button>
                        )}
                    </Form>
                )}
                <div className="ml-auto flex gap-2">
                    {question.can.approve && saved.trim() !== '' && (
                        <Form
                            {...ApplicationQuestionController.approve.form(
                                routeArgs,
                            )}
                            options={{ preserveScroll: true }}
                            errorBag={errorBag}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        processing ||
                                        dirty ||
                                        generating ||
                                        hasPlaceholders ||
                                        needsReview
                                    }
                                    title={
                                        dirty
                                            ? 'Save the answer before approving'
                                            : hasPlaceholders
                                              ? 'Fill in the [confirm: …] placeholders first'
                                              : needsReview
                                                ? generation?.status ===
                                                  'checking'
                                                    ? 'The AI is still labelling this draft’s claims'
                                                    : 'Review the AI draft’s unconfirmed claims first'
                                                : undefined
                                    }
                                >
                                    <CheckCircle2 /> Approve
                                </Button>
                            )}
                        </Form>
                    )}
                    {question.can.reopen && (
                        <Form
                            {...ApplicationQuestionController.reopen.form(
                                routeArgs,
                            )}
                            options={{ preserveScroll: true }}
                            errorBag={errorBag}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={processing}
                                >
                                    <RotateCcw /> Reopen
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </div>
            {question.answer?.feedback && (
                <FeedbackStatus feedback={question.answer.feedback} />
            )}
        </article>
    );
}

/**
 * What the AI learned from the latest edit of its draft. Suggestions wait for approval.
 */
function FeedbackStatus({
    feedback,
}: {
    feedback: NonNullable<Answer['feedback']>;
}) {
    if (feedback.status === 'failed') {
        return (
            <p className="type-caption text-text-secondary">
                Learning from your edits failed: {feedback.error}
            </p>
        );
    }

    if (feedback.status === 'pending_analysis') {
        return (
            <p className="type-caption text-text-secondary" aria-live="polite">
                Learning from your edits… Suggestions appear under{' '}
                <Link href={proposals()} className="underline">
                    Suggestions
                </Link>
                .
            </p>
        );
    }

    return (
        <p className="type-caption text-text-secondary">
            <Lightbulb className="mr-1 inline size-3.5" />
            {feedback.suggestions === 0
                ? 'Nothing to learn from your edits beyond this answer.'
                : `${feedback.suggestions} suggestion${feedback.suggestions === 1 ? '' : 's'} learned from your edits${feedback.pending > 0 ? `, ${feedback.pending} waiting for approval under ` : '.'}`}
            {feedback.suggestions > 0 && feedback.pending > 0 && (
                <>
                    <Link href={proposals()} className="underline">
                        Suggestions
                    </Link>
                    .
                </>
            )}
        </p>
    );
}

const claimLabels: Record<ClaimLabel, { label: string; hint: string }> = {
    verified: {
        label: 'Verified',
        hint: 'Stated by a fact you confirmed',
    },
    inferred: {
        label: 'Inferred',
        hint: 'A fair conclusion from your facts — check the wording',
    },
    proposed: {
        label: 'Check this',
        hint: 'Not in your facts as written — confirm or change it',
    },
    hypothetical: {
        label: 'Not backed',
        hint: 'An approach or opinion, or a claim with no evidence — keep only if true',
    },
};

const flagLabels: Record<string, string> = {
    unknown_label: 'the AI used an unknown label',
    unknown_evidence: 'it cited evidence that was not supplied',
    downgraded_no_verified_evidence:
        'labelled verified, but no verified fact backs it',
    downgraded_no_evidence: 'labelled inferred, but it cites no evidence',
    metric_without_source: 'a number that is not in your facts',
};

const quickInstructions = [
    'Shorter',
    'More formal',
    'Warmer and more personal',
    'More specific about results',
    'Use more of the employer’s own words',
];

/**
 * The answer's paragraphs as the server splits them (on blank lines), for "redraft one paragraph".
 */
function paragraphsOf(body: string): string[] {
    return body
        .trim()
        .split(/\r?\n\s*\r?\n/)
        .map((part) => part.trim())
        .filter((part) => part !== '');
}

function RedraftDialog({
    disabled,
    paragraphs,
    onSubmit,
}: {
    disabled: boolean;
    paragraphs: string[];
    onSubmit: (instruction: string | null, paragraph: number | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [instruction, setInstruction] = useState('');
    const [part, setPart] = useState('whole');
    const partOptions = [
        { value: 'whole', label: 'The whole answer' },
        ...paragraphs.map((paragraph, index) => ({
            value: String(index),
            label: `Paragraph ${index + 1}: ${paragraph.length > 60 ? `${paragraph.slice(0, 60)}…` : paragraph}`,
        })),
    ];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={disabled}
                >
                    <Sparkles /> Redraft with AI
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Redraft with AI</DialogTitle>
                    <DialogDescription>
                        The new draft is added as a version; the current text
                        stays in the history. Redraft the whole answer or one
                        paragraph, and say what should change — or leave it
                        empty for a fresh attempt.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setOpen(false);
                        onSubmit(
                            instruction.trim() === ''
                                ? null
                                : instruction.trim(),
                            part === 'whole' ? null : Number(part),
                        );
                    }}
                >
                    {paragraphs.length > 1 && (
                        <>
                            <Label htmlFor="redraft_part">
                                What to redraft
                            </Label>
                            <NativeSelect
                                id="redraft_part"
                                options={partOptions}
                                value={part}
                                onChange={(event) =>
                                    setPart(event.target.value)
                                }
                            />
                            {part !== 'whole' && (
                                <p className="type-caption text-text-secondary">
                                    Only this paragraph changes. What you
                                    already checked in the others stays checked.
                                </p>
                            )}
                        </>
                    )}
                    <Label htmlFor="redraft_instruction">
                        Instruction (optional)
                    </Label>
                    <Textarea
                        id="redraft_instruction"
                        rows={3}
                        maxLength={500}
                        value={instruction}
                        onChange={(event) => setInstruction(event.target.value)}
                        placeholder="e.g. Shorter, and lead with the payments migration"
                    />
                    <div className="flex flex-wrap gap-2">
                        {quickInstructions.map((text) => (
                            <Button
                                key={text}
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setInstruction(text)}
                            >
                                {text}
                            </Button>
                        ))}
                    </div>
                    <Button>
                        <Sparkles /> Redraft
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The saved text with each claim the AI listed marked by its label (the label is written out,
 * so colour is not the only signal). Claims not found word for word are listed below only.
 */
function AnnotatedDraft({
    body,
    claims,
}: {
    body: string;
    claims: AiCheck['claims'];
}) {
    const lower = body.toLocaleLowerCase();
    const spans: { start: number; end: number; label: ClaimLabel }[] = [];

    for (const claim of claims) {
        const needle = claim.text.trim().toLocaleLowerCase();
        const start = needle === '' ? -1 : lower.indexOf(needle);

        if (
            start >= 0 &&
            !spans.some((s) => start < s.end && start + needle.length > s.start)
        ) {
            spans.push({
                start,
                end: start + needle.length,
                label: claim.classification,
            });
        }
    }

    spans.sort((a, b) => a.start - b.start);
    const parts: ReactNode[] = [];
    let cursor = 0;

    spans.forEach((span, i) => {
        parts.push(body.slice(cursor, span.start));
        parts.push(
            <mark
                key={i}
                className={cn(
                    'rounded-sm px-0.5 text-text-primary',
                    span.label === 'verified'
                        ? 'bg-surface-accent-subtle'
                        : span.label === 'inferred'
                          ? 'bg-surface-input-subtle'
                          : 'bg-surface-tile underline decoration-dotted underline-offset-2',
                )}
            >
                {body.slice(span.start, span.end)}
                <sup className="ml-0.5 type-caption text-text-secondary">
                    {claimLabels[span.label]?.label ?? span.label}
                </sup>
            </mark>,
        );
        cursor = span.end;
    });
    parts.push(body.slice(cursor));

    return (
        <p className="rounded-kraken-sm border border-border-subtle p-3 text-sm leading-relaxed whitespace-pre-line">
            {parts}
        </p>
    );
}

function ReviewState({ review }: { review: Review | null }) {
    if (review === null) {
        return null;
    }

    const text =
        review.decision === 'accepted'
            ? 'Kept as worded'
            : review.decision === 'dismissed'
              ? 'Skipped'
              : review.claim_status === 'verified'
                ? 'Added to your library'
                : 'Added to the library — waiting for the candidate to verify';

    return (
        <p className="flex items-center gap-1.5 type-caption text-text-secondary">
            <CheckCircle2 className="size-3.5" aria-hidden /> {text}
        </p>
    );
}

/**
 * Settle one claim (keep it, or save it as a fact) or answer one AI question.
 */
function ReviewActions({
    itemType,
    index,
    initialStatement,
    routeArgs,
    subjects,
    canAccept,
    canAdd,
}: {
    itemType: 'claim' | 'question';
    index: number;
    initialStatement: string;
    routeArgs: [string, string];
    subjects: SelectOption[];
    canAccept: boolean;
    canAdd: boolean;
}) {
    const [adding, setAdding] = useState(itemType === 'question');
    const errorBag = `review_${routeArgs[1]}_${itemType}_${index}`;

    return (
        <div className="space-y-2 pt-1">
            {adding && canAdd ? (
                <Form
                    {...ClaimReviewController.addToLibrary.form(routeArgs)}
                    options={{ preserveScroll: true }}
                    errorBag={errorBag}
                    transform={(data) => ({
                        ...data,
                        item_type: itemType,
                        index,
                    })}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Textarea
                                name="statement"
                                rows={2}
                                maxLength={1000}
                                defaultValue={initialStatement}
                                required
                                aria-label={
                                    itemType === 'claim'
                                        ? 'The fact, as a bullet point'
                                        : 'Your answer, as a bullet point'
                                }
                                placeholder="e.g. Cut the nightly invoice run from 4 hours to 20 minutes"
                            />
                            <InputError
                                message={
                                    errors.statement ??
                                    errors.subject ??
                                    errors.review
                                }
                            />
                            <div className="flex flex-wrap items-center gap-2">
                                <NativeSelect
                                    name="subject"
                                    options={subjects}
                                    defaultValue="general"
                                    aria-label="Where this happened"
                                    className="h-9 w-auto max-w-xs"
                                />
                                <Button size="sm" disabled={processing}>
                                    Add to library
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : null}
            <div className="flex flex-wrap gap-2">
                {canAccept && (
                    <Form
                        {...ClaimReviewController.accept.form(routeArgs)}
                        options={{ preserveScroll: true }}
                        errorBag={errorBag}
                        transform={(data) => ({ ...data, index })}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                <Check /> Keep as worded
                            </Button>
                        )}
                    </Form>
                )}
                {canAdd && !adding && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setAdding(true)}
                    >
                        <BookmarkPlus /> It’s true — add to library
                    </Button>
                )}
                {itemType === 'question' && (
                    <Form
                        {...ClaimReviewController.dismiss.form(routeArgs)}
                        options={{ preserveScroll: true }}
                        errorBag={errorBag}
                        transform={(data) => ({ ...data, index })}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={processing}
                            >
                                Skip
                            </Button>
                        )}
                    </Form>
                )}
            </div>
        </div>
    );
}

function AiCheckSheet({
    check,
    body,
    routeArgs,
    errorBag,
    canReview,
    canUpdate,
    subjects,
}: {
    check: AiCheck;
    body: string;
    routeArgs: [string, string];
    errorBag: string;
    canReview: boolean;
    canUpdate: boolean;
    subjects: SelectOption[];
}) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="ghost">
                    <ListChecks /> AI check
                    {check.to_check > 0 && (
                        <Badge
                            variant={check.needs_review ? 'default' : 'outline'}
                            className="ml-1"
                        >
                            {check.needs_review
                                ? `${check.to_check} to check`
                                : 'checked'}
                        </Badge>
                    )}
                </Button>
            </SheetTrigger>
            <SheetContent className="sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>What the AI draft is based on</SheetTitle>
                    <SheetDescription>
                        Each claim is checked on the server against the facts
                        the AI was given. Labels are only ever lowered, never
                        raised.
                        {check.from_resume_version !== null &&
                            ` Written from version ${check.from_resume_version} of the tailored resume.`}
                        {check.instruction !== null &&
                            ` Instruction: “${check.instruction}”.`}
                        {!check.labelled &&
                            ' The claims of this draft are not labelled yet (or the check failed): read it carefully before keeping it as worded.'}
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-6 overflow-y-auto px-4 pb-4">
                    {check.needs_review && canReview && (
                        <div className="space-y-2 rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-3">
                            <p className="text-sm text-text-primary">
                                {check.to_check}{' '}
                                {check.to_check === 1
                                    ? 'claim needs'
                                    : 'claims need'}{' '}
                                your check. For each one below: keep it as
                                worded, or add it to your library as a fact
                                (reworded if needed). If it is not true, edit
                                the answer instead.
                            </p>
                            <Form
                                {...ApplicationQuestionController.claimsReviewed.form(
                                    routeArgs,
                                )}
                                options={{ preserveScroll: true }}
                                errorBag={errorBag}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <Check /> Keep all as worded
                                    </Button>
                                )}
                            </Form>
                        </div>
                    )}

                    {check.claims.length > 0 && body.trim() !== '' && (
                        <section className="space-y-2">
                            <h3 className="type-label text-text-label uppercase">
                                The draft, marked up
                            </h3>
                            <AnnotatedDraft body={body} claims={check.claims} />
                        </section>
                    )}

                    <section className="space-y-2">
                        <h3 className="type-label text-text-label uppercase">
                            Claims
                        </h3>
                        {check.claims.length === 0 ? (
                            <p className="type-body text-text-muted">
                                The AI listed no claims.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {check.claims.map((claim, i) => (
                                    <li
                                        key={i}
                                        className="space-y-1 rounded-kraken-sm border border-border-subtle p-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                variant={
                                                    claim.classification ===
                                                    'verified'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                title={
                                                    claimLabels[
                                                        claim.classification
                                                    ]?.hint
                                                }
                                            >
                                                {claimLabels[
                                                    claim.classification
                                                ]?.label ??
                                                    claim.classification}
                                            </Badge>
                                            {claim.evidence_ids.length > 0 && (
                                                <span className="type-caption text-text-muted">
                                                    {claim.evidence_ids.join(
                                                        ', ',
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-sm text-text-primary">
                                            {claim.text}
                                        </p>
                                        {claim.flags.length > 0 && (
                                            <p className="type-caption text-text-secondary">
                                                Lowered:{' '}
                                                {claim.flags
                                                    .map(
                                                        (flag) =>
                                                            flagLabels[flag] ??
                                                            flag,
                                                    )
                                                    .join('; ')}
                                            </p>
                                        )}
                                        <ReviewState review={claim.review} />
                                        {claim.to_check &&
                                            claim.review === null && (
                                                <ReviewActions
                                                    itemType="claim"
                                                    index={claim.index}
                                                    initialStatement={
                                                        claim.text
                                                    }
                                                    routeArgs={routeArgs}
                                                    subjects={subjects}
                                                    canAccept={canReview}
                                                    canAdd={canUpdate}
                                                />
                                            )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {check.questions.length > 0 && (
                        <section className="space-y-2">
                            <h3 className="type-label text-text-label uppercase">
                                The AI asks
                            </h3>
                            <ul className="space-y-2">
                                {check.questions.map((item, i) => (
                                    <li
                                        key={i}
                                        className="rounded-kraken-sm border border-border-subtle p-3"
                                    >
                                        <p className="text-sm font-medium">
                                            {item.question}
                                        </p>
                                        {item.why !== '' && (
                                            <p className="type-caption text-text-muted">
                                                {item.why}
                                            </p>
                                        )}
                                        <ReviewState review={item.review} />
                                        {item.review === null && canUpdate && (
                                            <ReviewActions
                                                itemType="question"
                                                index={item.index}
                                                initialStatement=""
                                                routeArgs={routeArgs}
                                                subjects={subjects}
                                                canAccept={false}
                                                canAdd
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section className="space-y-2">
                        <h3 className="type-label text-text-label uppercase">
                            Evidence given to the AI
                        </h3>
                        {check.evidence.length === 0 ? (
                            <p className="type-body text-text-muted">
                                None matched. Add facts to your profile, or
                                analyse the job description first.
                            </p>
                        ) : (
                            <ul className="space-y-1">
                                {check.evidence.map((item) => (
                                    <li key={item.id} className="text-sm">
                                        <span className="mr-2 font-medium tabular-nums">
                                            {item.id}
                                        </span>
                                        {item.text}
                                        {item.status === 'proposed' && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Not verified
                                            </Badge>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </SheetContent>
        </Sheet>
    );
}

function EditPromptForm({
    question,
    routeArgs,
    errorBag,
    onDone,
}: {
    question: Question;
    routeArgs: [string, string];
    errorBag: string;
    onDone: () => void;
}) {
    return (
        <Form
            {...ApplicationQuestionController.update.form(routeArgs)}
            options={{ preserveScroll: true }}
            errorBag={errorBag}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <Textarea
                        name="prompt_text"
                        rows={2}
                        defaultValue={question.prompt_text}
                        maxLength={2000}
                        aria-label="Question"
                        required
                    />
                    <InputError message={errors.prompt_text} />
                    <div className="flex flex-wrap items-end gap-3">
                        <LimitFields
                            idPrefix={question.ulid}
                            errors={errors}
                            defaults={question}
                        />
                        <Button size="sm" disabled={processing}>
                            Save question
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function InsertFromLibrary({
    library,
    onInsert,
}: {
    library: LibraryAnswer[];
    onInsert: (entry: LibraryAnswer) => void;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const matches = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();

        return needle === ''
            ? library
            : library.filter(
                  (entry) =>
                      entry.question.toLocaleLowerCase().includes(needle) ||
                      entry.body.toLocaleLowerCase().includes(needle),
              );
    }, [library, search]);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <Library /> Insert from library
                </Button>
            </SheetTrigger>
            <SheetContent className="sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Insert from your answer library</SheetTitle>
                    <SheetDescription>
                        Replaces the text in the box; adjust it for this job,
                        then save.
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-3 overflow-y-auto px-4 pb-4">
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search your answers"
                        aria-label="Search your answers"
                    />
                    {matches.length === 0 ? (
                        <p className="type-body text-text-muted">
                            {library.length === 0
                                ? 'Your answer library is empty. Save good answers there to reuse them.'
                                : 'No answers match.'}
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {matches.map((entry) => (
                                <li
                                    key={entry.ulid}
                                    className="space-y-2 rounded-kraken-sm border border-border-subtle p-3"
                                >
                                    <p className="font-medium">
                                        {entry.question}
                                        {entry.status === 'draft' && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Draft
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="line-clamp-4 text-sm whitespace-pre-line text-text-secondary">
                                        {entry.body}
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            onInsert(entry);
                                            setOpen(false);
                                        }}
                                    >
                                        Use this answer
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function SaveToLibraryDialog({
    routeArgs,
    categories,
    errorBag,
}: {
    routeArgs: [string, string];
    categories: SelectOption[];
    errorBag: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="ghost">
                    <BookmarkPlus /> Save to library
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Save to your answer library</DialogTitle>
                    <DialogDescription>
                        Keeps this question and the saved answer for future
                        applications.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...ApplicationQuestionController.saveToLibrary.form(
                        routeArgs,
                    )}
                    options={{ preserveScroll: true }}
                    errorBag={errorBag}
                    onSuccess={() => setOpen(false)}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor={`category_${routeArgs[1]}`}>
                                Category
                            </Label>
                            <NativeSelect
                                id={`category_${routeArgs[1]}`}
                                name="category"
                                options={categories}
                                defaultValue="other"
                            />
                            <InputError message={errors.category} />
                            <Button disabled={processing}>Save</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

ApplicationQuestions.layout = {
    breadcrumbs: [
        { title: 'Applications', href: applicationsIndex() },
        { title: 'Questions & answers', href: applicationsIndex() },
    ],
};
