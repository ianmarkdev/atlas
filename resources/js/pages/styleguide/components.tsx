import { Head } from '@inertiajs/react';
import { Info, Plus, TriangleAlert } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { KnowledgeStatusBadge } from '@/components/knowledge-status-badge';
import { NativeSelect } from '@/components/native-select';
import { StyleguideHeader, StyleguideSection } from '@/components/styleguide';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const seniorityOptions = [
    { value: 'junior', label: 'Junior' },
    { value: 'mid', label: 'Mid' },
    { value: 'senior', label: 'Senior' },
];

// Local-only reference page for the restyled shadcn components. Hover, focus
// and pressed states are live: tab through or point at them.
export default function Components() {
    return (
        <>
            <Head title="Components" />
            <main className="mx-auto flex max-w-7xl flex-col gap-20 px-4 py-12 md:px-10 md:py-20">
                <StyleguideHeader
                    title="Components"
                    description="The app's components in the Kraken style. Compare with the Kraken site's Dev Handoff / 03 Components."
                />

                <StyleguideSection title="Button">
                    <div className="flex flex-wrap items-center gap-6">
                        <Button>Save resume</Button>
                        <Button disabled>Save resume</Button>
                        <Button variant="outline">Go back</Button>
                        <Button variant="outline" disabled>
                            Go back
                        </Button>
                        <Button variant="secondary">Preview</Button>
                        <Button variant="ghost">Cancel</Button>
                        <Button variant="destructive">Delete</Button>
                        <Button variant="link">Read more</Button>
                    </div>
                    <div className="flex flex-wrap items-center gap-6">
                        <Button size="sm">
                            <Plus /> Small
                        </Button>
                        <Button>
                            <Plus /> Default
                        </Button>
                        <Button size="lg">
                            <Plus /> Large
                        </Button>
                        <Button size="icon" aria-label="Add">
                            <Plus />
                        </Button>
                        <Button size="icon" variant="ghost" aria-label="Add">
                            <Plus />
                        </Button>
                    </div>
                    <div className="flex max-w-152 flex-col gap-6">
                        <Button size="block">Log in</Button>
                        <Button size="block" disabled>
                            Log in
                        </Button>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Badge">
                    <div className="flex flex-wrap items-center gap-4">
                        <Badge>Default</Badge>
                        <Badge variant="secondary">Secondary</Badge>
                        <Badge variant="outline">Outline</Badge>
                        <Badge variant="destructive">Destructive</Badge>
                        <KnowledgeStatusBadge status="verified" />
                        <KnowledgeStatusBadge status="proposed" />
                        <KnowledgeStatusBadge status="rejected" />
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Text Field">
                    <div className="grid max-w-314 gap-x-10 gap-y-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="sg-name">Name</Label>
                            <Input id="sg-name" placeholder="Full name" />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sg-name-filled">Name</Label>
                            <Input
                                id="sg-name-filled"
                                placeholder="Full name"
                                defaultValue="Ada Lovelace"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sg-name-error">Name</Label>
                            <Input
                                id="sg-name-error"
                                placeholder="Full name"
                                aria-invalid
                            />
                            <InputError message="Please enter your name" />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sg-name-disabled">Name</Label>
                            <Input
                                id="sg-name-disabled"
                                placeholder="Full name"
                                disabled
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sg-summary">Summary</Label>
                            <Textarea
                                id="sg-summary"
                                placeholder="A line or two about the role"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="sg-summary-error">Summary</Label>
                            <Textarea
                                id="sg-summary-error"
                                placeholder="A line or two about the role"
                                aria-invalid
                            />
                            <InputError message="Please add a summary" />
                        </div>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Select">
                    <div className="grid max-w-314 gap-x-10 gap-y-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="sg-native">
                                Seniority (native)
                            </Label>
                            <NativeSelect
                                id="sg-native"
                                options={seniorityOptions}
                                placeholder="Choose one"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label>Seniority</Label>
                            <Select>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose one" />
                                </SelectTrigger>
                                <SelectContent>
                                    {seniorityOptions.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Checkbox and toggle">
                    <div className="flex flex-wrap items-center gap-10">
                        <div className="flex items-center gap-3">
                            <Checkbox id="sg-remember" />
                            <Label htmlFor="sg-remember">Remember me</Label>
                        </div>
                        <div className="flex items-center gap-3">
                            <Checkbox id="sg-checked" defaultChecked />
                            <Label htmlFor="sg-checked">Verified only</Label>
                        </div>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            defaultValue="all"
                        >
                            <ToggleGroupItem value="all" className="px-4">
                                All
                            </ToggleGroupItem>
                            <ToggleGroupItem value="verified" className="px-4">
                                Verified
                            </ToggleGroupItem>
                            <ToggleGroupItem value="proposed" className="px-4">
                                Proposed
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Card">
                    <div className="grid gap-6 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Senior Engineer</CardTitle>
                                <CardDescription>
                                    Acme Corp · 2021 to now
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="type-body text-text-secondary">
                                Led the move to a typed API layer across four
                                product teams.
                            </CardContent>
                            <CardFooter className="gap-4">
                                <Button size="sm">Edit</Button>
                                <TextLink href="#">View history</TextLink>
                            </CardFooter>
                        </Card>
                        <Card>
                            <CardHeader>
                                <Skeleton className="h-5 w-40" />
                                <Skeleton className="h-4 w-56" />
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                <Skeleton className="h-4 w-full" />
                                <Skeleton className="h-4 w-3/4" />
                            </CardContent>
                        </Card>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Alert">
                    <div className="flex max-w-152 flex-col gap-4">
                        <Alert>
                            <Info />
                            <AlertTitle>Saved as a suggestion</AlertTitle>
                            <AlertDescription>
                                The candidate approves it before it applies.
                            </AlertDescription>
                        </Alert>
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertTitle>Could not save</AlertTitle>
                            <AlertDescription>
                                Check the highlighted fields and try again.
                            </AlertDescription>
                        </Alert>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Heading and links">
                    <div className="flex flex-col gap-10">
                        <Heading
                            title="Profile"
                            description="Update your name and email address"
                        />
                        <Heading
                            variant="small"
                            title="Delete account"
                            description="Delete your account and all of its resources"
                        />
                        <p className="type-body-l text-text-secondary">
                            Forgot your password?{' '}
                            <TextLink href="#">Reset it</TextLink>
                        </p>
                    </div>
                </StyleguideSection>

                <StyleguideSection title="Dialog and tooltip">
                    <div className="flex flex-wrap items-center gap-6">
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="outline">Open dialog</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Archive this job?</DialogTitle>
                                    <DialogDescription>
                                        It leaves every resume but stays in the
                                        library history.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button variant="ghost">Cancel</Button>
                                    </DialogClose>
                                    <Button>Archive</Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="secondary">Hover me</Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                Shown on hover and focus
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </StyleguideSection>
            </main>
        </>
    );
}
