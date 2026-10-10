import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore, type MouseEvent } from 'react';
import { Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle, SessionExpired, Skeleton } from '@/shared/components';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { useApiClient, useMountEl } from '@/shared/context';
import { useSessionExpired } from '@/shared/session';
import { AdminConfigContext, useAdminConfig } from './config';
import { commitWorkspaceUrl, courseIdAtHash, screenAtUrl, subscribeWorkspaceUrl } from './workspace-navigation';
import { learnerIdFromHash, useHashRoute } from './hooks';
import { adminStrings } from './strings';
import { AdminShell } from './components/admin-shell';
import { LearnerRecordView } from './components/learner-record';
import { registerFreeAdminScreens } from './screens';
import {
    adminScreen,
    adminScreensSnapshot,
    subscribeAdminScreens,
    type AdminScreenRuntime,
} from './screens/registry';

/*
 * The CMS publishes canonical screen URLs. History navigation reuses this
 * mount and its authenticated query cache, while reloads remain native CMS
 * requests. Screen-local hashes stay owned by their existing feature routers.
 *
 * Which screens exist is a cross-bundle registry rather than a switch. A
 * host that names a contributed screen whose bundle is missing receives an
 * explicit unavailable state; silently showing Dashboard would make a
 * failed installation look like successful navigation.
 *
 * One sub-route is shared by every screen: "#/learner/{id}", the learner
 * record of artboard 2c. The Enrolments list links to it, the Learners
 * screen is built around it, and either way a reload lands back on the
 * record rather than on the list.
 */

registerFreeAdminScreens();
const CourseWorkspace = lazy(() => import('./course-workspace').then((module) => ({ default: module.CourseWorkspace })));

