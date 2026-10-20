import { useHttp } from '@inertiajs/react';
import { ListPlus, Plus } from 'lucide-react';
import { useState } from 'react';
import ClaimController from '@/actions/App/Http/Controllers/Knowledge/ClaimController';
import EmploymentController from '@/actions/App/Http/Controllers/Knowledge/EmploymentController';
import ProjectController from '@/actions/App/Http/Controllers/Knowledge/ProjectController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Claim, Employment, Project } from '@/types';

/*
 * Dialogs that add an experience, project or several bullet points to the shared library from inside the
 * resume editor. They post JSON (no page visit), so unsaved resume changes are kept, and hand
 * the created record back to the editor, which includes it in the resume.
 */

export function AddJobDialog({
    onAdded,
}: {
    onAdded: (employment: Employment) => void;
}) {
    const [open, setOpen] = useState(false);
    const form = useHttp<Record<string, string>, { employment: Employment }>({
        employer_name: '',
        title: '',
        location: '',
        start_date: '',
        end_date: '',
    });

    const submit = async () => {
        try {
            const response = await form.post(EmploymentController.store.url());
            onAdded(response.employment);
            form.reset();
            setOpen(false);
        } catch {
            // Validation errors are shown in the dialog.
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm">
                    <Plus /> Add experience
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add an experience</DialogTitle>
                    <DialogDescription>
                        Saved to your library with its official title and dates,
                        then added to this resume. You can change how this
                        resume shows the title afterwards.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <Field
                        label="Employer"
                        id="job_employer"
                        error={form.errors.employer_name}
                    >
                        <Input
                            id="job_employer"
                            value={form.data.employer_name}
                            onChange={(event) =>
                                form.setData(
                                    'employer_name',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="Official job title"
                        id="job_title"
                        error={form.errors.title}
                    >
                        <Input
                            id="job_title"
                            value={form.data.title}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="Start month"
                        id="job_start"
                        error={form.errors.start_date}
                    >
                        <Input
                            id="job_start"
                            type="month"
                            value={form.data.start_date}
                            onChange={(event) =>
                                form.setData('start_date', event.target.value)
                            }
                            required
                        />
                    </Field>
                    <Field
                        label="End month (empty = current)"
                        id="job_end"
                        error={form.errors.end_date}
                    >
                        <Input
                            id="job_end"
                            type="month"
                            value={form.data.end_date}
                            onChange={(event) =>
                                form.setData('end_date', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Location"
                        id="job_location"
                        error={form.errors.location}
                    >
                        <Input
                            id="job_location"
                            value={form.data.location}
                            onChange={(event) =>
                                form.setData('location', event.target.value)
                            }
                        />
                    </Field>
                    <DialogFooter className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            Add experience
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function AddProjectDialog({
    employment,
    onAdded,
    label = 'Add project',
}: {
    employment: string | null;
    onAdded: (project: Project) => void;
    label?: string;
}) {
    const [open, setOpen] = useState(false);
    const form = useHttp<Record<string, string>, { project: Project }>({
        employment: employment ?? '',
        name: '',
        role: '',
        start_date: '',
        end_date: '',
    });

    const submit = async () => {
        try {
            const response = await form.post(ProjectController.store.url());
            onAdded(response.project);
            form.reset('name', 'role', 'start_date', 'end_date');
            setOpen(false);
        } catch {
            // Validation errors are shown in the dialog.
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <Plus /> {label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{label}</DialogTitle>
                    <DialogDescription>
                        Saved to your library and added to this resume. Put
                        achievements in bullet points, not in the description.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <div className="sm:col-span-2">
                        <Field
                            label="Project name"
                            id="project_name"
                            error={form.errors.name}
                        >
                            <Input
                                id="project_name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                        </Field>
                    </div>
                    <Field
                        label="Your role"
                        id="project_role"
                        error={form.errors.role}
                    >
                        <Input
                            id="project_role"
                            value={form.data.role}
                            onChange={(event) =>
                                form.setData('role', event.target.value)
                            }
                        />
                    </Field>
                    <div />
                    <Field
                        label="Start month"
                        id="project_start"
                        error={form.errors.start_date}
                    >
                        <Input
                            id="project_start"
                            type="month"
                            value={form.data.start_date}
                            onChange={(event) =>
                                form.setData('start_date', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="End month"
                        id="project_end"
                        error={form.errors.end_date}
                    >
                        <Input
                            id="project_end"
                            type="month"
                            value={form.data.end_date}
                            onChange={(event) =>
                                form.setData('end_date', event.target.value)
                            }
                        />
                    </Field>
                    <InputError message={form.errors.employment} />
                    <DialogFooter className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            {label}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function PasteListDialog({
    subject,
    onAdded,
}: {
    subject: string;
    onAdded: (claims: Claim[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const form = useHttp<
        Record<string, string>,
        { claims: Claim[]; skipped: number }
    >({
        subject,
        kind: 'achievement',
        statements: '',
    });
    const [skipped, setSkipped] = useState<number | null>(null);

    const submit = async () => {
        try {
            const response = await form.post(ClaimController.bulkStore.url());
            onAdded(response.claims);
            setSkipped(response.skipped);
            form.reset('statements');

            if (response.skipped === 0) {
                setOpen(false);
            }
        } catch {
            // Validation errors are shown in the dialog.
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setSkipped(null);
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="ghost">
                    <ListPlus /> Paste a list
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Paste a list of bullet points</DialogTitle>
                    <DialogDescription>
                        One bullet point per line. Bullet characters are removed
                        and exact duplicates are skipped. Nothing is changed by
                        AI.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <Label htmlFor="paste_statements" className="sr-only">
                        Bullet points
                    </Label>
                    <Textarea
                        id="paste_statements"
                        rows={8}
                        value={form.data.statements}
                        onChange={(event) =>
                            form.setData('statements', event.target.value)
                        }
                        placeholder={
                            '- Led a team of four developers\n- Migrated billing to queued jobs'
                        }
                    />
                    <InputError message={form.errors.statements} />
                    {skipped !== null && skipped > 0 && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Added. {skipped} duplicate
                            {skipped === 1 ? ' was' : 's were'} skipped.
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Add bullet points
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Field({
    label,
    id,
    error,
    children,
}: {
    label: string;
    id: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
