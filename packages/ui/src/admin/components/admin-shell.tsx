import { useLayoutEffect, useState, useSyncExternalStore, type ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import {
    Award,
    Activity,
    BarChart3,
    Building2,
    Import,
    Bell,
    BookOpen,
    GraduationCap,
    FolderTree,
    LayoutDashboard,
    LayoutGrid,
    LibraryBig,
    MailOpen,
    Search,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    Workflow,
    Sparkles,
    Moon,
    Settings,
    Sun,
    UserCheck,
    Users,
} from 'lucide-react';
import { Icon, Dialog, DialogContent, DialogTitle, DialogDescription, Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/shared/components';
import { cn } from '@/shared/cn';
import { useMountEl } from '@/shared/context';
import { useAdminConfig } from '../config';
import { adminStrings } from '../strings';
import { useWorkspaceHeight } from './use-workspace-height';
import {
    adminScreen,
    adminScreenKeys,
    adminScreensSnapshot,
    subscribeAdminScreens,
    type AdminScreenDefinition,
} from '../screens/registry';

/*
 * VidyaLMS owns this shell, while Joomla and WordPress continue to own the
 * page around it. It therefore uses only scoped utilities and host-provided
 * URLs. A top-level navigation never invents a pathname or hash route.
 */

const screenIcons: Record<string, LucideIcon> = {
    dashboard: LayoutDashboard,
    courses: BookOpen,
    categories: FolderTree,
    enrolments: UserCheck,
    learners: Users,
    certificates: Award,
    notifications: Bell,
    'email-templates': MailOpen,
    'question-banks': LibraryBig,
    organisations: Building2,
    reports: BarChart3,
    operations: Activity,
    import: Import,
    settings: Settings,
    automations: Workflow,
    ai: Sparkles,
};

type ColorScheme = 'light' | 'dark';

const THEME_STORAGE_KEY = 'vidyalms-admin-color-scheme';
const SIDEBAR_STORAGE_KEY = 'vidyalms-admin-sidebar-collapsed';

function storedColorScheme(): ColorScheme | null {
    try {
        const stored = window.localStorage.getItem(THEME_STORAGE_KEY);
        return stored === 'light' || stored === 'dark' ? stored : null;
    } catch {
        return null;
    }
}

function hostColorScheme(mountEl: HTMLElement | null): ColorScheme {
    const host = mountEl?.closest<HTMLElement>('[data-color-scheme], [data-bs-theme]') ?? null;
    const declared = host?.getAttribute('data-color-scheme') ?? host?.getAttribute('data-bs-theme');

    if (declared === 'dark' || declared === 'light') {
        return declared;
    }

    if (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }

    return 'light';
}

interface ShellNavItemProps {
    screen: AdminScreenDefinition;
    href: string | null;
    active: boolean;
    compact?: boolean;
}

function ShellNavItem({ screen, href, active, compact = false }: ShellNavItemProps) {
    const ScreenIcon = screenIcons[screen.key] ?? LayoutGrid;
    const className = cn(
        'vy-nav-link group flex items-center gap-3 rounded px-3 font-display text-[14px] font-medium transition-colors duration-1',
        compact ? 'h-11 shrink-0' : 'h-11 w-full',
        active
            ? 'bg-accent-soft text-accent'
            : href === null
              ? 'cursor-default text-fg-2 opacity-60'
              : 'text-fg-2 hover:bg-surface-2 hover:text-fg',
    );
    const contents = (
        <>
            <Icon icon={ScreenIcon} size={17} />
            <span className={compact ? 'sr-only' : undefined}>{screen.label}</span>
        </>
    );

    if (href === null) {
        return (
            <span className={className} aria-current={active ? 'page' : undefined} aria-disabled="true">
                {contents}
            </span>
        );
    }

    const link = (
        <a className={className} href={href} aria-current={active ? 'page' : undefined}>
            {contents}
        </a>
    );
    return compact ? <Tooltip><TooltipTrigger asChild>{link}</TooltipTrigger><TooltipContent side="right">{screen.label}</TooltipContent></Tooltip> : link;
}

function ProductMark() {
    return (
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-accent text-accent-fg">
            <Icon icon={GraduationCap} size={18} />
        </span>
    );
}

const navigationGroups = [
    { label: adminStrings.app.shell.workspace, keys: ['dashboard', 'courses', 'question-banks', 'categories'] },
    { label: adminStrings.app.shell.people, keys: ['learners', 'enrolments', 'organisations'] },
    { label: adminStrings.app.shell.intelligence, keys: ['certificates', 'reports', 'automations', 'notifications', 'email-templates', 'ai'] },
    { label: adminStrings.app.shell.system, keys: ['import', 'operations', 'settings'] },
];

function NavigationGroups({ items, activeKey, onNavigate, collapsed = false }: {
    items: AdminScreenDefinition[];
    activeKey: string;
    onNavigate?: () => void;
    collapsed?: boolean;
}) {
    const config = useAdminConfig();
    const [search, setSearch] = useState('');
    const known = new Set(navigationGroups.flatMap((group) => group.keys));
    const extensionKeys = items.filter((item) => !known.has(item.key)).map((item) => item.key);
    const groups = [...navigationGroups, { label: adminStrings.app.shell.other, keys: extensionKeys }];
    const matches = items.filter((item) =>
        item.label.toLocaleLowerCase(config.locale).includes(search.trim().toLocaleLowerCase(config.locale)),
    );
    return <>
        {!collapsed && <label className="vy-nav-search">
            <Icon icon={Search} size={16} />
            <input aria-label={adminStrings.app.shell.searchNavigation} placeholder={adminStrings.app.shell.searchNavigation} value={search}
                onChange={(event) => setSearch(event.target.value)} />
        </label>}
        <div className="vy-navigation-groups" onClick={(event) => { if ((event.target as Element).closest('a')) onNavigate?.(); }}>
            {groups.map((group) => {
                const entries = group.keys.flatMap((key) => (collapsed ? items : matches).filter((item) => item.key === key));
                if (!entries.length) return null;
                return <section key={group.label}>
                    <h2 className={collapsed ? 'sr-only' : 'vy-nav-group-label'}>{group.label}</h2>
                    <ul className="grid gap-1">{entries.map((item) => <li key={item.key}>
                        <ShellNavItem screen={item} href={config.urls.screens[item.key] ?? null} active={item.key === activeKey} compact={collapsed} />
                    </li>)}</ul>
                </section>;
            })}
            {!collapsed && matches.length === 0 && <p role="status" className="p-3 text-sm text-fg-2">{adminStrings.app.shell.navigationEmpty}</p>}
        </div>
    </>;
}

function Sidebar({ items, activeKey }: { items: AdminScreenDefinition[]; activeKey: string }) {
    const config = useAdminConfig();
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';
        } catch {
            return false;
        }
    });
    const label = collapsed ? adminStrings.app.shell.expandSidebar : adminStrings.app.shell.collapseSidebar;
    const toggle = () => {
        setCollapsed(!collapsed);
        try {
            window.localStorage.setItem(SIDEBAR_STORAGE_KEY, String(!collapsed));
        } catch {
            /* Storage is optional. */
        }
    };
    return <TooltipProvider delayDuration={150}><aside className="vy-sidebar" data-collapsed={collapsed}>
        <div className="vy-sidebar-brand"><ProductMark /><span className={collapsed ? 'sr-only' : 'font-display text-lg font-bold text-fg'}>{adminStrings.app.shell.product}</span></div>
        <button type="button" className="vy-sidebar-toggle" aria-label={label} title={label} aria-expanded={!collapsed} aria-controls="vy-desktop-navigation" onClick={toggle}>
            <Icon icon={collapsed ? PanelLeftOpen : PanelLeftClose} size={18} /><span className={collapsed ? 'sr-only' : undefined}>{label}</span>
        </button>
        <nav id="vy-desktop-navigation" aria-label={adminStrings.app.shell.navigation} className="min-h-0 flex-1"><NavigationGroups items={items} activeKey={activeKey} collapsed={collapsed} /></nav>
        <div className="vy-sidebar-user" title={collapsed ? config.user.name : undefined}>
            <span className="vy-avatar" aria-hidden="true">{config.user.name.trim().slice(0, 1) || '?'}</span>
            <div className={collapsed ? 'sr-only' : 'min-w-0'}><p className="truncate text-sm font-semibold text-fg">{config.user.name}</p><p className="text-xs text-fg-2">{adminStrings.app.shell.administrator}</p></div>
        </div>
    </aside></TooltipProvider>;
}