export function AdminApp() {
    const mountConfig = useAdminConfig();
    const href = useSyncExternalStore(subscribeWorkspaceUrl, () => window.location.href, () => '');
    const screenKey = screenAtUrl(mountConfig, href) ?? mountConfig.screen;
    const config = useMemo(() => ({ ...mountConfig, screen: screenKey }), [mountConfig, screenKey]);
    const [pendingDestination, setPendingDestination] = useState<string | null>(null);
    const client = useApiClient();
    const mountEl = useMountEl();
    const sessionExpired = useSessionExpired();
    const [hash, navigate] = useHashRoute();
    const courseId = config.screen === 'courses' ? courseIdAtHash(hash) : null;
    const destination = `${screenKey}:${courseId ?? ''}`;
    const previousScreen = useRef(destination);
    useEffect(() => {
        const protectHistory = (event: PopStateEvent) => {
            const requested = window.location.href;
            const nextScreen = screenAtUrl(mountConfig, requested) ?? mountConfig.screen;
            const nextCourse = nextScreen === 'courses' ? courseIdAtHash(window.location.hash.slice(1)) : null;
            if (`${nextScreen}:${nextCourse ?? ''}` === destination || !hasUnsavedWorkspaceChanges()) return;
            event.stopImmediatePropagation();
            // Preserve the departing editor before any feature hash listener
            // can unmount it. The requested destination remains reviewable.
            window.history.pushState(window.history.state, '', href);
            setPendingDestination(requested);
        };
        window.addEventListener('popstate', protectHistory, true);
        return () => window.removeEventListener('popstate', protectHistory, true);
    }, [destination, href, mountConfig]);
    useEffect(() => {
        if (mountEl === null) return;
        mountEl.setAttribute('data-screen', screenKey);
        return () => mountEl.removeAttribute('data-screen');
    }, [screenKey, mountEl]);
    useEffect(() => {
        if (previousScreen.current !== destination) {
            const main = mountEl?.querySelector<HTMLElement>('#vy-admin-main');
            if (main) {
                main.scrollTop = 0;
                main.focus();
            }
            previousScreen.current = destination;
        }
    }, [destination, mountEl]);
    const interceptNavigation = (event: MouseEvent<HTMLDivElement>) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const anchor = (event.target as Element).closest<HTMLAnchorElement>('a[href]');
        if (anchor === null || anchor.hasAttribute('download') || (anchor.target !== '' && anchor.target !== '_self')) return;
        const target = new URL(anchor.href, window.location.href);
        if (target.origin !== window.location.origin) return;
        const nextScreen = screenAtUrl(mountConfig, target.href);
        if (nextScreen === null || adminScreen(nextScreen) === undefined) return;
        if (nextScreen === screenKey && courseId === null && courseIdAtHash(target.hash.slice(1)) === null && target.hash !== '') return;
        event.preventDefault();
        if (target.href === window.location.href) return;
        if (hasUnsavedWorkspaceChanges()) setPendingDestination(target.href);
        else commitWorkspaceUrl(target.href);
    };
    useSyncExternalStore(subscribeAdminScreens, adminScreensSnapshot, () => 0);
    const replace = useCallback((next: string) => {
        const url = new URL(window.location.href);
        url.hash = next;
        window.history.replaceState(window.history.state, '', url);
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    }, []);
    const learnerId = learnerIdFromHash(hash);
    const listOrigin = useRef('/');
    if (learnerId === null && (hash === '' || hash === '/' || hash.startsWith('/?'))) {
        listOrigin.current = hash === '' ? '/' : hash;
    }

    const screen = adminScreen(config.screen);
    const unavailable = useMemo(() => ({
        key: config.screen,
        label: adminStrings.app.unavailable.title,
        render: () => (
            <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                <h2 className="font-display text-lg font-semibold text-fg">{adminStrings.app.unavailable.title}</h2>
                <p className="mt-1.5 max-w-[70ch] text-sm text-fg-2">{adminStrings.app.unavailable.body(config.screen)}</p>
            </section>
        ),
    }), [config.screen]);
    const activeScreen = screen ?? unavailable;
    const runtime: AdminScreenRuntime = { config, client, hash, navigate, replace, mountEl };

    const body = () => {
        /* An expired administrator token is neither an error nor a
           permission problem: the only useful action is to sign in again. */
        if (sessionExpired) {
            return <SessionExpired />;
        }

        if (learnerId !== null && config.screen !== 'learners') {
            return (
                <LearnerRecordView
                    key={learnerId}
                    userId={learnerId}
                    onBack={() => navigate(listOrigin.current)}
                    backLabel={adminStrings.learners.backTo(screen?.label ?? adminStrings.app.screens.dashboard)}
                />
            );
        }

        if (courseId !== null) {
            return <Suspense fallback={<Skeleton className="h-64 w-full" />}><CourseWorkspace key={courseId} courseId={courseId} /></Suspense>;
        }
        return <div key={config.screen}>{activeScreen.render(runtime)}</div>;
    };

    return (
        <AdminConfigContext.Provider value={config}><div
            onClick={interceptNavigation}
            role="region"
            aria-label={adminStrings.app.region}
            className="w-full min-w-0 max-w-full overflow-hidden font-body text-fg"
        >
            <AdminShell activeScreen={activeScreen}>{body()}</AdminShell>
            <Dialog open={pendingDestination !== null} onOpenChange={(open) => { if (!open) setPendingDestination(null); }}>
                <DialogContent><DialogTitle>{adminStrings.app.shell.leaveTitle}</DialogTitle>
                    <DialogDescription>{adminStrings.app.shell.leaveBody}</DialogDescription>
                    <DialogFooter><Button variant="outline" onClick={() => setPendingDestination(null)}>{adminStrings.app.shell.stay}</Button>
                        <Button variant="destructive" onClick={() => {
                            if (pendingDestination !== null) commitWorkspaceUrl(pendingDestination);
                            setPendingDestination(null);
                        }}>{adminStrings.app.shell.leave}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div></AdminConfigContext.Provider>
    );
}
