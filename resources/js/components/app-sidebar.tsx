import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    BookUser,
    FolderGit2,
    GitPullRequestArrow,
    IdCard,
    LayoutGrid,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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
import { index as knowledge } from '@/routes/knowledge';
import { index as proposals } from '@/routes/knowledge/proposals';
import { index as profile } from '@/routes/resumes';
import { index as team } from '@/routes/team';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Profile',
        href: profile(),
        icon: IdCard,
    },
    {
        title: 'Library',
        href: knowledge(),
        icon: BookUser,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth, pendingProposals } = usePage().props;
    const pendingCount = Number(pendingProposals ?? 0);

    const navItems: NavItem[] = [
        ...mainNavItems,
        {
            title:
                pendingCount > 0
                    ? `Suggestions (${pendingCount})`
                    : 'Suggestions',
            href: proposals(),
            icon: GitPullRequestArrow,
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

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
