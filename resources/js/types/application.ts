export type ApplicationStage =
    | 'saved'
    | 'preparing'
    | 'applied'
    | 'interviewing'
    | 'offer'
    | 'rejected'
    | 'withdrawn';

export type ApplyRequirement = 'video' | 'test' | 'video_and_test';

export type ReviewStatus =
    | 'not_started'
    | 'in_progress'
    | 'in_review'
    | 'changes_requested'
    | 'approved';

export type JobApplication = {
    ulid: string;
    company_name: string | null;
    role_title: string;
    location: string | null;
    employment_type: string | null;
    salary_text: string | null;
    source_url: string | null;
    /** The job platform, e.g. `linkedin.com`. */
    source: string | null;
    subject_line: string | null;
    apply_requirement: ApplyRequirement | null;
    stage: ApplicationStage;
    review_status: ReviewStatus;
    review_note: string | null;
    review_status_changed_at: string | null;
    assignee?: { ulid: string; name: string; is_you: boolean } | null;
    /** `YYYY-MM-DD` */
    deadline_on: string | null;
    is_overdue: boolean;
    applied_at: string | null;
    stage_changed_at: string | null;
    created_at: string | null;
    deleted_at: string | null;
    lock_version: number;
    can: { update: boolean; delete: boolean; restore: boolean };
};

export type ActivityItem = {
    id: number;
    actor: string;
    is_you: boolean;
    sentence: string;
    subject: string | null;
    note: string | null;
    at: string;
};

export type JobApplicationDetail = JobApplication & {
    notes: string | null;
    proposal_angle: string | null;
    owner?: string | null;
};
