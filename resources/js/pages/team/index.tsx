import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import TeamController from '@/actions/App/Http/Controllers/TeamController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/team';

type Va = {
    ulid: string;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
};

type PasswordLink = {
    email: string;
    url: string;
    expiresInMinutes: number;
};

export default function Team({
    vas,
    seatLimit,
    activeVaCount,
}: {
    vas: Va[];
    seatLimit: number;
    activeVaCount: number;
}) {
    const passwordLink = usePage().flash.passwordLink as
        | PasswordLink
        | undefined;
    const seatsFull = activeVaCount >= seatLimit;

    return (
        <>
            <Head title="Team" />

            <div className="flex flex-col gap-8 p-4 lg:max-w-3xl">
                <Heading
                    title="Team"
                    description={`Virtual assistants who prepare applications. ${activeVaCount} of ${seatLimit} seats in use.`}
                />

                {passwordLink && <PasswordLinkNotice link={passwordLink} />}

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Add a VA"
                        description="You will get a link to share with them so they can set their own password."
                    />

                    <Form
                        {...TeamController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoComplete="off"
                                        disabled={seatsFull}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        required
                                        autoComplete="off"
                                        disabled={seatsFull}
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <Button
                                    disabled={processing || seatsFull}
                                    data-test="add-va-button"
                                >
                                    Add VA
                                </Button>
                            </>
                        )}
                    </Form>

                    {seatsFull && (
                        <p className="text-sm text-muted-foreground">
                            All seats are in use. Deactivate a VA to add
                            another.
                        </p>
                    )}
                </section>

                <section className="space-y-4">
                    <Heading variant="small" title="VA accounts" />

                    {vas.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No VAs yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {vas.map((va) => (
                                <VaRow
                                    key={va.ulid}
                                    va={va}
                                    seatsFull={seatsFull}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

function VaRow({ va, seatsFull }: { va: Va; seatsFull: boolean }) {
    return (
        <li className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <div className="flex items-center gap-2">
                    <span className="truncate font-medium">{va.name}</span>
                    <Badge variant={va.is_active ? 'secondary' : 'outline'}>
                        {va.is_active ? 'Active' : 'Deactivated'}
                    </Badge>
                </div>
                <p className="truncate text-sm text-muted-foreground">
                    {va.email} ·{' '}
                    {va.last_login_at
                        ? `last signed in ${new Date(va.last_login_at).toLocaleDateString()}`
                        : 'never signed in'}
                </p>
            </div>

            <div className="flex shrink-0 gap-2">
                {va.is_active && (
                    <Form
                        {...TeamController.passwordLink.form(va.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                New password link
                            </Button>
                        )}
                    </Form>
                )}

                {va.is_active ? (
                    <Form
                        {...TeamController.deactivate.form(va.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant="destructive"
                                size="sm"
                                disabled={processing}
                            >
                                Deactivate
                            </Button>
                        )}
                    </Form>
                ) : (
                    <Form
                        {...TeamController.reactivate.form(va.ulid)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="flex flex-col items-end gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={processing || seatsFull}
                                >
                                    Reactivate
                                </Button>
                                <InputError message={errors.seats} />
                            </div>
                        )}
                    </Form>
                )}
            </div>
        </li>
    );
}

function PasswordLinkNotice({ link }: { link: PasswordLink }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(link.url);
        setCopied(true);
    };

    return (
        <div
            role="status"
            className="space-y-2 rounded-kraken-sm border border-border-accent-subtle bg-surface-accent-subtle p-4 type-body"
        >
            <p>
                Send this link to <strong>{link.email}</strong> so they can set
                their password. It is shown only once and expires in{' '}
                {link.expiresInMinutes} minutes.
            </p>
            <div className="flex gap-2">
                <Input
                    readOnly
                    value={link.url}
                    aria-label="Set-password link"
                    onFocus={(event) => event.currentTarget.select()}
                />
                <Button type="button" variant="outline" onClick={copy}>
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

Team.layout = {
    breadcrumbs: [
        {
            title: 'Team',
            href: index(),
        },
    ],
};
