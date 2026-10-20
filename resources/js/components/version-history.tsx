import { History } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import type { TextRevision } from '@/types';

/**
 * Read-only list of earlier wordings of a bullet point or an answer, newest first.
 */
export function VersionHistory({ revisions }: { revisions: TextRevision[] }) {
    if (revisions.length === 0) {
        return null;
    }

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <History /> History ({revisions.length})
                </Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>History</SheetTitle>
                    <SheetDescription>
                        Every change to the wording is kept. To go back to an
                        older version, copy its text into the form and save.
                    </SheetDescription>
                </SheetHeader>
                <ol className="space-y-2 overflow-y-auto px-4 pb-4">
                    {revisions.map((revision, i) => (
                        <li
                            key={revision.revision_no}
                            className="space-y-1 rounded-md border p-3 text-sm"
                        >
                            <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span className="font-medium text-foreground">
                                    Version {revision.revision_no}
                                </span>
                                {i === 0 && (
                                    <Badge variant="secondary">Current</Badge>
                                )}
                                {revision.from_suggestion && (
                                    <Badge variant="outline">
                                        Approved suggestion
                                    </Badge>
                                )}
                            </p>
                            <p className="whitespace-pre-line">
                                {revision.text}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {new Date(revision.created_at).toLocaleString()}
                                {revision.author && ` · ${revision.author}`}
                                {revision.change_reason &&
                                    ` · “${revision.change_reason}”`}
                            </p>
                        </li>
                    ))}
                </ol>
            </SheetContent>
        </Sheet>
    );
}
