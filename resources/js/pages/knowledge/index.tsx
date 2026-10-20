import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import EmploymentController from '@/actions/App/Http/Controllers/Knowledge/EmploymentController';
import ProjectController from '@/actions/App/Http/Controllers/Knowledge/ProjectController';
import Heading from '@/components/heading';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/knowledge';
import { edit as editProfile } from '@/routes/knowledge/profile';
import type { Employment, KnowledgeAbilities, Project } from '@/types';

type RecordActions = {
    verify: ReturnType<typeof EmploymentController.verify.form>;
    archive: ReturnType<typeof EmploymentController.archive.form>;
    restore: ReturnType<typeof EmploymentController.restore.form>;
};

function formatPeriod(start: string | null, end: string | null): string {
    const month = (value: string) =>
        new Date(`${value}-01T00:00:00`).toLocaleDateString(undefined, {
            month: 'short',
            year: 'numeric',
        });

    if (start === null) {
        return end === null ? '' : `until ${month(end)}`;
    }

    return `${month(start)} – ${end === null ? 'present' : month(end)}`;
}

export default function KnowledgeIndex({
    profile,
    employments,
    standaloneProjects,
    showArchived,
    canEditProfile,
}: {
    profile: { display_name: string; headline: string | null };
    employments: Employment[];
    standaloneProjects: Project[];
    showArchived: boolean;
    canEditProfile: boolean;
}) {
    const isEmpty = employments.length === 0 && standaloneProjects.length === 0;

    return (
        <>
            <Head title="Career knowledge" />

            <div className="flex flex-col gap-8 p-4 lg:max-w-4xl">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {profile.display_name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {profile.headline ?? 'No headline yet'}
                        </p>
                    </div>
                    {canEditProfile && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={editProfile()}>
                                <Pencil /> Edit profile
                            </Link>
                        </Button>
                    )}
                </div>

                <section className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading
                            variant="small"
                            title="Employments"
                            description="Each job, with the projects you did there."
                        />
                        <div className="flex gap-2">
                            <Button size="sm" asChild>
                                <Link href={EmploymentController.create()}>
                                    <Plus /> Add employment
                                </Link>
                            </Button>
                            <Button size="sm" variant="outline" asChild>
                                <Link href={ProjectController.create()}>
                                    <Plus /> Add project
                                </Link>
                            </Button>
                        </div>
                    </div>

                    {isEmpty && (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            Nothing here yet. Start with your current or most
                            recent job.
                        </p>
                    )}

                    <ul className="space-y-4">
                        {employments.map((employment) => (
                            <li
                                key={employment.ulid}
                                className="rounded-lg border p-4"
                            >
                                <RecordHeader
                                    title={`${employment.title} · ${employment.employer_name}`}
                                    subtitle={[
                                        formatPeriod(
                                            employment.start_date,
                                            employment.end_date,
                                        ),
                                        employment.location,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    status={employment.status}
                                    can={employment.can}
                                    editHref={EmploymentController.edit(
                                        employment.ulid,
                                    )}
                                    actions={{
                                        verify: EmploymentController.verify.form(
                                            employment.ulid,
                                        ),
                                        archive:
                                            EmploymentController.archive.form(
                                                employment.ulid,
                                            ),
                                        restore:
                                            EmploymentController.restore.form(
                                                employment.ulid,
                                            ),
                                    }}
                                />

                                {employment.summary && (
                                    <p className="mt-3 text-sm whitespace-pre-line">
                                        {employment.summary}
                                    </p>
                                )}

                                <div className="mt-4 space-y-2 border-l-2 pl-4">
                                    {(employment.projects ?? []).map(
                                        (project) => (
                                            <ProjectItem
                                                key={project.ulid}
                                                project={project}
                                            />
                                        ),
                                    )}
                                    <Link
                                        href={ProjectController.create({
                                            query: {
                                                employment: employment.ulid,
                                            },
                                        })}
                                        className="inline-flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline"
                                    >
                                        <Plus className="size-3.5" /> Add a
                                        project at {employment.employer_name}
                                    </Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                {standaloneProjects.length > 0 && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Other projects"
                            description="Freelance, side and personal projects not tied to an employment."
                        />
                        <ul className="space-y-2">
                            {standaloneProjects.map((project) => (
                                <li key={project.ulid}>
                                    <ProjectItem project={project} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <Link
                    href={index({
                        query: showArchived ? {} : { archived: 1 },
                    })}
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    {showArchived
                        ? 'Hide archived records'
                        : 'Show archived records'}
                </Link>
            </div>
        </>
    );
}

function ProjectItem({ project }: { project: Project }) {
    return (
        <div className="rounded-md bg-muted/40 p-3">
            <RecordHeader
                title={project.name}
                subtitle={[
                    project.role,
                    project.client_name && `for ${project.client_name}`,
                    formatPeriod(project.start_date, project.end_date),
                ]
                    .filter(Boolean)
                    .join(' · ')}
                status={project.status}
                can={project.can}
                editHref={ProjectController.edit(project.ulid)}
                actions={{
                    verify: ProjectController.verify.form(project.ulid),
                    archive: ProjectController.archive.form(project.ulid),
                    restore: ProjectController.restore.form(project.ulid),
                }}
            />
            {project.summary && (
                <p className="mt-2 text-sm whitespace-pre-line">
                    {project.summary}
                </p>
            )}
        </div>
    );
}

function RecordHeader({
    title,
    subtitle,
    status,
    can,
    editHref,
    actions,
}: {
    title: string;
    subtitle: string;
    status: Employment['status'];
    can: KnowledgeAbilities;
    editHref: ReturnType<typeof EmploymentController.edit>;
    actions: RecordActions;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{title}</span>
                    <KnowledgeStatusBadge status={status} />
                </div>
                {subtitle && (
                    <p className="text-sm text-muted-foreground">{subtitle}</p>
                )}
            </div>

            <div className="flex shrink-0 flex-wrap gap-2">
                {can.verify && (
                    <Form
                        {...actions.verify}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button size="sm" disabled={processing}>
                                Verify
                            </Button>
                        )}
                    </Form>
                )}
                {can.update && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={editHref}>Edit</Link>
                    </Button>
                )}
                {can.archive && (
                    <Form
                        {...actions.archive}
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
                {can.restore && (
                    <Form
                        {...actions.restore}
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
        </div>
    );
}

KnowledgeIndex.layout = {
    breadcrumbs: [
        {
            title: 'Career knowledge',
            href: index(),
        },
    ],
};
