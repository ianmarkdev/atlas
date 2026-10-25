import { Form, Head, Link, router, usePoll } from "@inertiajs/react";
import {
    Columns3,
    ExternalLink,
    Plus,
    Rows3,
    Search,
    Sparkles,
} from "lucide-react";
import { useEffect, useState } from "react";
import ApplicationAutopilotController from "@/actions/App/Http/Controllers/Applications/ApplicationAutopilotController";
import JobApplicationController from "@/actions/App/Http/Controllers/Applications/JobApplicationController";
import {
    DeadlineBadge,
    StageBadge,
    StageSelect,
} from "@/components/applications/stage";
import { Initials, ReviewBadge } from "@/components/applications/review";
import Heading from "@/components/heading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { companyLabel, formatDay, groupByStage } from "@/lib/applications";
import { timeAgo } from "@/lib/time";
import { cn } from "@/lib/utils";
import { index } from "@/routes/applications";
import { index as collectionsIndex } from "@/routes/collections";
import type { JobApplication, SelectOption } from "@/types";

type View = "board" | "table";

type QuickFilter =
    | "all"
    | "mine"
    | "unassigned"
    | "in_review"
    | "changes_requested"
    | "overdue"
    | "background";

const quickFilters: { value: QuickFilter; label: string }[] = [
    { value: "all", label: "All" },
    { value: "mine", label: "Assigned to me" },
    { value: "unassigned", label: "Unassigned" },
    { value: "in_review", label: "In review" },
    { value: "changes_requested", label: "Changes requested" },
    { value: "overdue", label: "Overdue" },
    { value: "background", label: "In background" },
];

/** The applications chosen for "Prepare in background", by ulid. */
type Selection = {
    has: (ulid: string) => boolean;
    toggle: (ulid: string, on: boolean) => void;
    setAll: (ulids: string[], on: boolean) => void;
};

const viewStorageKey = "applications.view";

function storedView(): View {
    try {
        return window.localStorage.getItem(viewStorageKey) === "board"
            ? "board"
            : "table";
    } catch {
        return "table";
    }
}

