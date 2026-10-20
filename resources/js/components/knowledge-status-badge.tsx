import { Badge } from '@/components/ui/badge';
import type { KnowledgeStatus } from '@/types';

const labels: Record<KnowledgeStatus, string> = {
    verified: 'Verified',
    proposed: 'Proposed',
    archived: 'Archived',
};

/**
 * Status shown as text, not only colour, so it stays readable without colour vision.
 */
export function KnowledgeStatusBadge({ status }: { status: KnowledgeStatus }) {
    return (
        <Badge
            variant={
                status === 'verified'
                    ? 'secondary'
                    : status === 'proposed'
                      ? 'default'
                      : 'outline'
            }
        >
            {labels[status]}
        </Badge>
    );
}
