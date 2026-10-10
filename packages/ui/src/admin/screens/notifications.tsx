import { translate as uiText } from '@/shared/i18n';
import { Bell, ShieldCheck, Ban, Users, Radio, Mail, ArrowUpRight } from 'lucide-react';
import { WorkspacePage, WorkspaceTabs } from '../components/workspace-page';
import { useAdminConfig } from '../config';
import { useHashRoute } from '../hooks';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { useEffect, useState } from 'react';
import type { UserAccount } from '@vidyalms/api-sdk';
import { Badge, Button, ErrorState, Pagination, Skeleton } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import {
    useNotificationConsents,
    useNotificationPolicies,
    useRegistryProviders,
    useSetNotificationPolicies,
    useSuppressAddress,
    useSuppressions,
} from '@/shared/flow';
import { notificationAdminStrings as strings } from '../notification-strings';
import { TextField } from '../components/field';
import { UserPicker } from '../components/user-picker';
import { NotificationChannelsPanel, NotificationDeliveryControlsPanel, notificationChannelLabel } from '../notifications/channel-settings';
import { channelStrings } from '../notifications/channel-strings';
import { NotificationRulesPanel } from '../notifications/rules';

// Delivery setup is separate from course rules and message content.

const TABS = ['providers', 'rules', 'delivery', 'policies', 'suppressions', 'consents', 'feedback'] as const;
type TabId = (typeof TABS)[number];

