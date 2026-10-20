import { Form, Head, Link } from '@inertiajs/react';
import EmploymentController from '@/actions/App/Http/Controllers/Knowledge/EmploymentController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/knowledge';
import type { Employment, SelectOption } from '@/types';

export default function EmploymentForm({
    employment,
    employmentTypes,
}: {
    employment: Employment | null;
    employmentTypes: SelectOption[];
}) {
    const isEditing = employment !== null;

    return (
        <>
            <Head title={isEditing ? 'Edit employment' : 'Add employment'} />

            <div className="flex flex-col gap-6 p-4 lg:max-w-2xl">
                <Heading
                    title={isEditing ? 'Edit employment' : 'Add employment'}
                    description="Plain text only. Leave the end month empty for your current job."
                />

                <Form
                    {...(isEditing
                        ? EmploymentController.update.form(employment.ulid)
                        : EmploymentController.store.form())}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.lock_version && (
                                <p
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                                >
                                    {errors.lock_version}
                                </p>
                            )}
                            {isEditing && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={employment.lock_version}
                                />
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="employer_name">
                                        Employer
                                    </Label>
                                    <Input
                                        id="employer_name"
                                        name="employer_name"
                                        defaultValue={
                                            employment?.employer_name ?? ''
                                        }
                                        required
                                    />
                                    <InputError
                                        message={errors.employer_name}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="title">Job title</Label>
                                    <Input
                                        id="title"
                                        name="title"
                                        defaultValue={employment?.title ?? ''}
                                        required
                                    />
                                    <InputError message={errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="employment_type">
                                        Type
                                    </Label>
                                    <NativeSelect
                                        id="employment_type"
                                        name="employment_type"
                                        options={employmentTypes}
                                        placeholder="Not specified"
                                        defaultValue={
                                            employment?.employment_type ?? ''
                                        }
                                    />
                                    <InputError
                                        message={errors.employment_type}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="location">Location</Label>
                                    <Input
                                        id="location"
                                        name="location"
                                        defaultValue={
                                            employment?.location ?? ''
                                        }
                                        placeholder="e.g. London (hybrid)"
                                    />
                                    <InputError message={errors.location} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="start_date">
                                        Start month
                                    </Label>
                                    <Input
                                        id="start_date"
                                        name="start_date"
                                        type="month"
                                        defaultValue={
                                            employment?.start_date ?? ''
                                        }
                                        required
                                    />
                                    <InputError message={errors.start_date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="end_date">End month</Label>
                                    <Input
                                        id="end_date"
                                        name="end_date"
                                        type="month"
                                        defaultValue={
                                            employment?.end_date ?? ''
                                        }
                                    />
                                    <InputError message={errors.end_date} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="summary">Summary</Label>
                                <Textarea
                                    id="summary"
                                    name="summary"
                                    rows={5}
                                    defaultValue={employment?.summary ?? ''}
                                    placeholder="What the role was about. Specific achievements go in as separate claims."
                                />
                                <InputError message={errors.summary} />
                            </div>

                            <div className="flex gap-2">
                                <Button disabled={processing}>Save</Button>
                                <Button variant="ghost" type="button" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

EmploymentForm.layout = {
    breadcrumbs: [
        { title: 'Career knowledge', href: index() },
        { title: 'Employment', href: index() },
    ],
};
