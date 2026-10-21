import { Link, useHttp } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import CredentialController from '@/actions/App/Http/Controllers/Knowledge/CredentialController';
import SkillController from '@/actions/App/Http/Controllers/Knowledge/SkillController';
import InputError from '@/components/input-error';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    credentialHeading,
    formatPeriod,
    moveItem,
} from '@/lib/resume-document';
import { edit as editContact } from '@/routes/contact';
import type {
    Credential,
    ResumeCredentialItem,
    ResumeSkillGroup,
    Skill,
} from '@/types';

/* Resume editor sections beyond experience: skills (grouped), education, certifications,
 * languages and links. Each picks and orders items from the library; facts stay read-only. */

function MoveButtons({
    index,
    count,
    onMove,
    label,
}: {
    index: number;
    count: number;
    onMove: (delta: number) => void;
    label: string;
}) {
    return (
        <>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                disabled={index === 0}
                onClick={() => onMove(-1)}
                aria-label={`Move ${label} up`}
            >
                <ArrowUp />
            </Button>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                disabled={index === count - 1}
                onClick={() => onMove(1)}
                aria-label={`Move ${label} down`}
            >
                <ArrowDown />
            </Button>
        </>
    );
}

export function SkillsSection({
    groups,
    skills,
    onChange,
    onSkillsCreated,
}: {
    groups: ResumeSkillGroup[];
    skills: Skill[];
    onChange: (groups: ResumeSkillGroup[]) => void;
    onSkillsCreated: (skills: Skill[]) => void;
}) {
    const byUlid = new Map(skills.map((skill) => [skill.ulid, skill]));
    const used = new Set(groups.flatMap((group) => group.items));
    const available = skills.filter((skill) => !used.has(skill.ulid));

    const update = (index: number, group: ResumeSkillGroup) =>
        onChange(groups.map((g, i) => (i === index ? group : g)));

    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="type-card-title text-text-primary">
                        Skills
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Group them the way this resume should read, e.g.
                        “Backend” and “Frontend”.
                    </p>
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        onChange([...groups, { label: null, items: [] }])
                    }
                >
                    <Plus /> Add group
                </Button>
            </div>

            {groups.length === 0 && (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    No skills on this resume yet. Add a group, then pick skills
                    for it.
                </p>
            )}

            {groups.map((group, index) => (
                <div key={index} className="space-y-3 rounded-lg border p-3">
                    <div className="flex items-center gap-2">
                        <Input
                            value={group.label ?? ''}
                            onChange={(event) =>
                                update(index, {
                                    ...group,
                                    label: event.target.value || null,
                                })
                            }
                            placeholder="Group name (optional), e.g. Backend"
                            aria-label="Skill group name"
                            maxLength={60}
                        />
                        <MoveButtons
                            index={index}
                            count={groups.length}
                            onMove={(delta) =>
                                onChange(moveItem(groups, index, delta))
                            }
                            label="skill group"
                        />
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            onClick={() =>
                                onChange(groups.filter((_, i) => i !== index))
                            }
                            aria-label="Remove skill group"
                        >
                            <Trash2 />
                        </Button>
                    </div>

                    <ul className="flex flex-wrap gap-2">
                        {group.items
                            .filter((ulid) => byUlid.has(ulid))
                            .map((ulid, itemIndex, visible) => (
                                <li
                                    key={ulid}
                                    className="flex items-center gap-1 rounded-full border bg-muted/40 py-0.5 pr-1 pl-3 text-sm"
                                >
                                    {byUlid.get(ulid)!.name}
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-6"
                                        disabled={itemIndex === 0}
                                        onClick={() =>
                                            update(index, {
                                                ...group,
                                                items: moveItem(
                                                    visible,
                                                    itemIndex,
                                                    -1,
                                                ),
                                            })
                                        }
                                        aria-label={`Move ${byUlid.get(ulid)!.name} left`}
                                    >
                                        <ArrowUp className="-rotate-90" />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-6"
                                        onClick={() =>
                                            update(index, {
                                                ...group,
                                                items: group.items.filter(
                                                    (u) => u !== ulid,
                                                ),
                                            })
                                        }
                                        aria-label={`Remove ${byUlid.get(ulid)!.name}`}
                                    >
                                        <X />
                                    </Button>
                                </li>
                            ))}
                    </ul>

                    <AddSkillToGroup
                        available={available}
                        onPick={(ulid) =>
                            update(index, {
                                ...group,
                                items: [...group.items, ulid],
                            })
                        }
                        onCreated={(created) => {
                            onSkillsCreated(created);
                            update(index, {
                                ...group,
                                items: [
                                    ...group.items,
                                    ...created
                                        .map((s) => s.ulid)
                                        .filter((u) => !used.has(u)),
                                ],
                            });
                        }}
                    />
                </div>
            ))}
        </section>
    );
}

