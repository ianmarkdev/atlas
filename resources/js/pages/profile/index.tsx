import { Form, Head, Link } from '@inertiajs/react';
import { FileText, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import ResumeController from '@/actions/App/Http/Controllers/Profile/ResumeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
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
import { edit as editContact } from '@/routes/contact';
import { index } from '@/routes/resumes';
import type { Contact } from '@/types';

type ResumeCard = {
    ulid: string;
    name: string;
    title: string | null;
    jobs: number;
    is_default: boolean;
    is_archived: boolean;
    updated_at: string | null;
};

export default function Profile({
    contact,
    resumes,
    canEdit,
}: {
    contact: Contact;
    resumes: ResumeCard[];
    canEdit: boolean;
}) {
    const [showArchived, setShowArchived] = useState(false);
    const active = resumes.filter((resume) => !resume.is_archived);
    const archived = resumes.filter((resume) => resume.is_archived);
    const contactLine = [contact.email, contact.phone, contact.location]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title="Profile" />

            <div className="flex flex-col gap-8 p-4 lg:max-w-5xl">
                <section className="flex flex-wrap items-start justify-between gap-4 rounded-lg border p-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {contact.display_name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {contactLine ||
                                'No contact details yet. They appear at the top of every resume.'}
                        </p>
                    </div>
                    {canEdit && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={editContact()}>
                                <Pencil /> Edit contact details
                            </Link>
                        </Button>
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading
                            variant="small"
                            title="Resumes"
                            description="One resume per focus, e.g. PHP, React, Next.js or Ruby. Each picks and words your experience, projects and bullet points differently; the facts are shared."
                        />
                        {canEdit && <NewResumeDialog resumes={active} />}
                    </div>

                    {active.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            No resumes yet.
                            {canEdit &&
                                ' Create your first one — for example "PHP focused".'}
                        </p>
                    ) : (
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {active.map((resume) => (
                                <ResumeCardItem
                                    key={resume.ulid}
                                    resume={resume}
                                    canEdit={canEdit}
                                />
                            ))}
                        </ul>
                    )}

                    {archived.length > 0 && (
                        <div className="space-y-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setShowArchived(!showArchived)}
                            >
                                {showArchived ? 'Hide' : 'Show'} archived
                                resumes ({archived.length})
                            </Button>
                            {showArchived && (
                                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {archived.map((resume) => (
                                        <ResumeCardItem
                                            key={resume.ulid}
                                            resume={resume}
                                            canEdit={canEdit}
                                        />
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function ResumeCardItem({
    resume,
    canEdit,
}: {
    resume: ResumeCard;
    canEdit: boolean;
}) {
    return (
        <li className="flex flex-col gap-3 rounded-lg border p-4">
            <div className="flex items-start gap-2">
                <FileText
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                />
                <div className="min-w-0 flex-1">
                    <Link
                        href={ResumeController.show(resume.ulid)}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {resume.name}
                    </Link>
                    <p className="truncate text-sm text-muted-foreground">
                        {resume.title ?? 'No title yet'}
                    </p>
                </div>
                {resume.is_default && (
                    <Badge variant="secondary">Default</Badge>
                )}
                {resume.is_archived && (
                    <Badge variant="outline">Archived</Badge>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                {resume.jobs} {resume.jobs === 1 ? 'experience' : 'experiences'}
                {resume.updated_at &&
                    ` · edited ${new Date(resume.updated_at).toLocaleDateString()}`}
            </p>

            {canEdit && (
                <div className="mt-auto flex flex-wrap gap-2">
                    {!resume.is_archived && (
                        <Button size="sm" asChild>
                            <Link href={ResumeController.show(resume.ulid)}>
                                Open
                            </Link>
                        </Button>
                    )}
                    {!resume.is_default && !resume.is_archived && (
                        <Form
                            {...ResumeController.makeDefault.form(resume.ulid)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Make default
                                </Button>
                            )}
                        </Form>
                    )}
                    {!resume.is_default && !resume.is_archived && (
                        <Form
                            {...ResumeController.archive.form(resume.ulid)}
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
                    {resume.is_archived && (
                        <Form
                            {...ResumeController.unarchive.form(resume.ulid)}
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
            )}
        </li>
    );
}

function NewResumeDialog({ resumes }: { resumes: ResumeCard[] }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus /> New resume
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New resume</DialogTitle>
                    <DialogDescription>
                        Start empty, or copy an existing resume and change its
                        focus.
                    </DialogDescription>
                </DialogHeader>
                <Form {...ResumeController.store.form()} className="space-y-4">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="resume_name">Name</Label>
                                <Input
                                    id="resume_name"
                                    name="name"
                                    placeholder="e.g. React focused"
                                    required
                                    maxLength={120}
                                />
                                <InputError message={errors.name} />
                            </div>
                            {resumes.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="copy_from">
                                        Start from
                                    </Label>
                                    <NativeSelect
                                        id="copy_from"
                                        name="copy_from"
                                        placeholder="Empty resume"
                                        options={resumes.map((resume) => ({
                                            value: resume.ulid,
                                            label: `Copy of ${resume.name}`,
                                        }))}
                                    />
                                </div>
                            )}
                            <DialogFooter>
                                <Button disabled={processing}>
                                    Create resume
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

Profile.layout = {
    breadcrumbs: [{ title: 'Profile', href: index() }],
};
