import { Form, Head, Link } from '@inertiajs/react';
import ProjectController from '@/actions/App/Http/Controllers/Knowledge/ProjectController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/knowledge';
import type { Project, SelectOption } from '@/types';

export default function ProjectForm({
    project,
    employments,
    defaultEmployment,
}: {
    project: Project | null;
    employments: SelectOption[];
    defaultEmployment: string | null;
}) {
    const isEditing = project !== null;

    return (
        <>
            <Head title={isEditing ? 'Edit project' : 'Add project'} />

            <div className="flex flex-col gap-6 p-4 lg:max-w-2xl">
                <Heading
                    title={isEditing ? 'Edit project' : 'Add project'}
                    description="Plain text only. Link it to an experience, or leave it standalone for freelance and side projects."
                />

                <Form
                    {...(isEditing
                        ? ProjectController.update.form(project.ulid)
                        : ProjectController.store.form())}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.lock_version && (
                                <p
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                                >
                                    {errors.lock_version}
                                </p>
                            )}
                            {isEditing && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={project.lock_version}
                                />
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="name">Project name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={project?.name ?? ''}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="employment">Experience</Label>
                                <NativeSelect
                                    id="employment"
                                    name="employment"
                                    options={employments}
                                    placeholder="Standalone project"
                                    defaultValue={
                                        project?.employment ??
                                        defaultEmployment ??
                                        ''
                                    }
                                />
                                <InputError message={errors.employment} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="role">Your role</Label>
                                    <Input
                                        id="role"
                                        name="role"
                                        defaultValue={project?.role ?? ''}
                                    />
                                    <InputError message={errors.role} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="client_name">Client</Label>
                                    <Input
                                        id="client_name"
                                        name="client_name"
                                        defaultValue={
                                            project?.client_name ?? ''
                                        }
                                        aria-describedby="client_name_hint"
                                    />
                                    <p
                                        id="client_name_hint"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Only if you may name them publicly.
                                    </p>
                                    <InputError message={errors.client_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="start_date">
                                        Start month
                                    </Label>
                                    <Input
                                        id="start_date"
                                        name="start_date"
                                        type="month"
                                        defaultValue={project?.start_date ?? ''}
                                    />
                                    <InputError message={errors.start_date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="end_date">End month</Label>
                                    <Input
                                        id="end_date"
                                        name="end_date"
                                        type="month"
                                        defaultValue={project?.end_date ?? ''}
                                    />
                                    <InputError message={errors.end_date} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="url">Link</Label>
                                <Input
                                    id="url"
                                    name="url"
                                    type="url"
                                    defaultValue={project?.url ?? ''}
                                    placeholder="https://"
                                />
                                <InputError message={errors.url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="summary">
                                    Short description
                                </Label>
                                <Textarea
                                    id="summary"
                                    name="summary"
                                    rows={5}
                                    defaultValue={project?.summary ?? ''}
                                />
                                <InputError message={errors.summary} />
                            </div>

                            <div className="flex gap-2">
                                <Button disabled={processing}>Save</Button>
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

ProjectForm.layout = {
    breadcrumbs: [
        { title: 'Library', href: index() },
        { title: 'Project', href: index() },
    ],
};
