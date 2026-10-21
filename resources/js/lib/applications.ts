import type { ApplicationStage, JobApplication } from '@/types';

/** Stages shown as board columns, in pipeline order. */
export const openStages: ApplicationStage[] = [
    'saved',
    'preparing',
    'applied',
    'interviewing',
    'offer',
];

export function isClosed(stage: ApplicationStage): boolean {
    return stage === 'rejected' || stage === 'withdrawn';
}

/**
 * Whole days from `today` until a `YYYY-MM-DD` deadline (negative when it has passed).
 */
export function daysUntil(deadline: string, today: Date = new Date()): number {
    const [year, month, day] = deadline.split('-').map(Number);
    const due = Date.UTC(year, month - 1, day);
    const start = Date.UTC(
        today.getFullYear(),
        today.getMonth(),
        today.getDate(),
    );

    return Math.round((due - start) / 86_400_000);
}

/**
 * "Overdue by 2 days", "Due today", "Due in 3 days", "Due 12 Nov" — or null when there is no
 * deadline or the application was already sent or closed.
 */
export function deadlineLabel(
    application: Pick<JobApplication, 'deadline_on' | 'stage'>,
    today: Date = new Date(),
): { text: string; tone: 'overdue' | 'soon' | 'later' } | null {
    if (
        application.deadline_on === null ||
        isClosed(application.stage) ||
        ['applied', 'interviewing', 'offer'].includes(application.stage)
    ) {
        return null;
    }

    const days = daysUntil(application.deadline_on, today);

    if (days < 0) {
        return {
            text: `Overdue by ${-days} ${days === -1 ? 'day' : 'days'}`,
            tone: 'overdue',
        };
    }

    if (days === 0) {
        return { text: 'Due today', tone: 'soon' };
    }

    if (days <= 3) {
        return {
            text: `Due in ${days} ${days === 1 ? 'day' : 'days'}`,
            tone: 'soon',
        };
    }

    return { text: `Due ${formatDay(application.deadline_on)}`, tone: 'later' };
}

export function formatDay(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

/**
 * Board columns: the open stages with their applications, plus closed ones apart.
 */
export function groupByStage(applications: JobApplication[]): {
    columns: { stage: ApplicationStage; items: JobApplication[] }[];
    closed: JobApplication[];
} {
    return {
        columns: openStages.map((stage) => ({
            stage,
            items: applications.filter((a) => a.stage === stage),
        })),
        closed: applications.filter((a) => isClosed(a.stage)),
    };
}
