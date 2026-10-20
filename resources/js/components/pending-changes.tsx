import { createContext, useContext } from 'react';
import { Badge } from '@/components/ui/badge';

/**
 * Library items with a suggested change waiting for the candidate, as `type:ulid` keys
 * (e.g. `claim:01J…`). Provided by the Library page.
 */
export const PendingChangesContext = createContext<Set<string>>(new Set());

export function PendingChangeBadge({ itemKey }: { itemKey: string }) {
    const pending = useContext(PendingChangesContext);

    return pending.has(itemKey) ? (
        <Badge variant="outline">Change pending</Badge>
    ) : null;
}