function PoliciesPanel() {
    const policies = useNotificationPolicies();
    const save = useSetNotificationPolicies();
    const announce = useAnnounce();
    const [cells, setCells] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);
    const [hydrated, setHydrated] = useState(false);
    useNavigationGuard(dirty);

    const data = policies.data ?? null;
    useEffect(() => {
        if (data === null || hydrated) {
            return;
        }
        const seeded: Record<string, string> = {};
        for (const cell of data.cells) {
            seeded[`${cell.category_key}|${cell.channel_key}`] = cell.state;
        }
        setCells(seeded);
        setHydrated(true);
    }, [data, hydrated]);

    if (policies.isPending) {
        return <Skeleton className="m-4 h-32" />;
    }
    if (policies.isError) {
        return (
            <div className="p-4">
                <ErrorState
                    error={policies.error}
                    title={strings.common.loadError}
                    onRetry={() => void policies.refetch()}
                />
            </div>
        );
    }

    const matrix = policies.data;
    return (
        <section className="grid gap-3.5 p-4">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.policies.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.policies.intro}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.policies.marketingRule}</p>
            </div>
            {matrix.cells.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.policies.empty}</p>
            ) : (
                <div className="overflow-x-auto rounded border border-border">
                    <table className="w-full min-w-[520px] border-collapse text-[12.5px]">
                        <thead>
                            <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                <th scope="col" className="px-3 py-1.5">
                                    {strings.policies.category}
                                </th>
                                <th scope="col" className="px-3 py-1.5">
                                    {uiText("vidyalms.ui.ce4683e7013a18cd", "Channel")}</th>
                                <th scope="col" className="px-3 py-1.5">
                                    {uiText("vidyalms.ui.a3b50c476732c740", "State")}</th>
                                <th scope="col" className="px-3 py-1.5">
                                    {strings.policies.defaultFrequency}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {matrix.cells.map((cell) => {
                                const key = `${cell.category_key}|${cell.channel_key}`;
                                return (
                                    <tr key={key} className="border-t border-border/60">
                                        <td className="px-3 py-2">{channelStrings.categoryLabels[cell.category_key] ?? cell.category_key.replaceAll("_", " ")}</td>
                                        <td className="px-3 py-2">{notificationChannelLabel(cell.channel_key)}</td>
                                        <td className="px-3 py-2">
                                            <select
                                                disabled={save.isPending}
                                                aria-label={uiText("vidyalms.ui.12cafd4e0ceedbff", "{p0}, {p1}", { p0: channelStrings.categoryLabels[cell.category_key] ?? cell.category_key, p1: notificationChannelLabel(cell.channel_key) })}
                                                className="h-[34px] rounded border border-border bg-surface px-2 text-[12.5px]"
                                                value={cells[key] ?? cell.state}
                                                onChange={(event) => {
                                                    setDirty(true);
                                                    setCells((current) => ({ ...current, [key]: event.target.value }));
                                                }}
                                            >
                                                {cell.allowed_states.map((state) => (
                                                    <option key={state} value={state}>
                                                        {strings.policies.states[state] ?? state}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="px-3 py-2">{channelStrings.frequencyLabels[cell.default_frequency] ?? cell.default_frequency}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            <div>
                <Button
                    size="sm"
                    disabled={save.isPending}
                    onClick={() =>
                        save.mutate(
                            {
                                body: {
                                    cells: matrix.cells.map((cell) => ({
                                        category_key: cell.category_key,
                                        channel_key: cell.channel_key,
                                        state: (cells[`${cell.category_key}|${cell.channel_key}`] ??
                                            cell.state) as 'permitted',
                                        default_frequency: cell.default_frequency,
                                    })),
                                },
                            },
                            {
                                onSuccess: (result) => {
                                    setCells(
                                        Object.fromEntries(
                                            result.cells.map((cell) => [
                                                `${cell.category_key}|${cell.channel_key}`,
                                                cell.state,
                                            ]),
                                        ),
                                    );
                                    setDirty(false);
                                    announce(strings.policies.saved);
                                },
                            },
                        )
                    }
                >
                    {strings.policies.save}
                </Button>
            </div>
            {save.isError && <ErrorState error={save.error} />}
        </section>
    );
}

function SuppressionsPanel() {
    const [page, setPage] = useState(1);
    const [address, setAddress] = useState('');
    const [lookup, setLookup] = useState('');
    const suppressions = useSuppressions({
        page,
        per_page: 25,
        ...(lookup === '' ? {} : { address: lookup }),
    });
    const act = useSuppressAddress();
    const announce = useAnnounce();

    return (
        <section className="grid gap-3.5 p-4">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.suppressions.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.suppressions.intro}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.suppressions.privacy}</p>
            </div>

            <div className="flex flex-wrap items-end gap-2">
                <TextField
                    className="max-w-[320px]"
                    type="email"
                    label={strings.suppressions.lookup}
                    hint={strings.suppressions.lookupHint}
                    value={address}
                    onChange={(event) => setAddress(event.target.value)}
                />
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        setLookup(address.trim());
                        setPage(1);
                    }}
                >
                    {strings.suppressions.lookup}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    title={strings.suppressions.manualWarning}
                    disabled={address.trim() === '' || act.isPending}
                    onClick={() =>
                        act.mutate(
                            { body: { channel_key: 'email', address: address.trim(), action: 'suppress' } },
                            { onSuccess: () => announce(strings.suppressions.active) },
                        )
                    }
                >
                    {strings.suppressions.add}
                </Button>
            </div>
            <p className="text-[11.5px] text-warn-strong">{strings.suppressions.manualWarning}</p>

            {suppressions.isPending && <Skeleton className="h-24 w-full" />}
            {suppressions.isError && (
                <ErrorState
                    error={suppressions.error}
                    title={strings.common.loadError}
                    onRetry={() => void suppressions.refetch()}
                />
            )}
            {suppressions.data !== undefined &&
                (suppressions.data.items.length === 0 ? (
                    <p className="text-[12.5px] text-fg-2">{strings.suppressions.none}</p>
                ) : (
                    <>
                        <div className="overflow-x-auto rounded border border-border">
                            <table className="w-full min-w-[640px] border-collapse text-[12.5px]">
                                <thead>
                                    <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                        <th scope="col" className="px-3 py-1.5">
                                            {strings.suppressions.columns.digest}
                                        </th>
                                        <th scope="col" className="px-3 py-1.5">
                                            {strings.suppressions.columns.channel}
                                        </th>
                                        <th scope="col" className="px-3 py-1.5">
                                            {strings.suppressions.columns.reason}
                                        </th>
                                        <th scope="col" className="px-3 py-1.5">
                                            {strings.suppressions.columns.lastSeen}
                                        </th>
                                        <th scope="col" className="px-3 py-1.5">
                                            {strings.suppressions.columns.state}
                                        </th>
                                        <th scope="col" className="px-3 py-1.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {suppressions.data.items.map((row) => (
                                        <tr key={row.id} className="border-t border-border/60">
                                            <td className="px-3 py-2 font-mono text-[11px]">
                                                {row.address_digest.slice(0, 16)}
                                            </td>
                                            <td className="px-3 py-2">{row.channel_key}</td>
                                            <td className="px-3 py-2">
                                                {strings.suppressions.reasons[row.reason] ?? row.reason}
                                                {(row.soft_bounce_count ?? 0) > 0 && (
                                                    <span className="block text-[11px] text-fg-2">
                                                        {strings.suppressions.softBounces(row.soft_bounce_count ?? 0)}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">{row.last_seen_at}</td>
                                            <td className="px-3 py-2">
                                                <Badge variant={row.active ? 'warn' : 'neutral'}>
                                                    {row.active
                                                        ? strings.suppressions.active
                                                        : strings.suppressions.released}
                                                </Badge>
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {row.active && address.trim() !== '' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={act.isPending}
                                                        onClick={() =>
                                                            act.mutate({
                                                                body: {
                                                                    channel_key: row.channel_key,
                                                                    address: address.trim(),
                                                                    action: 'release',
                                                                },
                                                            })
                                                        }
                                                    >
                                                        {strings.suppressions.release}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination
                            label={strings.suppressions.title}
                            page={suppressions.data.page}
                            perPage={suppressions.data.per_page}
                            total={suppressions.data.total}
                            onPageChange={setPage}
                        />
                    </>
                ))}
            {act.isError && <ErrorState error={act.error} />}
        </section>
    );
}

function ConsentsPanel() {
    const [person, setPerson] = useState<UserAccount | null>(null);
    const consents = useNotificationConsents(person?.id ?? null);

    return (
        <section className="grid gap-3.5 p-4">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.consents.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.consents.intro}</p>
            </div>
            <UserPicker label={strings.consents.pick} placeholder="" value={person} onChange={setPerson} />
            {person !== null && consents.isPending && <Skeleton className="h-24 w-full" />}
            {consents.isError && (
                <ErrorState
                    error={consents.error}
                    title={strings.common.loadError}
                    onRetry={() => void consents.refetch()}
                />
            )}
            {consents.data !== undefined &&
                (consents.data.items.length === 0 ? (
                    <p className="text-[12.5px] text-fg-2">{strings.consents.none}</p>
                ) : (
                    <div className="overflow-x-auto rounded border border-border">
                        <table className="w-full min-w-[560px] border-collapse text-[12.5px]">
                            <thead>
                                <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                    <th scope="col" className="px-3 py-1.5">
                                        {strings.consents.columns.category}
                                    </th>
                                    <th scope="col" className="px-3 py-1.5">
                                        {strings.consents.columns.state}
                                    </th>
                                    <th scope="col" className="px-3 py-1.5">
                                        {strings.consents.columns.basis}
                                    </th>
                                    <th scope="col" className="px-3 py-1.5">
                                        {strings.consents.columns.source}
                                    </th>
                                    <th scope="col" className="px-3 py-1.5">
                                        {strings.consents.columns.when}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {consents.data.items.map((row) => (
                                    <tr key={row.id} className="border-t border-border/60">
                                        <td className="px-3 py-2">{row.category_key}</td>
                                        <td className="px-3 py-2">
                                            <Badge variant={row.state === 'granted' ? 'ok' : 'neutral'}>
                                                {strings.consents.states[row.state] ?? row.state}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2">{strings.consents.bases[row.basis] ?? row.basis}</td>
                                        <td className="px-3 py-2">{row.source}</td>
                                        <td className="px-3 py-2 tabular-nums">{row.occurred_at}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ))}
        </section>
    );
}

function FeedbackPanel() {
    const mappers = useRegistryProviders('DeliveryFeedbackMapper', 'site', 0);
    const channels = useRegistryProviders('Notification', 'site', 0);

    return (
        <section className="grid gap-3.5 p-4">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.feedback.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.feedback.intro}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.feedback.secret}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.feedback.unparsed}</p>
            </div>

            {(mappers.isPending || channels.isPending) && <Skeleton className="h-24 w-full" />}
            {mappers.isError && (
                <ErrorState
                    error={mappers.error}
                    title={strings.common.loadError}
                    onRetry={() => void mappers.refetch()}
                />
            )}

            {channels.data !== undefined && (
                <div>
                    <p className="font-display text-[11.5px] font-semibold text-fg">{strings.feedback.endpoint}</p>
                    <ul className="mt-1 grid gap-0.5">
                        {channels.data.providers.map((channel) => (
                            <li key={channel.key} className="font-mono text-[11.5px] text-fg-2">
                                {strings.feedback.endpointPath(channel.key)}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {mappers.data !== undefined && (
                <div>
                    <p className="font-display text-[11.5px] font-semibold text-fg">{strings.feedback.mappers}</p>
                    {mappers.data.providers.length === 0 ? (
                        <p className="mt-0.5 text-[11.5px] text-warn-strong">{strings.feedback.noMappers}</p>
                    ) : (
                        <ul className="mt-1 grid gap-0.5">
                            {mappers.data.providers.map((mapper) => (
                                <li key={mapper.key} className="text-[12px] text-fg-2">
                                    {mapper.label}{' '}
                                    <Badge variant={mapper.available ? 'ok' : 'warn'}>{mapper.edition}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </section>
    );
}

const notificationSections = [
    { key: 'providers', label: strings.tabs.providers, description: uiText("vidyalms.ui.0b52114aa1ef8270", "Channels and integrations"), icon: Bell },
    { key: 'rules', label: channelStrings.rules, description: channelStrings.rulesSummary, icon: Mail },
    { key: 'delivery', label: channelStrings.controls, description: channelStrings.controlsSummary, icon: Radio },
    { key: 'policies', label: strings.tabs.policies, description: uiText("vidyalms.ui.a237b7f8d70abcb2", "Who receives what"), icon: ShieldCheck },
    { key: 'suppressions', label: strings.tabs.suppressions, description: uiText("vidyalms.ui.131226fd921d0b40", "Addresses held from delivery"), icon: Ban },
    { key: 'consents', label: strings.tabs.consents, description: uiText("vidyalms.ui.faff4f8116f3affe", "Learner preferences and history"), icon: Users },
    { key: 'feedback', label: strings.tabs.feedback, description: uiText("vidyalms.ui.383a3a4619e41c03", "Delivery feedback and webhooks"), icon: Radio },
];

export function NotificationsScreen() {
    const config = useAdminConfig();
    const [hash, navigate] = useHashRoute();
    const requested = hash.replace(/^\//, '') as TabId;
    const tab = TABS.includes(requested) ? requested : 'providers';
    const [visited, setVisited] = useState<TabId[]>([tab]);
    useEffect(() => {
        setVisited((current) => (current.includes(tab) ? current : [...current, tab]));
    }, [tab]);
    const panels = {
        providers: NotificationChannelsPanel,
        rules: NotificationRulesPanel,
        delivery: NotificationDeliveryControlsPanel,
        policies: PoliciesPanel,
        suppressions: SuppressionsPanel,
        consents: ConsentsPanel,
        feedback: FeedbackPanel,
    };
    const section = notificationSections.find((item) => item.key === tab)!;
    return (
        <WorkspacePage
            title={strings.screen}
            description={uiText("vidyalms.ui.f37e2921b83d2468", "Thoughtful communication, at every step of the learning journey.")}
            actions={
                config.urls.screens['email-templates'] && (
                    <a
                        className="inline-flex items-center gap-2 text-sm font-medium text-accent"
                        href={config.urls.screens['email-templates']}
                    >
                        <Mail size={17} />
                        {uiText("vidyalms.ui.3c2ee00f0e20b886", "Email templates")}<ArrowUpRight size={15} />
                    </a>
                )
            }
        >
            <div className="vy-workspace-split">
                <WorkspaceTabs
                    label={strings.screen}
                    tabs={notificationSections}
                    current={tab}
                    onChange={(key) => navigate(`/${key}`)}
                    vertical
                />
                <div className="vy-workspace-panel vy-notification-panels">
                    <header className="vy-workspace-panel-heading">
                        <div>
                            <h2>{section.label}</h2>
                            <p>{section.description}</p>
                        </div>
                        <span className="vy-workspace-glyph">
                            <section.icon size={22} />
                        </span>
                    </header>
                    {[...new Set([...visited, tab])].map((key) => {
                        const Panel = panels[key];
                        return (
                            <div key={key} role="tabpanel" aria-label={strings.tabs[key]} hidden={key !== tab}>
                                <Panel />
                            </div>
                        );
                    })}
                </div>
            </div>
        </WorkspacePage>
    );
}
