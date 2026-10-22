import { Link, usePage } from '@inertiajs/react';
import {
    BookUser,
    GitPullRequestArrow,
    IdCard,
    LayoutGrid,
    MessageSquareText,
    PenLine,
    Send,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as applications } from '@/routes/applications';
import { index as knowledge } from '@/routes/knowledge';
import { index as answers } from '@/routes/knowledge/answers';
import { index as writingStyle } from '@/routes/knowledge/preferences';
import { index as proposals } from '@/routes/knowledge/proposals';
import { index as profile } from '@/routes/resumes';
import { index as team } from '@/routes/team';
import type { NavItem } from '@/types';

const overviewItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Applications', href: applications(), icon: Send },
];

const careerItems: NavItem[] = [
    { title: 'Profile', href: profile(), icon: IdCard },
    { title: 'Library', href: knowledge(), icon: BookUser },
    { title: 'Answers', href: answers(), icon: MessageSquareText },
    { title: 'Writing style', href: writingStyle(), icon: PenLine },
];

export function AppSidebar() {
    const { auth, pendingProposals } = usePage().props;

    const workspaceItems: NavItem[] = [
        {
            title: 'Suggestions',
            href: proposals(),
            icon: GitPullRequestArrow,
            badge: Number(pendingProposals ?? 0),
        },
        ...(auth.can.manageVas
            ? [{ title: 'Team', href: team(), icon: Users }]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-2">
                <NavMain items={overviewItems} />
                <NavMain items={careerItems} label="Career" />
                <NavMain items={workspaceItems} label="Workspace" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
