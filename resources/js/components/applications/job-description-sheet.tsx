import { FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

/**
 * The pasted job description in a side panel, to keep open while tailoring or answering.
 */
export function JobDescriptionSheet({ text }: { text: string | null }) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <FileText /> Job description
                </Button>
            </SheetTrigger>
            <SheetContent className="sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Job description</SheetTitle>
                    <SheetDescription>
                        Keep it open while you work: match the employer’s words
                        where your facts support them.
                    </SheetDescription>
                </SheetHeader>
                <div className="overflow-y-auto px-4 pb-4 text-sm leading-relaxed whitespace-pre-line text-text-secondary">
                    {text ?? 'No job description pasted yet.'}
                </div>
            </SheetContent>
        </Sheet>
    );
}
