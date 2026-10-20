import { Form, Head, Link } from '@inertiajs/react';
import ContactController from '@/actions/App/Http/Controllers/Profile/ContactController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/contact';
import { index } from '@/routes/resumes';
import type { Contact } from '@/types';

export default function ContactDetails({ contact }: { contact: Contact }) {
    return (
        <>
            <Head title="Contact details" />

            <div className="flex flex-col gap-6 p-4 lg:max-w-2xl">
                <Heading
                    title="Contact details"
                    description="Shown at the top of every resume. Links and languages follow in a later update."
                />

                <Form
                    {...ContactController.update.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="display_name">Name</Label>
                                <Input
                                    id="display_name"
                                    name="display_name"
                                    defaultValue={contact.display_name}
                                    required
                                />
                                <InputError message={errors.display_name} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={contact.email ?? ''}
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">Phone</Label>
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={contact.phone ?? ''}
                                    />
                                    <InputError message={errors.phone} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="location">Location</Label>
                                <Input
                                    id="location"
                                    name="location"
                                    defaultValue={contact.location ?? ''}
                                    placeholder="e.g. London, UK (open to remote)"
                                />
                                <InputError message={errors.location} />
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

ContactDetails.layout = {
    breadcrumbs: [
        { title: 'Profile', href: index() },
        { title: 'Contact details', href: edit() },
    ],
};
