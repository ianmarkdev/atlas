import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import { companyLabel } from '@/lib/applications';
import { cn } from '@/lib/utils';
import type { JobApplication } from '@/types';

/** Row and card highlight for an important application: an accent bar on the leading edge. */
export const importantHighlight =
    '[box-shadow:inset_3px_0_0_var(--color-border-accent)]';

/**
 * The star: flags or unflags the application at once for someone who may edit it, and is a
 * plain mark (only when flagged) for someone who may not.
 */
export function ImportantToggle({
    application,
    className,
}: {
    application: Pick<
        JobApplication,
        'ulid' | 'is_important' | 'company_name' | 'role_title' | 'can'
    >;
    className?: string;
}) {
    const label = `${companyLabel(application.company_name)}, ${application.role_title}`;

    if (!application.can.update) {
        return application.is_important ? (
            <Star
                role="img"
                aria-label="Important"
                className={cn(
                    'size-4 fill-current text-text-accent',
                    className,
                )}
            />
        ) : null;
    }

    return (
        <button
            type="button"
            aria-pressed={application.is_important}
            aria-label={
                application.is_important
                    ? `Important: ${label}. Remove the mark`
                    : `Mark ${label} as important`
            }
            title={
                application.is_important
                    ? 'Important. Click to remove the mark'
                    : 'Mark as important'
            }
            onClick={() =>
                router.patch(
                    JobApplicationController.important.url(application.ulid),
                    { is_important: !application.is_important },
                    { preserveScroll: true, preserveState: true },
                )
            }
            className={cn(
                'inline-flex shrink-0 cursor-pointer rounded-kraken-sm p-0.5 focus-ring transition-colors duration-fast ease-out',
                application.is_important
                    ? 'text-text-accent'
                    : 'text-text-subtle hover:text-text-primary',
                className,
            )}
        >
            <Star
                aria-hidden
                className={cn(
                    'size-4',
                    application.is_important && 'fill-current',
                )}
            />
        </button>
    );
}