function TopToolbar({
    activeScreen,
    colorScheme,
    onColorSchemeChange,
}: {
    activeScreen: AdminScreenDefinition;
    colorScheme: ColorScheme;
    onColorSchemeChange: () => void;
}) {
    const config = useAdminConfig();
    const themeLabel =
        colorScheme === 'dark' ? adminStrings.app.shell.useLightTheme : adminStrings.app.shell.useDarkTheme;

    return (
        <header className="vy-workspace-toolbar flex min-h-16 items-center gap-3 border-b border-border bg-surface px-4 sm:px-5 lg:px-6">
            <div className="flex items-center gap-2.5 xl:hidden">
                <ProductMark />
                <span className="font-display text-sm font-bold text-fg">{adminStrings.app.shell.product}</span>
                <span aria-hidden="true" className="h-5 w-px bg-border" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="hidden text-[10px] font-bold uppercase tracking-[0.12em] text-fg-2 sm:block">
                    {adminStrings.app.shell.workspace}
                </p>
                <p className="truncate font-display text-[16px] font-semibold tracking-tight text-fg">
                    {activeScreen.label}
                </p>
            </div>
            <button
                type="button"
                aria-label={themeLabel}
                aria-pressed={colorScheme === 'dark'}
                title={themeLabel}
                onClick={onColorSchemeChange}
                className="inline-flex h-9 shrink-0 cursor-pointer items-center gap-2 rounded border border-border bg-bg px-2.5 text-[11.5px] font-medium text-fg-2 transition-colors duration-1 hover:bg-surface-2 hover:text-fg"
            >
                <Icon icon={colorScheme === 'dark' ? Sun : Moon} size={15} />
                <span className="hidden lg:inline">
                    {colorScheme === 'dark' ? adminStrings.app.shell.lightTheme : adminStrings.app.shell.darkTheme}
                </span>
            </button>
            <div className="hidden min-w-0 items-center gap-2 rounded border border-border bg-bg px-2.5 py-1.5 sm:flex">
                <span
                    aria-hidden="true"
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-[10px] font-bold uppercase text-accent"
                >
                    {config.user.name.trim().slice(0, 1) || '?'}
                </span>
                <span className="max-w-[180px] truncate text-[11.5px] font-medium text-fg">{config.user.name}</span>
            </div>
        </header>
    );
}

