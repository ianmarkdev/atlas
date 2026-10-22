import { router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import { NativeSelect } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { companyLabel, deadlineLabel } from '@/lib/applications';
import { cn } from '@/lib/utils';
import type { ApplicationStage, JobApplication, SelectOption } from '@/types';

const stageVariant: Record<
    ApplicationStage,
    'default' | 'secondary' | 'outline'
> = {
    saved: 'outline',
    preparing: 'outline',
    applied: 'secondary',
    interviewing: 'secondary',
    offer: 'default',
    rejected: 'outline',
    withdrawn: 'outline',
};

export function StageBadge({
    stage,
    stages,
}: {
    stage: ApplicationStage;
    stages: SelectOption[];
}) {
    return (
        <Badge variant={stageVariant[stage]}>
            {stages.find((option) => option.value === stage)?.label ?? stage}
        </Badge>
    );
}

/**
 * Moves the application to another stage as soon as a new one is picked.
 */
export function StageSelect({
    application,
    stages,
    className,
}: {
    application: Pick<JobApplication, 'ulid' | 'stage' | 'company_name'>;
    stages: SelectOption[];
    className?: string;
}) {
    return (
        <NativeSelect
            aria-label={`Stage of the ${companyLabel(application.company_name)} application`}
            className={cn('h-9 w-auto px-2.5 text-sm', className)}
            value={application.stage}
            options={stages}
            onChange={(event) =>
                router.patch(
                    JobApplicationController.stage.url(application.ulid),
                    { stage: event.target.value },
                    { preserveScroll: true, preserveState: true },
                )
            }
        />
    );
}

export function DeadlineBadge({
    application,
}: {
    application: Pick<JobApplication, 'deadline_on' | 'stage'>;
}) {
    const deadline = deadlineLabel(application);

    if (deadline === null) {
        return null;
    }

    return (
        <Badge
            variant={
                deadline.tone === 'overdue'
                    ? 'destructive'
                    : deadline.tone === 'soon'
                      ? 'secondary'
                      : 'outline'
            }
        >
            <CalendarClock aria-hidden /> {deadline.text}
        </Badge>
    );
}
