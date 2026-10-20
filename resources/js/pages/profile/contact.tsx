import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import ContactController from '@/actions/App/Http/Controllers/Profile/ContactController';
import LanguageController from '@/actions/App/Http/Controllers/Profile/LanguageController';
import LinkController from '@/actions/App/Http/Controllers/Profile/LinkController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/contact';
import { index } from '@/routes/resumes';
import type { Contact, ContactLink, Language, SelectOption } from '@/types';

export default function ContactDetails({
    contact,
    languages,
    links,
    proficiencies,
    linkKinds,
}: {
    contact: Contact;
    languages: Language[];
    links: ContactLink[];
    proficiencies: SelectOption[];
    linkKinds: SelectOption[];
}) {
    return (
        <>
            <Head title="Contact details" />

            <div className="flex flex-col gap-10 p-4 lg:max-w-2xl">
                <section className="space-y-6">
                    <Heading
                        title="Contact details"
                        description="Shown at the top of every resume."
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
                                    <Button
                                        variant="ghost"
                                        type="button"
                                        asChild
                                    >
                                        <Link href={index()}>
                                            Back to profile
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Links"
                        description="Portfolio, GitHub, LinkedIn… Each resume chooses which ones to show."
                    />
                    {links.length > 0 && (
                        <ul className="divide-y rounded-lg border">
                            {links.map((link) => (
                                <li
                                    key={link.ulid}
                                    className="flex items-center justify-between gap-2 p-2 text-sm"
                                >
                                    <span className="min-w-0 truncate">
                                        <span className="font-medium">
                                            {link.label}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            {link.url}
                                        </span>
                                    </span>
                                    <DeleteButton
                                        form={LinkController.destroy.form(
                                            link.ulid,
                                        )}
                                        label={`Remove ${link.label}`}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    <Form
                        {...LinkController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-2 sm:grid-cols-[9rem_1fr_1fr_auto] sm:items-start"
                    >
                        {({ processing, errors }) => (
                            <>
                                <NativeSelect
                                    name="kind"
                                    options={linkKinds}
                                    defaultValue="portfolio"
                                    aria-label="Link type"
                                />
                                <div>
                                    <Input
                                        name="url"
                                        type="url"
                                        placeholder="https://"
                                        aria-label="Link address"
                                        required
                                    />
                                    <InputError message={errors.url} />
                                </div>
                                <div>
                                    <Input
                                        name="label"
                                        placeholder="Label (optional)"
                                        aria-label="Link label"
                                        maxLength={100}
                                    />
                                    <InputError message={errors.label} />
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Add link
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Languages"
                        description="Languages you speak. Each resume chooses which ones to show."
                    />
                    {languages.length > 0 && (
                        <ul className="divide-y rounded-lg border">
                            {languages.map((language) => (
                                <li
                                    key={language.ulid}
                                    className="flex items-center justify-between gap-2 p-2 text-sm"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {language.language}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            ({language.proficiency})
                                        </span>
                                    </span>
                                    <DeleteButton
                                        form={LanguageController.destroy.form(
                                            language.ulid,
                                        )}
                                        label={`Remove ${language.language}`}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    <Form
                        {...LanguageController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-2 sm:grid-cols-[1fr_12rem_auto] sm:items-start"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div>
                                    <Input
                                        name="language"
                                        placeholder="e.g. German"
                                        aria-label="Language"
                                        required
                                        maxLength={100}
                                    />
                                    <InputError message={errors.language} />
                                </div>
                                <NativeSelect
                                    name="proficiency"
                                    options={proficiencies}
                                    defaultValue="professional"
                                    aria-label="Proficiency"
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Add language
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}

function DeleteButton({
    form,
    label,
}: {
    form: ReturnType<typeof LinkController.destroy.form>;
    label: string;
}) {
    return (
        <Form {...form} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    size="icon"
                    variant="ghost"
                    disabled={processing}
                    aria-label={label}
                >
                    <Trash2 />
                </Button>
            )}
        </Form>
    );
}

ContactDetails.layout = {
    breadcrumbs: [
        { title: 'Profile', href: index() },
        { title: 'Contact details', href: edit() },
    ],
};
