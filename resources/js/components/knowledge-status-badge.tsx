import { Badge } from '@/components/ui/badge';
import type { ClaimStatus, KnowledgeStatus } from '@/types';

const labels: Record<KnowledgeStatus | ClaimStatus, string> = {
    verified: 'Verified',
    proposed: 'Proposed',
    rejected: 'Rejected',
    archived: 'Archived',
};

/**
 * Status shown as text, not only colour, so it stays readable without colour vision.
 */
export function KnowledgeStatusBadge({
    status,
}: {
    status: KnowledgeStatus | ClaimStatus;
}) {
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