export default function Applications({
    applications,
    filters,
    stages,
    canViewDeleted,
    collection,
}: {
    applications: JobApplication[];
    filters: { q: string; deleted: boolean; filter: QuickFilter };
    stages: SelectOption[];
    canViewDeleted: boolean;
    collection: { ulid: string; name: string } | null;
}) {
    const [search, setSearch] = useState(filters.q);
    const [view, setView] = useState<View>("table");

    useEffect(() => setView(storedView()), []);

    const [selected, setSelected] = useState<string[]>([]);
    const selection: Selection = {
        has: (ulid) => selected.includes(ulid),
        toggle: (ulid, on) =>
            setSelected((current) =>
                on
                    ? [...current, ulid]
                    : current.filter((value) => value !== ulid),
            ),
        setAll: (ulids, on) =>
            setSelected((current) =>
                on
                    ? [...new Set([...current, ...ulids])]
                    : current.filter((value) => !ulids.includes(value)),
            ),
    };

    // Forget choices that left the list (another filter, or deleted).
    useEffect(() => {
        setSelected((current) =>
            current.filter((ulid) =>
                applications.some((application) => application.ulid === ulid),
            ),
        );
    }, [applications]);

    // Refresh the list while applications are being prepared in the background.
    const preparing = applications.some(
        (application) => application.autopilot?.status === "running",
    );
    const poll = usePoll(
        15000,
        { only: ["applications"] },
        { autoStart: false },
    );

    useEffect(() => {
        if (preparing) {
            poll.start();
        } else {
            poll.stop();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [preparing]);

    const runInBackground = (start: boolean) => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setSelected([]),
        };

        if (start) {
            router.post(
                ApplicationAutopilotController.store.url(),
                { applications: selected },
                options,
            );
        } else {
            router.delete(ApplicationAutopilotController.destroy.url(), {
                data: { applications: selected },
                ...options,
            });
        }
    };

    const chooseView = (next: View) => {
        setView(next);

        try {
            window.localStorage.setItem(viewStorageKey, next);
        } catch {
            // Remembering the view is a convenience only.
        }
    };

    const visit = (query: {
        q?: string;
        deleted?: boolean;
        filter?: QuickFilter;
        collection?: string | null;
    }) => {
        const inCollection =
            query.collection === undefined
                ? (collection?.ulid ?? null)
                : query.collection;

        router.get(
            index.url(),
            {
                ...(inCollection ? { collection: inCollection } : {}),
                ...(query.q ? { q: query.q } : {}),
                ...(query.deleted ? { deleted: 1 } : {}),
                ...(query.filter && query.filter !== "all"
                    ? { filter: query.filter }
                    : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Applications" />

            <div className="flex flex-col gap-6 p-4 lg:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={
                            filters.deleted
                                ? "Recently deleted applications"
                                : "Applications"
                        }
                        description="Every job you are applying for, from saved to offer. Paste the job description once; the resume and answers for it live here too."
                    />
                    {!filters.deleted && (
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href={collectionsIndex()}>
                                    Collections
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href={JobApplicationController.create()}>
                                    <Plus /> Add application
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>

                {collection && (
                    <p className="flex flex-wrap items-center gap-2 rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle px-3 py-2 text-sm text-text-primary">
                        Showing the collection “{collection.name}”.
                        <button
                            type="button"
                            onClick={() =>
                                visit({
                                    q: filters.q,
                                    filter: filters.filter,
                                    collection: null,
                                })
                            }
                            className="text-text-accent underline-offset-4 focus-ring hover:underline"
                        >
                            Show all applications
                        </button>
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        role="search"
                        className="flex min-w-56 flex-1 gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            visit({
                                q: search,
                                deleted: filters.deleted,
                                filter: filters.filter,
                            });
                        }}
                    >
                        <div className="relative flex-1">
                            <Search
                                className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search company or role"
                                aria-label="Search company or role"
                                className="pl-9"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </form>

                    {!filters.deleted && (
                        <div
                            role="group"
                            aria-label="View"
                            className="flex gap-1"
                        >
                            <Button
                                type="button"
                                variant={
                                    view === "board" ? "secondary" : "ghost"
                                }
                                aria-pressed={view === "board"}
                                onClick={() => chooseView("board")}
                            >
                                <Columns3 /> Board
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    view === "table" ? "secondary" : "ghost"
                                }
                                aria-pressed={view === "table"}
                                onClick={() => chooseView("table")}
                            >
                                <Rows3 /> Table
                            </Button>
                        </div>
                    )}
                </div>

                {!filters.deleted && (
                    <div
                        role="group"
                        aria-label="Quick filters"
                        className="-mt-2 flex flex-wrap gap-1.5"
                    >
                        {quickFilters.map((option) => (
                            <Button
                                key={option.value}
                                type="button"
                                size="sm"
                                variant={
                                    filters.filter === option.value
                                        ? "secondary"
                                        : "ghost"
                                }
                                aria-pressed={filters.filter === option.value}
                                onClick={() =>
                                    visit({
                                        q: filters.q,
                                        filter: option.value,
                                    })
                                }
                            >
                                {option.label}
                            </Button>
                        ))}
                    </div>
                )}

                {!filters.deleted && selected.length > 0 && (
                    <div
                        role="region"
                        aria-label="Chosen applications"
                        className="sticky top-2 z-10 flex flex-wrap items-center gap-2 rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle px-3 py-2 text-sm text-text-primary"
                    >
                        <span className="mr-auto">
                            {selected.length} chosen. The AI analyses each job,
                            adds its questions, tailors the resume and drafts
                            the answers and cover letter while you are away.
                            Nothing is approved.
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => runInBackground(true)}
                        >
                            <Sparkles /> Prepare in background
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => runInBackground(false)}
                        >
                            Stop
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelected([])}
                        >
                            Clear
                        </Button>
                    </div>
                )}

                {filters.deleted ? (
                    <DeletedList applications={applications} />
                ) : applications.length === 0 ? (
                    <EmptyState
                        searching={filters.q !== "" || filters.filter !== "all"}
                    />
                ) : view === "board" ? (
                    <Board
                        applications={applications}
                        stages={stages}
                        selection={selection}
                    />
                ) : (
                    <Table
                        applications={applications}
                        stages={stages}
                        selection={selection}
                    />
                )}

                {canViewDeleted && (
                    <button
                        type="button"
                        onClick={() =>
                            visit({ q: filters.q, deleted: !filters.deleted })
                        }
                        className="self-start type-caption text-text-muted underline-offset-4 focus-ring hover:underline"
                    >
                        {filters.deleted
                            ? "Back to applications"
                            : "Recently deleted"}
                    </button>
                )}
            </div>
        </>
    );
}

function EmptyState({ searching }: { searching: boolean }) {
    return (
        <div className="rounded-kraken-sm border border-dashed border-border-input p-8 text-center">
            <p className="type-body text-text-muted">
                {searching
                    ? "No applications match."
                    : "No applications yet. Add the first job you want to apply for — paste its job description and set a deadline."}
            </p>
        </div>
    );
}

