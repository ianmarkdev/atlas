import { Badge } from '@/components/ui/badge';
import { timeAgo } from '@/lib/time';
import type { ActivityItem, ReviewStatus } from '@/types';

export const reviewLabels: Record<ReviewStatus, string> = {
    not_started: 'Not started',
    in_progress: 'In progress',
    in_review: 'In review',
    changes_requested: 'Changes requested',
    approved: 'Approved',
};

export function ReviewBadge({
    status,
    hideNotStarted = false,
}: {
    status: ReviewStatus;
    hideNotStarted?: boolean;
}) {
    if (hideNotStarted && status === 'not_started') {
        return null;
    }

    const variant =
        status === 'approved'
            ? 'default'
            : status === 'changes_requested'
              ? 'destructive'
              : status === 'in_review'
                ? 'secondary'
                : 'outline';

    return <Badge variant={variant}>{reviewLabels[status]}</Badge>;
}

/**
 * Initials in a small circle, for assignees on cards.
 */
export function Initials({ name, title }: { name: string; title?: string }) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

    return (
        <span
            title={title ?? name}
            aria-label={title ?? name}
            className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-surface-accent-subtle type-caption font-medium text-text-accent"
        >
            {initials}
        </span>
    );
}

/**
 * A vertical list of audit events: who did what, the subject, any note, and when.
 */
export function Timeline({ items }: { items: ActivityItem[] }) {
    if (items.length === 0) {
        return (
            <p className="rounded-kraken-sm border border-dashed border-border-input p-4 type-body text-text-muted">
                Nothing yet.
            </p>
        );
    }

    return (
        <ol className="relative space-y-4 border-l border-border-subtle pl-4">
            {items.map((item) => (
                <li key={item.id} className="relative text-sm">
                    <span
                        aria-hidden
                        className="absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-background bg-text-subtle"
                    />
                    <p>
                        <span className="font-medium">
                            {item.is_you ? 'You' : item.actor}
                        </span>{' '}
                        {item.sentence}
                    </p>
                    {item.subject && (
                        <p className="truncate type-caption text-text-muted">
                            {item.subject}
                        </p>
                    )}
                    {item.note && (
                        <p className="mt-1 rounded-kraken-sm border-l-2 border-border-accent-subtle bg-surface-card px-2 py-1 whitespace-pre-line text-text-secondary">
                            {item.note}
                        </p>
                    )}
                    <time
                        dateTime={item.at}
                        title={new Date(item.at).toLocaleString()}
                        className="type-caption text-text-subtle"
                    >
                        {timeAgo(item.at)}
                    </time>
                </li>
            ))}
        </ol>
    );
}
