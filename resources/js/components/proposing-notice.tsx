import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * Shown on a library form when a VA edits a verified item: the save becomes a suggestion the
 * candidate approves (D5), with an optional note explaining why.
 */
export function ProposingNotice({ error }: { error?: string }) {
    return (
        <div className="space-y-3 rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-4 type-body">
            <p>
                This is verified. Your change is sent to the candidate as a
                suggestion and applies only once they approve it.
            </p>
            <div className="grid gap-2">
                <Label htmlFor="rationale">Why the change? (optional)</Label>
                <Textarea
                    id="rationale"
                    name="rationale"
                    rows={2}
                    maxLength={1000}
                    placeholder="e.g. The old CV says five people, not four."
                />
                <InputError message={error} />
            </div>
        </div>
    );
}
