import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    CheckCircle2,
    Circle,
    FileText,
    ListChecks,
    MessageSquareText,
    Plus,
    Send,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import ResumeController from '@/actions/App/Http/Controllers/Profile/ResumeController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { greeting, timeAgo } from '@/lib/time';
import { openStages } from '@/lib/applications';
import { dashboard } from '@/routes';
import {
    create as createApplication,
    index as applicationsIndex,
} from '@/routes/applications';
import { index as libraryIndex } from '@/routes/knowledge';
import {
    create as createAnswer,
    index as answersIndex,
} from '@/routes/knowledge/answers';
import { create as createClaim } from '@/routes/knowledge/claims';
import { index as resumesIndex } from '@/routes/resumes';

type Stats = {
    resumes: number;
    jobs: number;
    projects: number;
    bullets: number;
    bulletsProposed: number;
    skills: number;
    answers: number;
    answersDraft: number;
};

type AttentionItem = {
    key: string;
    label: string;
    count: number;
    href: string;
};

type ChecklistItem = {
    key: string;
    label: string;
    description: string;
    done: boolean;
    href: string;
};

type ResumeSummary = {
    ulid: string;
    name: string;
    title: string | null;
    jobs: number;
    is_default: boolean;
    updated_at: string | null;
};

type Activity = {
    id: number;
    actor: string;
    is_you: boolean;
    sentence: string;
    subject: string | null;
    at: string;
};

const stageLabels: Record<string, string> = {
    saved: 'Saved',
    preparing: 'Preparing',
    applied: 'Applied',
    interviewing: 'Interviewing',
    offer: 'Offer',
};

