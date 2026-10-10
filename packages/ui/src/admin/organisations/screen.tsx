import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Settings2 } from 'lucide-react';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    EmptyState,
    ErrorState,
    Icon,
    Skeleton,
} from '@/shared/components';
import { useHashRoute } from '../hooks';
import { PageHeader } from '../components/page-header';
import { AdminTabPanel, AdminTabs } from '../components/tabs';
import {
    StudioDraftProvider,
    useStudioNavigation as useOrganisationNavigation,
} from '../credentials/studio-drafts';
import { useOrganisation, useOrganisationRoles } from './api';
import {
    organisationHash,
    organisationRoute,
    type OrganisationLocation,
    type OrganisationTab,
} from './routes';
import { OrganisationPeople } from './people';
import { OrganisationUnits } from './units';
import { OrganisationLearning } from './learning';
import { OrganisationSeats } from './seats';
import { Directory } from './directory';
import { Overview } from './overview';
import { Settings } from './settings';
import { Activity } from './activity';
import { OrgAvatar, orgType } from './workspace';
import { organisationStrings as strings } from './strings';
import { orgWorkspaceStrings as s } from './workspace-strings';

const guardLabels = {
    title: strings.leaveTitle,
    body: strings.leaveBody,
    stay: strings.stay,
    leave: strings.discard,
};

