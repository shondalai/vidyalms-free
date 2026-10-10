import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogFooter,
    Skeleton,
} from '@/shared/components';
import { AdminTabs } from '../components/tabs';
import { credentialStrings } from '../credential-strings';
import { BadgesTab } from '../credentials/badges-tab';
import { CertificatesTab } from '../credentials/certificates-tab';
import { CreditsTab } from '../credentials/credits-tab';
import { PageHeader } from '../components/page-header';
import { CertificateHero } from '../credentials/workspace';
import { CertificateRecordPage } from '../credentials/record-page';
import { certificateWorkspaceStrings as ws } from '../credentials/workspace-strings';
import { useHashRoute } from '../hooks';
import { parseCertificateRoute } from '../credentials/studio-routes';
import { certificateStudioStrings as studioStrings } from '../credentials/studio-strings';

const TemplatesTab = lazy(() =>
    import('../credentials/templates-tab').then((module) => ({ default: module.TemplatesTab })),
);

/*
 * Artboard 2d. The screen the host's Certificates menu entry opens, and the
 * whole credential surface behind it: the issuance records, the template
 * aggregate they were issued from, the continuing-education credit they
 * carry and the badges that reuse the same machinery.
 *
 * Related credential operations stay as tabs rather than separate host menu
 * entries. Only the selected panel is mounted, so an unopened tab fetches
 * nothing. The template studio remains an internal, explicitly enabled tab
 * until Stage 6 implements every route it needs.
 */

const TABS = [
    { id: 'certificates', label: credentialStrings.tabs.certificates },
    { id: 'credits', label: credentialStrings.tabs.credits },
] as const;

const TEMPLATE_STUDIO_TAB = { id: 'templates', label: ws.templateTab } as const;
const BADGES_TAB = { id: 'badges', label: credentialStrings.tabs.badges } as const;

type CredentialTab = (typeof TABS)[number]['id'] | typeof TEMPLATE_STUDIO_TAB.id | typeof BADGES_TAB.id;

export interface CertificatesScreenProps {
    /**
     * Hosts shipping the complete publication and PDF runtime enable the
     * lazy studio in their registered screen. Tests may exercise records alone.
     */
    enableTemplateStudio?: boolean;
    /** Kept internal until the core registers the badge management routes. */
    enableBadges?: boolean;
}