export default function Dashboard({
    firstName,
    candidateName,
    isCandidate,
    stats,
    attention,
    checklist,
    resumes,
    activity,
    pipeline,
}: {
    firstName: string;
    candidateName: string;
    isCandidate: boolean;
    stats: Stats;
    attention: AttentionItem[];
    checklist: ChecklistItem[] | null;
    resumes: ResumeSummary[];
    activity: Activity[];
    pipeline: Record<string, number>;
}) {
    const setupOpen =
        checklist !== null && checklist.some((item) => !item.done);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="type-display-l text-text-primary">
                            {greeting()}, {firstName}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {isCandidate
                                ? 'Here is where your job search material stands.'
                                : `You are helping ${candidateName}. Here is what is happening.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={createClaim()}>
                                <Plus /> Bullet point
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={createAnswer()}>
                                <Plus /> Answer
                            </Link>
                        </Button>
                        {isCandidate && (
                            <Button size="sm" asChild>
                                <Link href={resumesIndex()}>
                                    <FileText /> Resumes
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>

                <section
                    aria-label="Summary"
                    className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <StatCard
                        icon={FileText}
                        label="Resumes"
                        value={stats.resumes}
                        hint={stats.resumes === 1 ? 'version' : 'versions'}
                        href={resumesIndex().url}
                    />
                    <StatCard
                        icon={ListChecks}
                        label="Bullet points"
                        value={stats.bullets}
                        hint={
                            stats.bulletsProposed > 0
                                ? `${stats.bulletsProposed} to verify`
                                : 'all verified'
                        }
                        href={libraryIndex().url}
                        highlight={stats.bulletsProposed > 0}
                    />
                    <StatCard
                        icon={Briefcase}
                        label="Experience"
                        value={stats.jobs}
                        hint={`${stats.jobs === 1 ? 'job' : 'jobs'} · ${stats.projects} ${stats.projects === 1 ? 'project' : 'projects'} · ${stats.skills} skills`}
                        href={libraryIndex().url}
                    />
                    <StatCard
                        icon={MessageSquareText}
                        label="Answers"
                        value={stats.answers}
                        hint={
                            stats.answersDraft > 0
                                ? `${stats.answersDraft} ${stats.answersDraft === 1 ? 'draft' : 'drafts'} waiting`
                                : 'ready to copy'
                        }
                        href={answersIndex().url}
                        highlight={stats.answersDraft > 0}
                    />
                </section>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-6 lg:col-span-2">
                        <ApplicationsCard pipeline={pipeline} />
                        <ResumesCard
                            resumes={resumes}
                            isCandidate={isCandidate}
                        />
                    </div>

                    <div className="flex flex-col gap-6">
                        <AttentionCard items={attention} />
                        {setupOpen && <ChecklistCard items={checklist} />}
                        <ActivityCard activity={activity} />
                    </div>
                </div>
            </div>
        </>
    );
}

function StatCard({
    icon: Icon,
    label,
    value,
    hint,
    href,
    highlight = false,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    hint: string;
    href: string;
    highlight?: boolean;
}) {
    return (
        <Link href={href} className="group/card rounded-field focus-ring">
            <Card className="h-full gap-2 py-4 transition-colors duration-fast ease-out group-hover/card:border-border-accent-subtle">
                <CardHeader className="flex flex-row items-center justify-between px-4">
                    <CardDescription className="type-nav-link uppercase">
                        {label}
                    </CardDescription>
                    <span className="flex size-8 items-center justify-center rounded-kraken-md bg-surface-accent-subtle text-text-accent">
                        <Icon className="size-4" aria-hidden />
                    </span>
                </CardHeader>
                <CardContent className="px-4">
                    <p className="type-heading-2 text-text-accent tabular-nums">
                        {value}
                    </p>
                    <p
                        className={cn(
                            'text-xs',
                            highlight
                                ? 'text-text-accent'
                                : 'text-muted-foreground',
                        )}
                    >
                        {hint}
                    </p>
                </CardContent>
            </Card>
        </Link>
    );
}

function ApplicationsCard({ pipeline }: { pipeline: Record<string, number> }) {
    const open = openStages.reduce(
        (total, stage) => total + (pipeline[stage] ?? 0),
        0,
    );
    const closed = (pipeline.rejected ?? 0) + (pipeline.withdrawn ?? 0);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <Send className="size-4" aria-hidden /> Applications
                    </CardTitle>
                    <div className="flex items-center gap-3">
                        <Link
                            href={applicationsIndex()}
                            className="group/text-link inline-flex items-center gap-1.5 type-button text-text-accent focus-ring"
                        >
                            Board{' '}
                            <ArrowRight className="size-3.5 transition-transform duration-fast ease-out group-hover/text-link:translate-x-1" />
                        </Link>
                        <Button size="sm" asChild>
                            <Link href={createApplication()}>
                                <Plus /> Add
                            </Link>
                        </Button>
                    </div>
                </div>
                <CardDescription>
                    {open === 0 && closed === 0
                        ? 'Add the jobs you are applying for: paste the job description, set a deadline, move it along as it progresses.'
                        : `${open} open${closed > 0 ? ` · ${closed} closed` : ''}`}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ol className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    {openStages.map((stage) => {
                        const count = pipeline[stage] ?? 0;

                        return (
                            <li key={stage}>
                                <Link
                                    href={applicationsIndex()}
                                    className={cn(
                                        'block rounded-kraken-sm border p-3 focus-ring transition-colors duration-fast ease-out hover:border-border-accent-subtle',
                                        count > 0
                                            ? 'border-border-subtle bg-surface-card'
                                            : 'border-dashed border-border-input',
                                    )}
                                >
                                    <p className="type-label text-text-label uppercase">
                                        {stageLabels[stage]}
                                    </p>
                                    <p
                                        className={cn(
                                            'type-heading-3 tabular-nums',
                                            count > 0
                                                ? 'text-text-primary'
                                                : 'text-text-subtle',
                                        )}
                                    >
                                        {count}
                                    </p>
                                </Link>
                            </li>
                        );
                    })}
                </ol>
            </CardContent>
        </Card>
    );
}

function ResumesCard({
    resumes,
    isCandidate,
}: {
    resumes: ResumeSummary[];
    isCandidate: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle>Resumes</CardTitle>
                    <Link
                        href={resumesIndex()}
                        className="group/text-link inline-flex items-center gap-1.5 type-button text-text-accent focus-ring"
                    >
                        All resumes{' '}
                        <ArrowRight className="size-3.5 transition-transform duration-fast ease-out group-hover/text-link:translate-x-1" />
                    </Link>
                </div>
                <CardDescription>
                    Versions of the same story, each focused on a kind of role.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {resumes.length === 0 ? (
                    <EmptyState
                        text={
                            isCandidate
                                ? 'No resumes yet. Create one from your Profile.'
                                : 'The candidate has no resumes yet.'
                        }
                    />
                ) : (
                    <ul className="divide-y">
                        {resumes.map((resume) => (
                            <li key={resume.ulid}>
                                <Link
                                    href={ResumeController.show(resume.ulid)}
                                    className="flex items-center gap-3 rounded-md px-2 py-3 hover:bg-muted/50"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-kraken-md bg-surface-accent-subtle text-text-accent">
                                        <FileText
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate font-medium">
                                                {resume.name}
                                            </span>
                                            {resume.is_default && (
                                                <Badge variant="secondary">
                                                    Default
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {[
                                                resume.title,
                                                `${resume.jobs} ${resume.jobs === 1 ? 'job' : 'jobs'}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    {resume.updated_at && (
                                        <span className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                                            Edited {timeAgo(resume.updated_at)}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function AttentionCard({ items }: { items: AttentionItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Needs attention</CardTitle>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CheckCircle2
                            className="size-4 text-text-accent"
                            aria-hidden
                        />
                        All caught up.
                    </p>
                ) : (
                    <ul className="space-y-1">
                        {items.map((item) => (
                            <li key={item.key}>
                                <Link
                                    href={item.href}
                                    className="flex items-center justify-between gap-2 rounded-md px-2 py-2 text-sm hover:bg-muted/50"
                                >
                                    {item.label}
                                    <Badge className="tabular-nums">
                                        {item.count}
                                    </Badge>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function ChecklistCard({ items }: { items: ChecklistItem[] }) {
    const done = items.filter((item) => item.done).length;
    const percent = Math.round((done / items.length) * 100);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Get set up</CardTitle>
                <CardDescription>
                    {done} of {items.length} done
                </CardDescription>
                <div
                    role="progressbar"
                    aria-label="Setup progress"
                    aria-valuenow={percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    className="mt-2 h-1.5 overflow-hidden rounded-pill bg-surface-input-subtle"
                >
                    <div
                        className="h-full rounded-pill bg-surface-accent transition-all duration-slow ease-tile"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            </CardHeader>
            <CardContent>
                <ul className="space-y-1">
                    {items.map((item) => (
                        <li key={item.key}>
                            <Link
                                href={item.href}
                                className="flex items-start gap-3 rounded-md px-2 py-2 hover:bg-muted/50"
                            >
                                {item.done ? (
                                    <CheckCircle2
                                        className="mt-0.5 size-4 shrink-0 text-text-accent"
                                        aria-label="Done"
                                    />
                                ) : (
                                    <Circle
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        aria-label="To do"
                                    />
                                )}
                                <span className="min-w-0">
                                    <span
                                        className={cn(
                                            'block text-sm',
                                            item.done
                                                ? 'text-muted-foreground line-through'
                                                : 'font-medium',
                                        )}
                                    >
                                        {item.label}
                                    </span>
                                    {!item.done && (
                                        <span className="block text-xs text-muted-foreground">
                                            {item.description}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function ActivityCard({ activity }: { activity: Activity[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Recent activity</CardTitle>
            </CardHeader>
            <CardContent>
                {activity.length === 0 ? (
                    <EmptyState text="Nothing yet." />
                ) : (
                    <ol className="relative space-y-4 border-l pl-4">
                        {activity.map((event) => (
                            <li key={event.id} className="relative text-sm">
                                <span
                                    aria-hidden
                                    className="absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-background bg-surface-accent"
                                />
                                <p>
                                    <span className="font-medium">
                                        {event.is_you ? 'You' : event.actor}
                                    </span>{' '}
                                    {event.sentence}
                                </p>
                                {event.subject && (
                                    <p className="truncate text-xs text-muted-foreground">
                                        {event.subject}
                                    </p>
                                )}
                                <time
                                    dateTime={event.at}
                                    title={new Date(event.at).toLocaleString()}
                                    className="text-xs text-muted-foreground"
                                >
                                    {timeAgo(event.at)}
                                </time>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

function EmptyState({ text }: { text: string }) {
    return (
        <p className="rounded-kraken-sm border border-dashed border-border-input p-4 type-body text-text-muted">
            {text}
        </p>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
