import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import EmploymentController from '@/actions/App/Http/Controllers/Knowledge/EmploymentController';
import CredentialController from '@/actions/App/Http/Controllers/Knowledge/CredentialController';
import ProjectController from '@/actions/App/Http/Controllers/Knowledge/ProjectController';
import SkillController from '@/actions/App/Http/Controllers/Knowledge/SkillController';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { ClaimList } from '@/components/claim-list';
import Heading from '@/components/heading';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import {
    PendingChangeBadge,
    PendingChangesContext,
} from '@/components/pending-changes';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/knowledge';
import type {
    Claim,
    Credential,
    Employment,
    KnowledgeAbilities,
    Project,
    ResumeUsage,
    Skill,
} from '@/types';

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
    employments,
    standaloneProjects,
    generalClaims,
    education,
    certifications,
    skills,
    canEditSkills,
    showArchived,
    usedIn,
    pendingChanges,
}: {
    pendingChanges: string[];
    employments: Employment[];
    standaloneProjects: Project[];
    generalClaims: Claim[];
    education: Credential[];
    certifications: Credential[];
    skills: Skill[];
    canEditSkills: boolean;
    showArchived: boolean;
    usedIn: ResumeUsage;
}) {
    const isEmpty = employments.length === 0 && standaloneProjects.length === 0;

    return (
        <PendingChangesContext value={new Set(pendingChanges)}>
            <Head title="Library" />

            <div className="flex flex-col gap-8 p-4 lg:max-w-4xl">
                <Heading
                    title="Library"
                    description="Every experience, project and bullet point you have entered — the facts behind all your resumes. Verify, archive and restore them here; build resumes on your Profile."
                />

                <section className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading
                            variant="small"
                            title="Experience"
                            description="Each role, with the projects you did there and their bullet points."
                        />
                        <div className="flex gap-2">
                            <Button size="sm" asChild>
                                <Link href={EmploymentController.create()}>
                                    <Plus /> Add experience
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
                                    usedIn={usedIn.employments[employment.ulid]}
                                    itemKey={`employment:${employment.ulid}`}
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

                                <div className="mt-3">
                                    <ClaimList
                                        claims={employment.claims ?? []}
                                        subject={`employment:${employment.ulid}`}
                                    />
                                </div>

                                <div className="mt-4 space-y-2 border-l-2 pl-4">
                                    {(employment.projects ?? []).map(
                                        (project) => (
                                            <ProjectItem
                                                key={project.ulid}
                                                project={project}
                                                usedIn={
                                                    usedIn.projects[
                                                        project.ulid
                                                    ]
                                                }
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
                            description="Freelance, side and personal projects not tied to an experience."
                        />
                        <ul className="space-y-2">
                            {standaloneProjects.map((project) => (
                                <li key={project.ulid}>
                                    <ProjectItem
                                        project={project}
                                        usedIn={usedIn.projects[project.ulid]}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <CredentialList
                    title="Education"
                    type="education"
                    credentials={education}
                    usedIn={usedIn.credentials}
                />

                <CredentialList
                    title="Certifications"
                    type="certification"
                    credentials={certifications}
                    usedIn={usedIn.credentials}
                />

                <SkillManager skills={skills} canEdit={canEditSkills} />

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Other facts"
                        description="Skills, qualifications and other bullet points not tied to one experience or project."
                    />
                    <ClaimList claims={generalClaims} subject="general" />
                </section>

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
        </PendingChangesContext>
    );
}

function ProjectItem({
    project,
    usedIn,
}: {
    project: Project;
    usedIn?: string[];
}) {
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
                usedIn={usedIn}
                itemKey={`project:${project.ulid}`}
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
            <div className="mt-3">
                <ClaimList
                    claims={project.claims ?? []}
                    subject={`project:${project.ulid}`}
                />
            </div>
        </div>
    );
}

function CredentialList({
    title,
    type,
    credentials,
    usedIn,
}: {
    title: string;
    type: 'education' | 'certification';
    credentials: Credential[];
    usedIn: Record<string, string[]>;
}) {
    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Heading variant="small" title={title} />
                <Button size="sm" variant="outline" asChild>
                    <Link
                        href={CredentialController.create({ query: { type } })}
                    >
                        <Plus />{' '}
                        {type === 'education'
                            ? 'Add education'
                            : 'Add certification'}
                    </Link>
                </Button>
            </div>
            {credentials.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing here yet.
                </p>
            ) : (
                <ul className="space-y-2">
                    {credentials.map((credential) => (
                        <li
                            key={credential.ulid}
                            className="rounded-lg border p-3"
                        >
                            <RecordHeader
                                title={`${credential.name}${credential.field_of_study ? `, ${credential.field_of_study}` : ''} — ${credential.institution}`}
                                subtitle={formatPeriod(
                                    credential.start_date,
                                    credential.end_date,
                                )}
                                status={credential.status}
                                can={credential.can}
                                usedIn={usedIn[credential.ulid]}
                                itemKey={`credential:${credential.ulid}`}
                                editHref={CredentialController.edit(
                                    credential.ulid,
                                )}
                                actions={{
                                    verify: CredentialController.verify.form(
                                        credential.ulid,
                                    ),
                                    archive: CredentialController.archive.form(
                                        credential.ulid,
                                    ),
                                    restore: CredentialController.restore.form(
                                        credential.ulid,
                                    ),
                                }}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function SkillManager({
    skills,
    canEdit,
}: {
    skills: Skill[];
    canEdit: boolean;
}) {
    return (
        <section className="space-y-4">
            <Heading
                variant="small"
                title="Skills"
                description="Your full skill list. Each resume picks, groups and orders the ones it shows."
            />
            {skills.length === 0 ? (
                <p className="text-sm text-muted-foreground">No skills yet.</p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {skills.map((skill) => (
                        <li
                            key={skill.ulid}
                            className="flex items-center gap-1 rounded-full border py-0.5 pr-1 pl-3 text-sm"
                        >
                            {skill.name}
                            {canEdit && (
                                <Form
                                    {...SkillController.destroy.form(
                                        skill.ulid,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-6 px-2 text-xs"
                                            disabled={processing}
                                            aria-label={`Remove ${skill.name}`}
                                        >
                                            ×
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canEdit && (
                <Form
                    {...SkillController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex max-w-xl gap-2"
                >
                    {({ processing, errors }) => (
                        <div className="flex w-full flex-col gap-1">
                            <div className="flex gap-2">
                                <Input
                                    name="names"
                                    placeholder="Add skills, comma-separated: PHP, Laravel, React"
                                    aria-label="Add skills"
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Add
                                </Button>
                            </div>
                            <InputError message={errors.names} />
                        </div>
                    )}
                </Form>
            )}
        </section>
    );
}

function RecordHeader({
    title,
    subtitle,
    status,
    can,
    usedIn,
    itemKey,
    editHref,
    actions,
}: {
    title: string;
    subtitle: string;
    status: Employment['status'];
    can: KnowledgeAbilities;
    usedIn?: string[];
    /** `type:ulid`, to show a pending suggested change */
    itemKey: string;
    editHref: ReturnType<typeof EmploymentController.edit>;
    actions: RecordActions;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{title}</span>
                    <KnowledgeStatusBadge status={status} />
                    <PendingChangeBadge itemKey={itemKey} />
                </div>
                {subtitle && (
                    <p className="text-sm text-muted-foreground">{subtitle}</p>
                )}
                <p className="text-xs text-muted-foreground">
                    {usedIn && usedIn.length > 0
                        ? `Used in: ${usedIn.join(', ')}`
                        : 'Not on any resume yet'}
                </p>
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
                {can.propose && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={editHref}>Suggest change</Link>
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
            title: 'Library',
            href: index(),
        },
    ],
};