export function OrganisationsScreen() {
    const [hash, navigate] = useHashRoute();
    const [activeHash, setActiveHash] = useState(hash);
    const [dirty, setDirty] = useState(false);
    const [pending, setPending] = useState<string | null>(null);
    const origin = useRef('/');
    const route = organisationRoute(activeHash);

    useEffect(() => {
        if (hash === activeHash) {
            return;
        }

        if (dirty) {
            setPending(hash);

            const url = new URL(window.location.href);

            url.hash = activeHash;
            window.history.replaceState(window.history.state, '', url);
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        } else {
            setActiveHash(hash);
        }
    }, [hash, activeHash, dirty]);

    useEffect(() => {
        if (route.id === null && !route.invalid) {
            origin.current = activeHash;
        }
    }, [route.id, route.invalid, activeHash]);

    const go = (next: string) => {
        setDirty(false);
        setActiveHash(next);
        navigate(next);
    };

    if (route.invalid) {
        return (
            <EmptyState
                title={strings.denied}
                action={<Button onClick={() => go('/')}>{strings.back}</Button>}
            />
        );
    }

    return (
        <>
            <StudioDraftProvider labels={guardLabels} onDirtyChange={setDirty}>
                {route.id === null ? (
                    <Directory location={route} navigate={go} />
                ) : (
                    <Workspace key={route.id} location={route} navigate={go} back={origin.current || '/'} />
                )}
            </StudioDraftProvider>
            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) setPending(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{strings.leaveTitle}</DialogTitle>
                    <DialogDescription>{strings.leaveBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPending(null)}>
                            {strings.stay}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (pending !== null) {
                                    go(pending);
                                    setPending(null);
                                }
                            }}
                        >
                            {strings.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Workspace({
    location,
    navigate,
    back,
}: {
    location: OrganisationLocation;
    navigate: (hash: string) => void;
    back: string;
}) {
    const id = location.id!;
    const query = useOrganisation(id);
    const roles = useOrganisationRoles(id);
    const navigation = useOrganisationNavigation();
    const [notice, setNotice] = useState('');

    if (!query.data) {
        return query.isError ? (
            <ErrorState
                error={query.error}
                onRetry={() => {
                    void query.refetch();
                }}
            />
        ) : (
            <Skeleton className="h-64 w-full" />
        );
    }

    if (!roles.data) {
        return roles.isError ? (
            <ErrorState
                error={roles.error}
                onRetry={() => {
                    void roles.refetch();
                }}
            />
        ) : (
            <Skeleton className="h-48 w-full" />
        );
    }

    const organisation = query.data;
    const change = (changes: Partial<OrganisationLocation>) => navigate(organisationHash(location, changes));
    const organisationWide = roles.data.access.unit_id === null;
    const visible: OrganisationTab[] = [
        'overview',
        'units',
        ...(roles.data.access.actions.includes('people') ? ['people' as const] : []),
        ...(roles.data.access.actions.includes('learning') ? ['learning' as const] : []),
        ...(roles.data.access.actions.includes('seats') ? ['seats' as const] : []),
        ...(organisationWide && roles.data.access.actions.includes('settings') ? ['settings' as const] : []),
        ...(organisationWide && roles.data.access.actions.includes('events') ? ['activity' as const] : []),
    ];
    const tabProps = {
        organisation,
        roles: roles.data,
        location,
        change,
        notify: setNotice,
    };

    return (
        <div className="vy-org-workspace">
            <Button
                className="justify-self-start"
                size="sm"
                variant="ghost"
                onClick={() => navigation.requestLeave(() => navigate(back))}
            >
                <Icon icon={ArrowLeft} size={16} />
                {strings.back}
            </Button>
            <PageHeader
                title={organisation.name}
                eyebrow={s.workspace}
                actions={
                    <>
                        <Badge variant={organisation.state === 'active' ? 'accent' : 'warn'}>
                            {strings.stateLabels[organisation.state]}
                        </Badge>
                        {visible.includes('settings') && location.tab !== 'settings' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    navigation.requestLeave(() =>
                                        change({ tab: 'settings', state: '', page: 1, pool: null }),
                                    )
                                }
                            >
                                <Icon icon={Settings2} size={16} />
                                {s.editConfiguration}
                            </Button>
                        )}
                    </>
                }
            />
            <section className={`vy-org-profile vy-org-tone-${id % 4}`} aria-label={s.profile}>
                <OrgAvatar name={organisation.name} id={id} large />
                <div className="vy-org-profile-identity">
                    <strong>{organisation.name}</strong>
                    <p>
                        {organisation.slug} · {orgType(organisation.type, organisation.type_unresolved)}
                    </p>
                </div>
                <div className="vy-org-profile-scope">
                    <span>{s.scope}</span>
                    <strong>{organisationWide ? s.fullScope : s.unitScope}</strong>
                </div>
            </section>
            {(query.isError || roles.isError) && <ErrorState error={query.error ?? roles.error} />}
            {notice && (
                <div
                    role="status"
                    className="flex items-center gap-3 rounded-lg border border-border bg-surface p-3 text-sm"
                >
                    <p className="flex-1 text-fg">{notice}</p>
                    <Button variant="ghost" size="sm" onClick={() => setNotice('')}>
                        {strings.close}
                    </Button>
                </div>
            )}
            <AdminTabs
                label={strings.workspace}
                current={location.tab}
                tabs={visible.map((tab) => ({ id: tab, label: strings.tabs[tab] }))}
                onSelect={(tab) =>
                    navigation.requestLeave(() =>
                        change({
                            tab: tab as OrganisationTab,
                            page: 1,
                            state: '',
                            search: '',
                            pool: null,
                        }),
                    )
                }
            />
            <AdminTabPanel id={location.tab}>
                {!visible.includes(location.tab) ? (
                    <EmptyState title={strings.denied} />
                ) : location.tab === 'overview' ? (
                    <Overview
                        organisation={organisation}
                        roles={roles.data}
                        select={(tab) => change({ tab, page: 1, state: '', search: '', pool: null })}
                    />
                ) : location.tab === 'people' ? (
                    <OrganisationPeople {...tabProps} />
                ) : location.tab === 'units' ? (
                    <OrganisationUnits {...tabProps} />
                ) : location.tab === 'learning' ? (
                    <OrganisationLearning {...tabProps} />
                ) : location.tab === 'seats' ? (
                    <OrganisationSeats {...tabProps} />
                ) : location.tab === 'settings' ? (
                    <Settings
                        key={organisation.id}
                        organisation={organisation}
                        roles={roles.data}
                        notify={setNotice}
                        location={location}
                        change={change}
                    />
                ) : (
                    <Activity id={id} page={location.page} change={(page) => change({ page })} />
                )}
            </AdminTabPanel>
        </div>
    );
}