export function CertificatesScreen({
    enableTemplateStudio = false,
    enableBadges = false,
}: CertificatesScreenProps = {}) {
    const [hash, navigate] = useHashRoute();
    const [activeHash, setActiveHash] = useState(hash);
    const [dirty, setDirty] = useState(false);
    const [pendingHash, setPendingHash] = useState<string | null>(null);
    const lastLibraryHash = useRef('/templates');
    const lastRecordsHash = useRef('/');
    const route = parseCertificateRoute(activeHash);
    const routePath = activeHash.split('?')[0];
    useEffect(() => {
        document.getElementById('vy-admin-main')?.scrollTo({ top: 0 });
        window.scrollTo({ top: 0 });
    }, [routePath]);
    const tab: CredentialTab =
        route.kind === 'editor' || route.kind === 'templates'
            ? 'templates'
            : route.kind === 'credits' || route.kind === 'badges'
              ? route.kind
              : 'certificates';
    useEffect(() => {
        if (hash === activeHash) return;
        if (dirty) {
            setPendingHash(hash);
            const url = new URL(window.location.href);
            url.hash = activeHash;
            window.history.replaceState(window.history.state, '', url);
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        } else setActiveHash(hash);
    }, [hash, activeHash, dirty]);
    useEffect(() => {
        if (route.kind === 'templates') lastLibraryHash.current = activeHash;
    }, [route.kind, activeHash]);
    useEffect(() => {
        if (route.kind === 'certificates') lastRecordsHash.current = activeHash;
    }, [route.kind, activeHash]);
    const navigateConfirmed = (next: string) => {
        setDirty(false);
        setActiveHash(next);
        navigate(next);
    };
    const navigateGuarded = (next: string) => {
        if (dirty) setPendingHash(next);
        else navigateConfirmed(next);
    };
    const tabs: readonly { id: CredentialTab; label: string }[] = [
        TABS[0],
        ...(enableTemplateStudio ? [TEMPLATE_STUDIO_TAB] : []),
        ...TABS.slice(1),
        ...(enableBadges ? [BADGES_TAB] : []),
    ];

    return (
        <div className="vy-cert-workspace">
            {route.kind !== 'editor' && route.kind !== 'record' && (
                <PageHeader
                    title={ws.title}
                    description={ws.description}
                    actions={
                        enableTemplateStudio && tab !== 'templates' ? (
                            <Button
                                variant="primary"
                                onClick={() => navigateGuarded(lastLibraryHash.current)}
                            >
                                {ws.customize}
                            </Button>
                        ) : undefined
                    }
                />
            )}
            {route.kind !== 'editor' && route.kind !== 'record' && (
                <AdminTabs
                    label={credentialStrings.tabs.label}
                    tabs={tabs}
                    current={tab}
                    onSelect={(next) =>
                        navigateGuarded(
                            next === 'certificates'
                                ? '/'
                                : next === 'templates'
                                  ? lastLibraryHash.current
                                  : `/${next}`,
                        )
                    }
                />
            )}
            <div
                className="vy-cert-content"
                role={route.kind === 'editor' || route.kind === 'record' ? undefined : 'tabpanel'}
                id={`vy-admin-tabpanel-${tab}`}
                aria-labelledby={
                    route.kind === 'editor' || route.kind === 'record' ? undefined : `vy-admin-tab-${tab}`
                }
            >
                {route.kind === 'not-found' ||
                (tab === 'templates' && !enableTemplateStudio) ||
                (tab === 'badges' && !enableBadges) ? (
                    <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                        <h2 className="font-display text-lg font-semibold text-fg">
                            {studioStrings.invalidTitle}
                        </h2>
                        <p className="mt-2 text-sm text-fg-2">{studioStrings.invalidBody}</p>
                        <Button className="mt-3" variant="outline" onClick={() => navigateGuarded('/')}>
                            {studioStrings.back}
                        </Button>
                    </section>
                ) : (
                    <>
                        {route.kind === 'record' ? (
                            <CertificateRecordPage
                                key={route.certificateId}
                                certificateId={route.certificateId}
                                onBack={() => navigateConfirmed(lastRecordsHash.current)}
                                navigate={navigateConfirmed}
                            />
                        ) : (
                            tab === 'certificates' && (
                                <>
                                    {enableTemplateStudio && (
                                        <CertificateHero
                                            onCustomize={() => navigateGuarded(lastLibraryHash.current)}
                                        />
                                    )}
                                    <CertificatesTab
                                        onOpenRecord={(id) => navigateConfirmed(`/certificate/${id}`)}
                                    />
                                </>
                            )
                        )}
                        {tab === 'templates' && (
                            <Suspense fallback={<Skeleton className="h-64 w-full" />}>
                                <TemplatesTab
                                    hash={activeHash}
                                    navigate={navigateConfirmed}
                                    backHash={lastLibraryHash.current}
                                    onDirtyChange={setDirty}
                                />
                            </Suspense>
                        )}
                        {tab === 'credits' && <CreditsTab onDirtyChange={setDirty} />}
                        {tab === 'badges' && <BadgesTab />}
                    </>
                )}
            </div>
            <Dialog
                open={pendingHash !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingHash(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{studioStrings.leaveTitle}</DialogTitle>
                    <DialogDescription>{studioStrings.leaveBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPendingHash(null)}>
                            {studioStrings.stay}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                const next = pendingHash;
                                setPendingHash(null);
                                if (next !== null) navigateConfirmed(next);
                            }}
                        >
                            {studioStrings.leave}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
