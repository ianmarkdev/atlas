import { Form, Head, Link, router } from "@inertiajs/react";
import { Check, Copy, ExternalLink, History } from "lucide-react";
import { useState } from "react";
import ResumeController from "@/actions/App/Http/Controllers/Profile/ResumeController";
import { ResumeEditor } from "@/components/resume/resume-editor";
import { ResumePreview } from "@/components/resume/resume-preview";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from "@/components/ui/sheet";
import { indexLibrary, resumeToPlainText } from "@/lib/resume-document";
import { copyToClipboard } from "@/lib/clipboard";
import { index as resumesIndex } from "@/routes/resumes";
import type {
    Contact,
    ResumeDocument,
    ResumeLibrary,
    ResumeUsage,
} from "@/types";

type ResumeProps = {
    ulid: string;
    name: string;
    file_url: string | null;
    is_default: boolean;
    is_archived: boolean;
    lock_version: number;
    revision_no: number | null;
    document: ResumeDocument;
};

type Revision = {
    id: number;
    revision_no: number;
    created_at: string;
    author: string | null;
    created_via: string;
};

type PageProps = {
    resume: ResumeProps;
    contact: Contact;
    library: ResumeLibrary;
    usedElsewhere: ResumeUsage;
    revisions: Revision[];
    canEdit: boolean;
};

export default function ResumePage(props: PageProps) {
    return (
        <>
            <Head title={props.resume.name} />
            {/* Remount after every save, so the editor starts from the saved revision. */}
            <ResumeEditorPage
                key={`${props.resume.ulid}:${props.resume.revision_no}`}
                {...props}
            />
        </>
    );
}
function ResumeEditorPage({
    resume,
    contact,
    library,
    usedElsewhere,
    revisions,
    canEdit,
}: PageProps) {
    const [name, setName] = useState(resume.name);
    const [copied, setCopied] = useState(false);

    if (!canEdit) {
        const index = indexLibrary(library);
        const copyText = async () => {
            const isCopied = await copyToClipboard(
                resumeToPlainText(resume.document, contact, index),
            );
            setCopied(isCopied);
            window.setTimeout(() => setCopied(false), 2000);
        };

        return (
            <div className="flex flex-col gap-4 p-4 lg:max-w-3xl">
                <ReadOnlyHeader
                    resume={resume}
                    onCopy={copyText}
                    copied={copied}
                />
                <ResumePreview
                    document={resume.document}
                    contact={contact}
                    index={index}
                />
            </div>
        );
    }

    const save = (document: ResumeDocument, done: () => void) =>
        router.put(
            ResumeController.update.url(resume.ulid),
            { name, document, lock_version: resume.lock_version },
            { preserveScroll: true, onFinish: done },
        );

    return (
        <ResumeEditor
            initialDocument={resume.document}
            library={library}
            contact={contact}
            usedElsewhere={usedElsewhere}
            statusText={`Saved · revision ${resume.revision_no ?? 0}`}
            extraDirty={name !== resume.name}
            onSave={save}
            applyBulletUrl={(claimUlid) =>
                ResumeController.applyBullet.url([resume.ulid, claimUlid])
            }
            canEditDisplayTitles
            toolbarStart={
                <>
                    <Input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        aria-label="Resume name"
                        className="max-w-xs font-medium"
                        maxLength={120}
                    />
                    {resume.is_default && (
                        <Badge variant="secondary">Default</Badge>
                    )}
                </>
            }
            toolbarEnd={(dirty) => (
                <>
                    <FileLinkButton resume={resume} />
                    <HistorySheet
                        resume={resume}
                        revisions={revisions}
                        dirty={dirty}
                    />
                </>
            )}
        />
    );
}

function HistorySheet({
    resume,
    revisions,
    dirty,
}: {
    resume: ResumeProps;
    revisions: Revision[];
    dirty: boolean;
}) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <History /> History
                </Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>History</SheetTitle>
                    <SheetDescription>
                        Every save is kept. Restoring an older version saves it
                        as a new revision.
                        {dirty && " Restoring discards your unsaved changes."}
                    </SheetDescription>
                </SheetHeader>
                <ul className="space-y-2 overflow-y-auto px-4 pb-4">
                    {revisions.map((revision) => (
                        <li
                            key={revision.id}
                            className="flex items-center justify-between gap-2 rounded-md border p-2 text-sm"
                        >
                            <div>
                                <p className="font-medium">
                                    Revision {revision.revision_no}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {new Date(
                                        revision.created_at,
                                    ).toLocaleString()}
                                    {revision.author && ` · ${revision.author}`}
                                </p>
                            </div>
                            {revision.revision_no === resume.revision_no ? (
                                <Badge variant="secondary">Current</Badge>
                            ) : (
                                <Form
                                    {...ResumeController.restoreRevision.form([
                                        resume.ulid,
                                        revision.id,
                                    ])}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Restore
                                            </Button>
                                            {errors.revision && (
                                                <p className="text-xs text-destructive">
                                                    {errors.revision}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
            </SheetContent>
        </Sheet>
    );
}

/** Opens the resume's file in cloud storage; the link is set on the Profile page. */
function FileLinkButton({ resume }: { resume: ResumeProps }) {
    if (!resume.file_url) {
        return null;
    }

    return (
        <Button size="sm" variant="outline" asChild>
            <a href={resume.file_url} target="_blank" rel="noreferrer">
                <ExternalLink /> Open file
            </a>
        </Button>
    );
}

function ReadOnlyHeader({
    resume,
    onCopy,
    copied,
}: {
    resume: ResumeProps;
    onCopy: () => void;
    copied: boolean;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <h1 className="type-heading-3 text-text-primary">{resume.name}</h1>
            {resume.is_default && <Badge variant="secondary">Default</Badge>}
            {resume.is_archived && <Badge variant="outline">Archived</Badge>}
            <span className="text-sm text-muted-foreground">
                Only the candidate can edit resumes.
            </span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="ml-auto"
                onClick={onCopy}
            >
                {copied ? <Check /> : <Copy />}{" "}
                {copied ? "Copied" : "Copy as plain text"}
            </Button>
            <FileLinkButton resume={resume} />
            <Button size="sm" variant="ghost" asChild>
                <Link href={resumesIndex()}>All resumes</Link>
            </Button>
        </div>
    );
}

ResumePage.layout = {
    breadcrumbs: [{ title: "Profile", href: resumesIndex() }],
};