function MobileNavigation({ items, activeKey }: { items: AdminScreenDefinition[]; activeKey: string }) {
    const [open, setOpen] = useState(false);
    return <div className="vy-mobile-navigation">
        <button type="button" className="vy-menu-trigger" onClick={() => setOpen(true)} aria-label={adminStrings.app.shell.openMenu}><Icon icon={Menu} size={18} />{adminStrings.app.shell.workspace}</button>
        <Dialog open={open} onOpenChange={setOpen}><DialogContent className="vy-navigation-dialog">
            <DialogTitle>{adminStrings.app.shell.product}</DialogTitle>
            <DialogDescription>{adminStrings.app.shell.mobileNavigation}</DialogDescription>
            <nav aria-label={adminStrings.app.shell.mobileNavigation}><NavigationGroups items={items} activeKey={activeKey}
                onNavigate={() => setOpen(false)} /></nav>
        </DialogContent></Dialog>
    </div>;
}

export interface AdminShellProps {
    activeScreen: AdminScreenDefinition;
    children: ReactNode;
}

export function AdminShell({ activeScreen, children }: AdminShellProps) {
    const config = useAdminConfig();
    const mountEl = useMountEl();
    const workspaceRef = useWorkspaceHeight();
    useSyncExternalStore(subscribeAdminScreens, adminScreensSnapshot, () => 0);
    const [colorScheme, setColorScheme] = useState<ColorScheme>(
        () => storedColorScheme() ?? hostColorScheme(mountEl),
    );
    const items = adminScreenKeys()
        .map((key) => adminScreen(key))
        .filter((screen): screen is AdminScreenDefinition => screen !== undefined)
        /* The host owns product availability. An installed bundle may
           register several screens, but only URLs declared by this mount
           belong in its rail. Keep the active item as recovery for an older
           host that omitted its own URL. */
        .filter((screen) => screen.key === activeScreen.key || config.urls.screens[screen.key] !== undefined);

    useLayoutEffect(() => {
        if (mountEl === null) {
            return;
        }

        const previous = mountEl.getAttribute('data-color-scheme');
        mountEl.setAttribute('data-color-scheme', colorScheme);

        return () => {
            if (previous === null) {
                mountEl.removeAttribute('data-color-scheme');
            } else {
                mountEl.setAttribute('data-color-scheme', previous);
            }
        };
    }, [colorScheme, mountEl]);

    const toggleColorScheme = () => {
        const next = colorScheme === 'dark' ? 'light' : 'dark';
        setColorScheme(next);
        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, next);
        } catch {
            // Storage can be disabled without making theme switching fail.
        }
    };

    return (
        <div
            ref={workspaceRef}
            data-color-scheme={colorScheme}
            className="vy-admin-workspace relative w-full min-w-0 max-w-full overflow-hidden rounded-lg border border-border bg-bg"
        >
            <a
                href="#vy-admin-main"
                className="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-3 focus:z-50 focus:rounded focus:bg-surface focus:px-3 focus:py-2 focus:text-fg"
            >
                {adminStrings.app.shell.skipToContent}
            </a>
            <div className="vy-workspace-body flex w-full min-w-0 max-w-full">
                <Sidebar items={items} activeKey={activeScreen.key} />
                <div className="flex min-w-0 max-w-full flex-1 flex-col overflow-hidden">
                    <TopToolbar
                        activeScreen={activeScreen}
                        colorScheme={colorScheme}
                        onColorSchemeChange={toggleColorScheme}
                    />
                    <MobileNavigation items={items} activeKey={activeScreen.key} />
                    <main
                        id="vy-admin-main"
                        tabIndex={-1}
                        className="vy-workspace-main min-w-0 max-w-full flex-1"
                    >
                        <div className="w-full min-w-0 max-w-full overflow-hidden">{children}</div>
                    </main>
                </div>
            </div>
        </div>
    );
}