function AddSkillToGroup({
    available,
    onPick,
    onCreated,
}: {
    available: Skill[];
    onPick: (ulid: string) => void;
    onCreated: (skills: Skill[]) => void;
}) {
    const [selected, setSelected] = useState('');
    const form = useHttp<{ names: string }, { skills: Skill[] }>({ names: '' });

    const create = async () => {
        try {
            const response = await form.post(SkillController.store.url());
            onCreated(response.skills);
            form.reset();
        } catch {
            // Validation errors are shown below.
        }
    };

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <div className="flex gap-2">
                <NativeSelect
                    value={selected}
                    onChange={(event) => setSelected(event.target.value)}
                    options={available.map((skill) => ({
                        value: skill.ulid,
                        label: skill.name,
                    }))}
                    placeholder={
                        available.length === 0
                            ? 'All your skills are used'
                            : 'Pick a skill…'
                    }
                    aria-label="Pick a skill from your library"
                    disabled={available.length === 0}
                />
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={selected === ''}
                    onClick={() => {
                        onPick(selected);
                        setSelected('');
                    }}
                >
                    Add
                </Button>
            </div>
            <form
                className="flex gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void create();
                }}
            >
                <Input
                    value={form.data.names}
                    onChange={(event) =>
                        form.setData('names', event.target.value)
                    }
                    placeholder="New skills, comma-separated"
                    aria-label="New skills, comma-separated"
                />
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={form.processing || form.data.names.trim() === ''}
                >
                    Create
                </Button>
            </form>
            <InputError message={form.errors.names} />
        </div>
    );
}