function Board({
    applications,
    stages,
    selection,
}: {
    applications: JobApplication[];
    stages: SelectOption[];
    selection: Selection;
}) {
    const { columns, closed } = groupByStage(applications);
    const label = (stage: string) =>
        stages.find((option) => option.value === stage)?.label ?? stage;

    return (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-3 xl:grid-cols-5">
                {columns.map((column) => (
                    <section
                        key={column.stage}
                        aria-label={label(column.stage)}
                        className="flex flex-col gap-3 rounded-kraken-md border border-border-subtle bg-surface-tile p-3"
                    >
                        <h2 className="flex items-center justify-between type-label text-text-label uppercase">
                            {label(column.stage)}
                            <span className="tabular-nums">
                                {column.items.length}
                            </span>
                        </h2>
                        {column.items.length === 0 ? (
                            <p className="rounded-kraken-sm border border-dashed border-border-subtle p-3 type-caption text-text-subtle">
                                Nothing here
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {column.items.map((application) => (
                                    <li key={application.ulid}>
                                        <ApplicationCard
                                            application={application}
                                            stages={stages}
                                            selection={selection}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>

            {closed.length > 0 && (
                <details className="rounded-kraken-md border border-border-subtle p-3">
                    <summary className="cursor-pointer type-label text-text-label uppercase">
                        Closed ({closed.length})
                    </summary>
                    <ul className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        {closed.map((application) => (
                            <li key={application.ulid}>
                                <ApplicationCard
                                    application={application}
                                    stages={stages}
                                    selection={selection}
                                />
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

function ApplicationCard({
    application,
    stages,
    selection,
}: {
    application: JobApplication;
    stages: SelectOption[];
    selection: Selection;
}) {
    return (
        <article className="space-y-2 rounded-kraken-sm border border-border-subtle bg-card p-3 transition-colors duration-fast ease-out hover:border-border-accent-subtle">
            <div className="flex items-start gap-2">
                {application.can.update && (
                    <Checkbox
                        className="mt-1"
                        checked={selection.has(application.ulid)}
                        onCheckedChange={(checked) =>
                            selection.toggle(application.ulid, checked === true)
                        }
                        aria-label={`Choose ${companyLabel(application.company_name)}, ${application.role_title}`}
                    />
                )}
                <Link
                    href={JobApplicationController.show(application.ulid)}
                    className="block min-w-0 flex-1 focus-ring"
                >
                    <p className="truncate font-medium text-text-primary">
                        {companyLabel(application.company_name)}
                    </p>
                    <p className="truncate text-sm text-text-secondary">
                        {application.role_title}
                    </p>
                    {application.location && (
                        <p className="truncate type-caption text-text-muted">
                            {application.location}
                        </p>
                    )}
                </Link>
            </div>
            <AutopilotStatus application={application} />
            <div className="flex flex-wrap items-center gap-1.5">
                {application.assignee && (
                    <Initials
                        name={application.assignee.name}
                        title={`Assigned to ${application.assignee.is_you ? "you" : application.assignee.name}`}
                    />
                )}
                <ReviewBadge
                    status={application.review_status}
                    hideNotStarted
                />
                <DeadlineBadge application={application} />
                {application.stage_changed_at && (
                    <span className="type-caption text-text-subtle">
                        {timeAgo(application.stage_changed_at)}
                    </span>
                )}
            </div>
            {application.can.update && (
                <StageSelect
                    application={application}
                    stages={stages}
                    className="w-full"
                />
            )}
        </article>
    );
}

function Table({
    applications,
    stages,
    selection,
}: {
    applications: JobApplication[];
    stages: SelectOption[];
    selection: Selection;
}) {
    const choosable = applications
        .filter((application) => application.can.update)
        .map((application) => application.ulid);
    const allChosen =
        choosable.length > 0 && choosable.every((ulid) => selection.has(ulid));

    return (
        <div className="overflow-x-auto rounded-kraken-md border border-border-subtle">
            <table className="w-full min-w-[640px] text-sm">
                <thead>
                    <tr className="border-b border-border-subtle text-left">
                        <th scope="col" className="w-8 px-3 py-2">
                            {choosable.length > 0 && (
                                <Checkbox
                                    checked={allChosen}
                                    onCheckedChange={(checked) =>
                                        selection.setAll(
                                            choosable,
                                            checked === true,
                                        )
                                    }
                                    aria-label="Choose all applications"
                                />
                            )}
                        </th>
                        {[
                            "Company & role",
                            "Stage",
                            "Assigned to",
                            "Review",
                            "Deadline",
                            "Applied",
                            "",
                        ].map((heading) => (
                            <th
                                key={heading}
                                scope="col"
                                className="px-3 py-2 type-label font-normal text-text-label uppercase"
                            >
                                {heading}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {applications.map((application) => (
                        <tr
                            key={application.ulid}
                            className="border-b border-border-subtle last:border-0 hover:bg-surface-card"
                        >
                            <td className="px-3 py-2 align-top">
                                {application.can.update && (
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={selection.has(
                                            application.ulid,
                                        )}
                                        onCheckedChange={(checked) =>
                                            selection.toggle(
                                                application.ulid,
                                                checked === true,
                                            )
                                        }
                                        aria-label={`Choose ${companyLabel(application.company_name)}, ${application.role_title}`}
                                    />
                                )}
                            </td>
                            <td className="px-3 py-2">
                                <Link
                                    href={JobApplicationController.show(
                                        application.ulid,
                                    )}
                                    className="font-medium text-text-primary focus-ring hover:underline"
                                >
                                    {companyLabel(application.company_name)}
                                </Link>
                                <p className="text-text-secondary">
                                    {application.role_title}
                                </p>
                                <AutopilotStatus application={application} />
                            </td>
                            <td className="px-3 py-2">
                                {application.can.update ? (
                                    <StageSelect
                                        application={application}
                                        stages={stages}
                                    />
                                ) : (
                                    <StageBadge
                                        stage={application.stage}
                                        stages={stages}
                                    />
                                )}
                            </td>
                            <td className="px-3 py-2 text-text-secondary">
                                {application.assignee
                                    ? application.assignee.is_you
                                        ? "You"
                                        : application.assignee.name
                                    : "—"}
                            </td>
                            <td className="px-3 py-2">
                                <ReviewBadge
                                    status={application.review_status}
                                />
                            </td>
                            <td className="px-3 py-2">
                                <DeadlineBadge application={application} />
                                {!application.is_overdue &&
                                    application.deadline_on &&
                                    [
                                        "applied",
                                        "interviewing",
                                        "offer",
                                    ].includes(application.stage) && (
                                        <span className="text-text-muted">
                                            {formatDay(application.deadline_on)}
                                        </span>
                                    )}
                            </td>
                            <td className="px-3 py-2 text-text-muted">
                                {application.applied_at
                                    ? new Date(
                                          application.applied_at,
                                      ).toLocaleDateString()
                                    : "—"}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {application.source_url && (
                                    <a
                                        href={application.source_url}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="inline-flex text-text-muted focus-ring hover:text-text-primary"
                                        aria-label={`Open the ${companyLabel(application.company_name)} job ad`}
                                    >
                                        <ExternalLink className="size-4" />
                                    </a>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

const autopilotLabels = {
    running: "Preparing in background…",
    ready: "Ready for review",
    stopped: "Needs you",
} as const;

/**
 * Where "Prepare in background" is, with the note when it stopped or left something to check.
 */
function AutopilotStatus({ application }: { application: JobApplication }) {
    const autopilot = application.autopilot;

    if (!autopilot) {
        return null;
    }

    return (
        <div className="mt-1 space-y-1">
            <Badge
                variant={
                    autopilot.status === "stopped"
                        ? "destructive"
                        : autopilot.status === "ready"
                          ? "default"
                          : "secondary"
                }
            >
                {autopilot.status === "running" && (
                    <Sparkles className="animate-pulse" aria-hidden />
                )}
                {autopilotLabels[autopilot.status]}
            </Badge>
            {autopilot.note && (
                <p className="type-caption whitespace-pre-line text-text-muted">
                    {autopilot.note}
                </p>
            )}
        </div>
    );
}

function DeletedList({ applications }: { applications: JobApplication[] }) {
    if (applications.length === 0) {
        return (
            <p className="rounded-kraken-sm border border-dashed border-border-input p-6 type-body text-text-muted">
                Nothing deleted.
            </p>
        );
    }

    return (
        <ul className="space-y-2">
            {applications.map((application) => (
                <li
                    key={application.ulid}
                    className={cn(
                        "flex flex-wrap items-center justify-between gap-3 rounded-kraken-sm border border-border-subtle p-3",
                    )}
                >
                    <div className="min-w-0">
                        <p className="font-medium">
                            {companyLabel(application.company_name)}
                        </p>
                        <p className="text-sm text-text-secondary">
                            {application.role_title}
                        </p>
                        {application.deleted_at && (
                            <Badge variant="outline">
                                Deleted {timeAgo(application.deleted_at)}
                            </Badge>
                        )}
                    </div>
                    {application.can.restore && (
                        <Form
                            {...JobApplicationController.restore.form(
                                application.ulid,
                            )}
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
                </li>
            ))}
        </ul>
    );
}

Applications.layout = {
    breadcrumbs: [{ title: "Applications", href: index() }],
};
