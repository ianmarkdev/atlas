import { Form, Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import JobApplicationController from '@/actions/App/Http/Controllers/Applications/JobApplicationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { wordCount } from '@/lib/resume-document';
import { index } from '@/routes/applications';
import type { JobApplicationDetail, SelectOption } from '@/types';

const MAX_JOB_DESCRIPTION_LENGTH = 50000;
const MAX_PROPOSAL_ANGLE_LENGTH = 5000;

export default function ApplicationForm({
    application,
    jobDescription,
    stages,
    employmentTypes,
    applyRequirements,
}: {
    application: JobApplicationDetail | null;
    jobDescription: string | null;
    stages: SelectOption[];
    employmentTypes: SelectOption[];
    applyRequirements: SelectOption[];
}) {
    const isEditing = application !== null;
    const [description, setDescription] = useState(jobDescription ?? '');
    const descriptionChanged =
        isEditing && jobDescription !== null && description !== jobDescription;

    return (
        <>
            <Head title={isEditing ? 'Edit application' : 'Add application'} />

            <div className="flex flex-col gap-6 p-4 lg:max-w-3xl lg:p-6">
                <Heading
                    title={isEditing ? 'Edit application' : 'Add application'}
                    description="Plain text only: paste the job description as it appears in the ad."
                />

                <Form
                    {...(isEditing
                        ? JobApplicationController.update.form(application.ulid)
                        : JobApplicationController.store.form())}
                    className="space-y-8"
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.lock_version && (
                                <p
                                    role="alert"
                                    className="rounded-kraken-sm border border-border-error bg-surface-error-subtle p-3 text-sm text-text-error"
                                >
                                    {errors.lock_version}
                                </p>
                            )}
                            {isEditing && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={application.lock_version}
                                />
                            )}

                            <fieldset className="grid gap-4 sm:grid-cols-2">
                                <legend className="mb-3 type-label text-text-label uppercase">
                                    The job
                                </legend>
                                <Field
                                    id="company_name"
                                    label="Company"
                                    error={errors.company_name}
                                >
                                    <Input
                                        id="company_name"
                                        name="company_name"
                                        defaultValue={
                                            application?.company_name ?? ''
                                        }
                                        required
                                        maxLength={200}
                                        autoFocus={!isEditing}
                                    />
                                </Field>
                                <Field
                                    id="role_title"
                                    label="Role"
                                    error={errors.role_title}
                                >
                                    <Input
                                        id="role_title"
                                        name="role_title"
                                        defaultValue={
                                            application?.role_title ?? ''
                                        }
                                        required
                                        maxLength={200}
                                        placeholder="e.g. Senior Backend Engineer"
                                    />
                                </Field>
                                <Field
                                    id="source_url"
                                    label="Link to the ad (optional)"
                                    error={errors.source_url}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="source_url"
                                        name="source_url"
                                        type="url"
                                        inputMode="url"
                                        defaultValue={
                                            application?.source_url ?? ''
                                        }
                                        placeholder="https://"
                                    />
                                </Field>
                                <Field
                                    id="location"
                                    label="Location"
                                    error={errors.location}
                                >
                                    <Input
                                        id="location"
                                        name="location"
                                        defaultValue={
                                            application?.location ?? ''
                                        }
                                        placeholder="e.g. London (hybrid), Remote"
                                        maxLength={200}
                                    />
                                </Field>
                                <Field
                                    id="employment_type"
                                    label="Type"
                                    error={errors.employment_type}
                                >
                                    <NativeSelect
                                        id="employment_type"
                                        name="employment_type"
                                        options={employmentTypes}
                                        placeholder="Not specified"
                                        defaultValue={
                                            application?.employment_type ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    id="salary_text"
                                    label="Salary (as advertised)"
                                    error={errors.salary_text}
                                >
                                    <Input
                                        id="salary_text"
                                        name="salary_text"
                                        defaultValue={
                                            application?.salary_text ?? ''
                                        }
                                        placeholder="e.g. £80–90k + bonus"
                                        maxLength={200}
                                    />
                                </Field>
                                <Field
                                    id="deadline_on"
                                    label="Deadline (optional)"
                                    error={errors.deadline_on}
                                >
                                    <Input
                                        id="deadline_on"
                                        name="deadline_on"
                                        type="date"
                                        defaultValue={
                                            application?.deadline_on ?? ''
                                        }
                                    />
                                </Field>
                                {!isEditing && (
                                    <Field
                                        id="stage"
                                        label="Stage"
                                        error={errors.stage}
                                    >
                                        <NativeSelect
                                            id="stage"
                                            name="stage"
                                            options={stages}
                                            defaultValue="saved"
                                        />
                                    </Field>
                                )}
                            </fieldset>

                            <fieldset className="grid gap-4 sm:grid-cols-2">
                                <legend className="mb-3 type-label text-text-label uppercase">
                                    How to apply
                                </legend>
                                <Field
                                    id="source"
                                    label="Source (optional)"
                                    error={errors.source}
                                >
                                    <Input
                                        id="source"
                                        name="source"
                                        defaultValue={application?.source ?? ''}
                                        placeholder="e.g. linkedin.com"
                                        maxLength={200}
                                    />
                                </Field>
                                <Field
                                    id="apply_requirement"
                                    label="Required to apply"
                                    error={errors.apply_requirement}
                                >
                                    <NativeSelect
                                        id="apply_requirement"
                                        name="apply_requirement"
                                        options={applyRequirements}
                                        placeholder="None"
                                        defaultValue={
                                            application?.apply_requirement ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    id="subject_line"
                                    label="Email subject line (optional)"
                                    error={errors.subject_line}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="subject_line"
                                        name="subject_line"
                                        defaultValue={
                                            application?.subject_line ?? ''
                                        }
                                        placeholder="If the listing asks for one"
                                        maxLength={200}
                                    />
                                </Field>
                                <Field
                                    id="proposal_angle"
                                    label="Proposal angle (optional)"
                                    error={errors.proposal_angle}
                                    className="sm:col-span-2"
                                >
                                    <Textarea
                                        id="proposal_angle"
                                        name="proposal_angle"
                                        rows={3}
                                        defaultValue={
                                            application?.proposal_angle ?? ''
                                        }
                                        maxLength={MAX_PROPOSAL_ANGLE_LENGTH}
                                        placeholder="How to pitch the candidate for this role"
                                    />
                                </Field>
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="job_description">
                                    Job description
                                </Label>
                                <Textarea
                                    id="job_description"
                                    name="job_description"
                                    rows={16}
                                    value={description}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                    maxLength={MAX_JOB_DESCRIPTION_LENGTH}
                                    placeholder="Paste the full job ad: responsibilities, requirements, benefits…"
                                />
                                <p className="type-caption text-text-muted">
                                    {wordCount(description)} words
                                    {descriptionChanged &&
                                        ' · the earlier version is kept'}
                                </p>
                                <InputError message={errors.job_description} />
                            </div>

                            <Field
                                id="notes"
                                label="Notes (optional)"
                                error={errors.notes}
                            >
                                <Textarea
                                    id="notes"
                                    name="notes"
                                    rows={4}
                                    defaultValue={application?.notes ?? ''}
                                    maxLength={5000}
                                    placeholder="Recruiter name, referral, what stood out…"
                                />
                            </Field>

                            <div className="grid gap-1">
                                <div className="flex items-center gap-3">
                                    {/* value="1": Laravel's boolean rule refuses the default "on". */}
                                    <Checkbox
                                        id="is_important"
                                        name="is_important"
                                        value="1"
                                        defaultChecked={
                                            application?.is_important ?? false
                                        }
                                    />
                                    <Label htmlFor="is_important">
                                        Important
                                    </Label>
                                </div>
                                <p className="type-caption text-text-muted">
                                    Starred on the board and table, and
                                    highlighted in Orbit.
                                </p>
                                <InputError message={errors.is_important} />
                            </div>

                            <div className="flex gap-2">
                                <Button disabled={processing}>
                                    {isEditing ? 'Save' : 'Add application'}
                                </Button>
                                <Button variant="ghost" type="button" asChild>
                                    <Link
                                        href={
                                            isEditing
                                                ? JobApplicationController.show(
                                                      application.ulid,
                                                  )
                                                : index()
                                        }
                                    >
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function Field({
    id,
    label,
    error,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={className ? `grid gap-2 ${className}` : 'grid gap-2'}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

ApplicationForm.layout = {
    breadcrumbs: [
        { title: 'Applications', href: index() },
        { title: 'Application', href: index() },
    ],
};
