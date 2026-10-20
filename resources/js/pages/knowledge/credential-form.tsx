import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CredentialController from '@/actions/App/Http/Controllers/Knowledge/CredentialController';
import Heading from '@/components/heading';
import { ProposingNotice } from '@/components/proposing-notice';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/knowledge';
import type { Credential } from '@/types';

export default function CredentialForm({
    credential,
    defaultType,
    proposing = false,
}: {
    proposing?: boolean;
    credential: Credential | null;
    defaultType: 'education' | 'certification';
}) {
    const isEditing = credential !== null;
    const [type, setType] = useState(credential?.type ?? defaultType);
    const isEducation = type === 'education';
    const noun = isEducation ? 'education' : 'certification';

    return (
        <>
            <Head title={isEditing ? `Edit ${noun}` : `Add ${noun}`} />

            <div className="flex flex-col gap-6 p-4 lg:max-w-2xl">
                <Heading
                    title={isEditing ? `Edit ${noun}` : `Add ${noun}`}
                    description="Plain text only. This is the fact; each resume chooses whether to show it."
                />

                <Form
                    {...(isEditing
                        ? CredentialController.update.form(credential.ulid)
                        : CredentialController.store.form())}
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
                                    value={credential.lock_version}
                                />
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="type">Type</Label>
                                <NativeSelect
                                    id="type"
                                    name="type"
                                    value={type}
                                    onChange={(event) =>
                                        setType(
                                            event.target.value as typeof type,
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'education',
                                            label: 'Education',
                                        },
                                        {
                                            value: 'certification',
                                            label: 'Certification',
                                        },
                                    ]}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        {isEducation
                                            ? 'Degree or qualification'
                                            : 'Certificate'}
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={credential?.name ?? ''}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="institution">
                                        {isEducation
                                            ? 'School or university'
                                            : 'Issuer'}
                                    </Label>
                                    <Input
                                        id="institution"
                                        name="institution"
                                        defaultValue={
                                            credential?.institution ?? ''
                                        }
                                        required
                                    />
                                    <InputError message={errors.institution} />
                                </div>

                                {isEducation && (
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="field_of_study">
                                            Field of study
                                        </Label>
                                        <Input
                                            id="field_of_study"
                                            name="field_of_study"
                                            defaultValue={
                                                credential?.field_of_study ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.field_of_study}
                                        />
                                    </div>
                                )}

                                {isEducation && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="start_date">
                                            Start month
                                        </Label>
                                        <Input
                                            id="start_date"
                                            name="start_date"
                                            type="month"
                                            defaultValue={
                                                credential?.start_date ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.start_date}
                                        />
                                    </div>
                                )}
                                <div className="grid gap-2">
                                    <Label htmlFor="end_date">
                                        {isEducation
                                            ? 'End month'
                                            : 'Awarded (month)'}
                                    </Label>
                                    <Input
                                        id="end_date"
                                        name="end_date"
                                        type="month"
                                        defaultValue={
                                            credential?.end_date ?? ''
                                        }
                                    />
                                    <InputError message={errors.end_date} />
                                </div>
                                {!isEducation && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="expires_on">
                                            Expires (month)
                                        </Label>
                                        <Input
                                            id="expires_on"
                                            name="expires_on"
                                            type="month"
                                            defaultValue={
                                                credential?.expires_on ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.expires_on}
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="credential_url">
                                    {isEducation
                                        ? 'Link (optional)'
                                        : 'Verification link (optional)'}
                                </Label>
                                <Input
                                    id="credential_url"
                                    name="credential_url"
                                    type="url"
                                    defaultValue={
                                        credential?.credential_url ?? ''
                                    }
                                    placeholder="https://"
                                />
                                <InputError message={errors.credential_url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="summary">
                                    Short description (optional)
                                </Label>
                                <Textarea
                                    id="summary"
                                    name="summary"
                                    rows={3}
                                    defaultValue={credential?.summary ?? ''}
                                />
                                <InputError message={errors.summary} />
                            </div>

                            {proposing && (
                                <ProposingNotice error={errors.rationale} />
                            )}

                            <div className="flex gap-2">
                                <Button disabled={processing}>
                                    {proposing ? 'Send suggestion' : 'Save'}
                                </Button>
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

CredentialForm.layout = {
    breadcrumbs: [
        { title: 'Library', href: index() },
        { title: 'Education & certifications', href: index() },
    ],
};
