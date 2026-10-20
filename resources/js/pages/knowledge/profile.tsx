import { Form, Head } from '@inertiajs/react';
import CandidateProfileController from '@/actions/App/Http/Controllers/Knowledge/CandidateProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/knowledge';
import { edit } from '@/routes/knowledge/profile';

export default function CandidateProfile({
    profile,
}: {
    profile: { display_name: string; headline: string | null };
}) {
    return (
        <>
            <Head title="Edit profile" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-2xl">
                <Heading
                    title="Career profile"
                    description="How you appear at the top of your knowledge base and in generated answers."
                />

                <Form
                    {...CandidateProfileController.update.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="display_name">
                                    Display name
                                </Label>
                                <Input
                                    id="display_name"
                                    name="display_name"
                                    defaultValue={profile.display_name}
                                    required
                                />
                                <InputError message={errors.display_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="headline">Headline</Label>
                                <Input
                                    id="headline"
                                    name="headline"
                                    defaultValue={profile.headline ?? ''}
                                    placeholder="e.g. Senior Laravel developer"
                                />
                                <InputError message={errors.headline} />
                            </div>

                            <Button disabled={processing}>Save</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CandidateProfile.layout = {
    breadcrumbs: [
        { title: 'Career knowledge', href: index() },
        { title: 'Profile', href: edit() },
    ],
};