export function CredentialSection({
    title,
    type,
    credentials,
    items,
    onChange,
    onCreated,
    usedElsewhere,
}: {
    title: string;
    type: 'education' | 'certification';
    credentials: Credential[];
    items: ResumeCredentialItem[];
    onChange: (items: ResumeCredentialItem[]) => void;
    onCreated: (credential: Credential) => void;
    usedElsewhere: Record<string, string[]>;
}) {
    const byUlid = new Map(
        credentials.map((credential) => [credential.ulid, credential]),
    );
    const included = items.filter((item) => byUlid.has(item.credential));
    const includedUlids = new Set(included.map((item) => item.credential));
    const others = credentials.filter(
        (credential) => !includedUlids.has(credential.ulid),
    );

    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="type-card-title text-text-primary">{title}</h2>
                <AddCredentialDialog
                    type={type}
                    onCreated={(credential) => {
                        onCreated(credential);
                        onChange([
                            ...included,
                            { credential: credential.ulid },
                        ]);
                    }}
                />
            </div>

            {credentials.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nothing in your library yet.
                </p>
            ) : (
                <ul className="space-y-1">
                    {included.map((item, index) => {
                        const credential = byUlid.get(item.credential)!;

                        return (
                            <li
                                key={item.credential}
                                className="flex items-center gap-2 rounded-md bg-muted/40 px-2 py-1"
                            >
                                <Checkbox
                                    checked
                                    onCheckedChange={() =>
                                        onChange(
                                            included.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                    aria-label={`Leave out ${credential.name}`}
                                />
                                <CredentialLabel credential={credential} />
                                <MoveButtons
                                    index={index}
                                    count={included.length}
                                    onMove={(delta) =>
                                        onChange(
                                            moveItem(included, index, delta),
                                        )
                                    }
                                    label={credential.name}
                                />
                            </li>
                        );
                    })}
                    {others.map((credential) => (
                        <li
                            key={credential.ulid}
                            className="flex items-center gap-2 px-2 py-1 text-muted-foreground"
                        >
                            <Checkbox
                                checked={false}
                                onCheckedChange={() =>
                                    onChange([
                                        ...included,
                                        { credential: credential.ulid },
                                    ])
                                }
                                aria-label={`Include ${credential.name}`}
                            />
                            <CredentialLabel credential={credential} />
                            {usedElsewhere[credential.ulid] && (
                                <span className="text-xs">
                                    (in{' '}
                                    {usedElsewhere[credential.ulid].join(', ')})
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function CredentialLabel({ credential }: { credential: Credential }) {
    return (
        <span className="min-w-0 flex-1 text-sm">
            {credentialHeading(credential)}
            <span className="ml-2 text-muted-foreground">
                {formatPeriod(credential.start_date, credential.end_date)}
            </span>
            {credential.status !== 'verified' && (
                <span className="ml-2">
                    <KnowledgeStatusBadge status={credential.status} />
                </span>
            )}
        </span>
    );
}

function AddCredentialDialog({
    type,
    onCreated,
}: {
    type: 'education' | 'certification';
    onCreated: (credential: Credential) => void;
}) {
    const [open, setOpen] = useState(false);
    const isEducation = type === 'education';
    const form = useHttp<Record<string, string>, { credential: Credential }>({
        type,
        institution: '',
        name: '',
        field_of_study: '',
        start_date: '',
        end_date: '',
    });

    const submit = async () => {
        try {
            const response = await form.post(CredentialController.store.url());
            onCreated(response.credential);
            form.reset(
                'institution',
                'name',
                'field_of_study',
                'start_date',
                'end_date',
            );
            setOpen(false);
        } catch {
            // Validation errors are shown in the dialog.
        }
    };

    const field = (
        key: string,
        label: string,
        props: React.ComponentProps<typeof Input> = {},
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`credential_${key}`}>{label}</Label>
            <Input
                id={`credential_${key}`}
                value={form.data[key] ?? ''}
                onChange={(event) => form.setData(key, event.target.value)}
                {...props}
            />
            <InputError message={form.errors[key]} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <Plus />{' '}
                    {isEducation ? 'Add education' : 'Add certification'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {isEducation ? 'Add education' : 'Add a certification'}
                    </DialogTitle>
                    <DialogDescription>
                        Saved to your library and added to this resume.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    {field(
                        'name',
                        isEducation ? 'Degree or qualification' : 'Certificate',
                        { required: true },
                    )}
                    {field(
                        'institution',
                        isEducation ? 'School or university' : 'Issuer',
                        { required: true },
                    )}
                    {isEducation && field('field_of_study', 'Field of study')}
                    {isEducation &&
                        field('start_date', 'Start month', { type: 'month' })}
                    {field(
                        'end_date',
                        isEducation ? 'End month' : 'Awarded (month)',
                        { type: 'month' },
                    )}
                    <DialogFooter className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            {isEducation
                                ? 'Add education'
                                : 'Add certification'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Pick and order a few contact-level items for this resume (languages, links).
 */
export function PickListSection({
    title,
    options,
    selected,
    onChange,
    emptyHint,
}: {
    title: string;
    options: { ulid: string; label: string }[];
    selected: string[];
    onChange: (selected: string[]) => void;
    emptyHint: string;
}) {
    const byUlid = new Map(options.map((option) => [option.ulid, option]));
    const included = selected.filter((ulid) => byUlid.has(ulid));
    const others = options.filter((option) => !included.includes(option.ulid));

    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="type-card-title text-text-primary">{title}</h2>
                <Button type="button" size="sm" variant="ghost" asChild>
                    <Link href={editContact()}>Manage in contact details</Link>
                </Button>
            </div>

            {options.length === 0 ? (
                <p className="text-sm text-muted-foreground">{emptyHint}</p>
            ) : (
                <ul className="space-y-1">
                    {included.map((ulid, index) => (
                        <li
                            key={ulid}
                            className="flex items-center gap-2 rounded-md bg-muted/40 px-2 py-1"
                        >
                            <Checkbox
                                checked
                                onCheckedChange={() =>
                                    onChange(included.filter((u) => u !== ulid))
                                }
                                aria-label={`Leave out ${byUlid.get(ulid)!.label}`}
                            />
                            <span className="min-w-0 flex-1 text-sm">
                                {byUlid.get(ulid)!.label}
                            </span>
                            <MoveButtons
                                index={index}
                                count={included.length}
                                onMove={(delta) =>
                                    onChange(moveItem(included, index, delta))
                                }
                                label={byUlid.get(ulid)!.label}
                            />
                        </li>
                    ))}
                    {others.map((option) => (
                        <li
                            key={option.ulid}
                            className="flex items-center gap-2 px-2 py-1 text-sm text-muted-foreground"
                        >
                            <Checkbox
                                checked={false}
                                onCheckedChange={() =>
                                    onChange([...included, option.ulid])
                                }
                                aria-label={`Include ${option.label}`}
                            />
                            {option.label}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
