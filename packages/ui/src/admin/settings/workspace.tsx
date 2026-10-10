import { translate as uiText } from '@/shared/i18n';
import { ShopSetup } from '@/shared/shop-setup';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ProblemError, type components, type UserAccount } from '@vidyalms/api-sdk';
import {
    Check,
    ChevronRight,
    Download,
    Search,
    Save,
    ShieldCheck,
    SlidersHorizontal,
    ArrowUpRight,
} from 'lucide-react';
import {
    Button,
    Card,
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogFooter,
    ErrorState,
    Skeleton,
} from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { usePlatformSettings, adminKeys } from '../api';
import { screenUrl, useAdminConfig } from '../config';
import { PageHeader } from '../components/page-header';
import { ScreenError } from '../components/states';
import { UserPicker } from '../components/user-picker';
import { LearnerHeaderPreview } from './learner-header-preview';
import { ShopMappings } from './shop-mappings';
import { useHashRoute } from '../hooks';
import {
    settingsGroups,
    settingsStrings as s,
    type ConfigurationValues,
    type SettingField,
    type SettingKey,
} from './fields';

type PlatformSettings = components['schemas']['PlatformSettings'];
type Issuer = components['schemas']['CredentialIssuerInput'];

export function SettingsWorkspace() {
    const query = usePlatformSettings();
    const client = useApiClient();
    const cache = useQueryClient();
    const config = useAdminConfig();
    const [hash, navigate] = useHashRoute();
    const section = settingsGroups.some((group) => hash === `/${group.id}`) ? hash.slice(1) : 'general';
    const [headerPanel, setHeaderPanel] = useState<'layout' | 'branding' | 'spacing'>('layout');
    const [search, setSearch] = useState('');
    const [values, setValues] = useState<ConfigurationValues | null>(null);
    const [base, setBase] = useState<ConfigurationValues>({});
    const [revision, setRevision] = useState(0);
    const [notice, setNotice] = useState('');
    const [discardOpen, setDiscardOpen] = useState(false);
    const [issuer, setIssuer] = useState<Issuer>({});
    const [issuerBase, setIssuerBase] = useState<Issuer>({});
    const [publishNames, setPublishNames] = useState(false);
    const [publishNamesBase, setPublishNamesBase] = useState(false);
    const [actor, setActor] = useState('');
    const [actorBase, setActorBase] = useState('');
    const [actorAccount, setActorAccount] = useState<UserAccount | null>(null);
    const form = useRef<HTMLFormElement>(null);
    const savedForm = (data: PlatformSettings) => {
        if (!data.configuration) return;
        setValues(data.configuration.values);
        setBase(data.configuration.values);
        setRevision(data.configuration.revision);
        const nextIssuer = { name: data.issuer.name ?? '', url: data.issuer.url ?? '', image: data.issuer.image ?? '' };
        setIssuer(nextIssuer);
        setIssuerBase(nextIssuer);
        setPublishNames(data.publish_learner_name ?? false);
        setPublishNamesBase(data.publish_learner_name ?? false);
        setActor(data.background_actor.user_id?.toString() ?? '');
        setActorBase(data.background_actor.user_id?.toString() ?? '');
        setActorAccount(
            data.background_actor.user_id
                ? {
                      id: data.background_actor.user_id,
                      name: data.background_actor.name ?? `Account ${data.background_actor.user_id}`,
                      email: '',
                  }
                : null,
        );
    };
    useEffect(() => {
        if (query.data && values === null) savedForm(query.data);
    }, [query.data, values]);
    const changed = (Object.keys(values ?? {}) as SettingKey[]).filter((key) => values?.[key] !== base[key]);
    const issuerDirty = JSON.stringify(issuer) !== JSON.stringify(issuerBase);
    const actorDirty = actor !== actorBase;
    const publicationDirty = publishNames !== publishNamesBase;
    const dirty = changed.length > 0 || issuerDirty || actorDirty || publicationDirty;
    useNavigationGuard(dirty);
    const save = useMutation({
        mutationFn: async () => {
            const patch = Object.fromEntries(changed.map((key) => [key, values?.[key]]));
            const { data } = await client.PUT('/admin/settings', {
                body: { configuration: patch, expected_revision: revision },
            });
            return must(data);
        },
        onSuccess: (data) => {
            if (data.configuration) {
                setValues(data.configuration.values);
                setBase(data.configuration.values);
                setRevision(data.configuration.revision);
            }
            cache.setQueryData(adminKeys.settings, data);
            void cache.invalidateQueries({ queryKey: ['vidyalms'] });
            setNotice(s.savedNotice);
        },
    });
    const saveRecord = useMutation({
        mutationFn: async (kind: 'issuer' | 'actor' | 'publication') => {
            const { data } = await client.PUT('/admin/settings', {
                body:
                    kind === 'publication'
                        ? { publish_learner_name: publishNames }
                        : kind === 'issuer'
                        ? { issuer }
                        : { background_actor_user_id: actor.trim() === '' ? null : Number(actor) },
            });
            return { data: must(data), kind };
        },
        onSuccess: ({ data, kind }) => {
            if (kind === 'issuer') setIssuerBase(issuer);
            else if (kind === 'actor') setActorBase(actor);
            else {
                setPublishNames(data.publish_learner_name ?? false);
                setPublishNamesBase(data.publish_learner_name ?? false);
            }
            cache.setQueryData(adminKeys.settings, data);
            setNotice(kind === 'publication'
                ? uiText('vidyalms.ui.credentialPublicationSaved', 'Name publication setting saved.')
                : kind === 'issuer' ? 'Credential issuer saved.' : 'Background account saved.');
        },
    });
    const pending = save.isPending || saveRecord.isPending;
    const conflict = save.error instanceof ProblemError && save.error.status === 409;
    const reload = async () => {
        const result = await query.refetch();
        if (result.data && !result.isError) {
            savedForm(result.data);
            save.reset();
            saveRecord.reset();
            setNotice('Latest settings loaded.');
            setDiscardOpen(false);
        }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!changed.length || pending) return;
        save.mutate();
    };
    const edit = (key: SettingKey, value: string | number | boolean) => {
        setValues((current) => ({ ...current, [key]: value }));
        setNotice('');
    };
    const matches = (field: SettingField) =>
        `${field.key} ${field.label} ${field.hint}`.toLowerCase().includes(search.trim().toLowerCase());
    const active = settingsGroups.find((group) => group.id === section) ?? settingsGroups[0]!;
    const fields: SettingField[] = search.trim()
        ? settingsGroups.flatMap((group) => group.fields).filter(matches)
        : active.fields.filter((field) => !field.headerPanel || field.headerPanel === headerPanel);
    const exportSaved = () => {
        const data = query.data?.configuration;
        if (!data) return;
        const url = URL.createObjectURL(
            new Blob([JSON.stringify({ product: 'VidyaLMS', ...data }, null, 2)], { type: 'application/json' }),
        );
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'vidyalms-settings.json';
        anchor.click();
        URL.revokeObjectURL(url);
    };
    if (query.isPending)
        return (
            <div className="vy-settings-workspace" aria-busy="true" aria-label={s.title}>
                <Skeleton className="h-24 w-full" />
                <Skeleton className="h-96 w-full" />
            </div>
        );
    if (query.isError)
        return (
            <ScreenError
                error={query.error}
                onRetry={() => void query.refetch()}
                coursesUrl={screenUrl(config, 'courses')}
            />
        );
    if (!query.data.configuration || values === null)
        return <ErrorState title={s.unavailable} onRetry={() => void query.refetch()} />;
    const data = query.data;
    const isSection = (id: string) => !search.trim() && section === id;
    const links = (entries: [string, string][]) => (
        <div className="vy-settings-links">
            {entries
                .filter(([key]) => Boolean(config.urls.screens[key]))
                .map(([key, label]) => (
                    <a key={key} href={config.urls.screens[key]}>
                        {label}
                        <ArrowUpRight size={15} />
                    </a>
                ))}
        </div>
    );
    return (
        <div className="vy-settings-workspace">
            <PageHeader
                title={
                    <>
                        {s.title}
                        <span className="vy-settings-dot">.</span>
                    </>
                }
                description={s.intro}
            />
            <div className="vy-settings-layout">
                <aside className="vy-settings-rail">
                    <label className="vy-settings-search">
                        <Search size={16} />
                        <input
                            aria-label={s.search}
                            type="search"
                            placeholder={s.search}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </label>
                    <nav aria-label={s.navigation}>
                        {settingsGroups.map((group) => (
                            <button
                                key={group.id}
                                aria-current={section === group.id && !search ? 'page' : undefined}
                                onClick={() => {
                                    navigate(`/${group.id}`);
                                    setSearch('');
                                }}
                            >
                                <group.icon size={17} />
                                <span>{group.title}</span>
                                {(group.fields.some((field) => changed.includes(field.key as SettingKey))
                                    || (group.id === 'credentials' && publicationDirty)) && (
                                    <span aria-label={uiText("vidyalms.ui.374db9c066587c09", "Unsaved section")} className="vy-settings-changed" />
                                )}
                                <ChevronRight size={14} />
                            </button>
                        ))}
                    </nav>
                    <div className="vy-settings-source">
                        <ShieldCheck size={19} />
                        <div>
                            <strong>{s.sourceTitle}</strong>
                            <p>{s.sourceHint}</p>
                        </div>
                    </div>
                </aside>
                <div className="vy-settings-content">
                    {notice && (
                        <p className="vy-settings-notice" role="status">
                            <Check size={16} />
                            {notice}
                        </p>
                    )}
                    {conflict && (
                        <div className="vy-settings-warning" role="alert">
                            <p>{s.conflict}</p>
                            <Button variant="outline" onClick={() => setDiscardOpen(true)}>
                                {s.reload}
                            </Button>
                        </div>
                    )}
                    {save.isError && !conflict && (
                        <div className="vy-settings-validation">
                            <ErrorState title={uiText("vidyalms.ui.8334d394c266d569", "Couldn't save changes.")} error={save.error} />
                            {save.error instanceof ProblemError &&
                                Object.entries(save.error.fieldErrors).map(([key, message]) => {
                                    const field = settingsGroups
                                        .flatMap((group) => group.fields)
                                        .find((item) => item.key === key);
                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => {
                                                setSearch(field?.label ?? key);
                                                requestAnimationFrame(() =>
                                                    document.getElementById(`vy-setting-${key}`)?.focus(),
                                                );
                                            }}
                                        >
                                            {field?.label ?? key}: {message}
                                        </button>
                                    );
                                })}
                        </div>
                    )}
                    {saveRecord.isError && <ErrorState title={uiText("vidyalms.ui.8334d394c266d569", "Couldn't save changes.")} error={saveRecord.error} />}
                    <Card className="vy-settings-card">
                        <header>
                            <div>
                                <h2>{search.trim() ? s.results : active.title}</h2>
                                <p>{search.trim() ? uiText("vidyalms.ui.135b2e912684cdc8", "{p0} matching settings", { p0: fields.length }) : active.description}</p>
                            </div>
                            <span>
                                <SlidersHorizontal size={23} />
                            </span>
                        </header>
                        {isSection('learner-experience') && (
                            <>
                                <LearnerHeaderPreview values={values} />
                                <div className="vy-settings-header-panels" role="group" aria-label={uiText("vidyalms.ui.27a30b69d85933cb", "Header customization")}>
                                    {(['layout', 'branding', 'spacing'] as const).map((panel) => (
                                        <button key={panel} type="button" aria-pressed={headerPanel === panel} onClick={() => setHeaderPanel(panel)}>
                                            {{ layout: 'Layout', branding: 'Branding', spacing: 'Spacing' }[panel]}
                                            {active.fields.some((field) => field.headerPanel === panel && changed.includes(field.key)) && <span aria-label={uiText("vidyalms.ui.374db9c066587c09", "Unsaved section")} className="vy-settings-changed" />}
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}
                        {isSection('storage') && <p className="vy-settings-field">
                            {uiText('vidyalms.ui.privateStorageServerHelp', 'VidyaLMS sets up a private folder outside your public website when your hosting allows it. Check System health for its status. If setup needs help, your hosting administrator can configure VIDYALMS_PRIVATE_DIR for the website and scheduled jobs. Changing the folder does not move existing files.')}
                        </p>}
                        <form ref={form} id="vy-runtime-settings" onSubmit={submit}>
                            <fieldset disabled={pending} className="vy-settings-fields">
                                {fields.map((field) => (
                                    <div
                                        className={`vy-settings-field ${field.type === 'boolean' ? 'vy-settings-switch-field' : ''}`}
                                        key={field.key}
                                    >
                                        <label htmlFor={`vy-setting-${field.key}`}>{field.label}</label>
                                        {field.type === 'boolean' ? (
                                            <input
                                                type="checkbox"
                                                role="switch"
                                                id={`vy-setting-${field.key}`}
                                                aria-describedby={`vy-setting-hint-${field.key}`}
                                                checked={Boolean(values[field.key])}
                                                onChange={(event) => edit(field.key, event.target.checked)}
                                            />
                                         ) : field.type === 'select' ? (
                                            <select id={`vy-setting-${field.key}`} aria-describedby={`vy-setting-hint-${field.key}`}
                                                value={String(values[field.key] ?? field.options?.[0]?.value ?? '')} onChange={(event) => edit(field.key, event.target.value)}>
                                                {field.options?.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                            </select>
                                        ) : field.type === 'textarea' ? (
                                            <textarea
                                                id={`vy-setting-${field.key}`}
                                                aria-describedby={`vy-setting-hint-${field.key}`}
                                                rows={5}
                                                value={String(values[field.key] ?? '')}
                                                onChange={(event) => edit(field.key, event.target.value)}
                                                maxLength={8192}
                                            />
                                        ) : (
                                            <input
                                                id={`vy-setting-${field.key}`}
                                                aria-describedby={`vy-setting-hint-${field.key}`}
                                                type={field.type ?? 'text'}
                                                min={field.type === 'number' ? field.min ?? 1 : undefined}
                                                max={field.max}
                                                step={field.type === 'number' ? 1 : undefined}
                                                required={field.type === 'number'}
                                                maxLength={field.maxLength ?? 2048}
                                                value={String(values[field.key] ?? '')}
                                                onChange={(event) =>
                                                    edit(
                                                        field.key,
                                                        field.type === 'number' && event.target.value !== ''
                                                            ? Number(event.target.value)
                                                            : event.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                        <p id={`vy-setting-hint-${field.key}`}>{field.hint}</p>
                                    </div>
                                ))}
                            </fieldset>
                        </form>
                        {search.trim() && !fields.length && (
                            <div className="vy-settings-empty">
                                <h3>{s.noResults}</h3>
                                <p>{s.searchHint}</p>
                                <Button variant="outline" onClick={() => setSearch('')}>
                                    {s.clearSearch}
                                </Button>
                            </div>
                        )}
                        {isSection('general') && (
                            <div className="vy-settings-info">
                                <ShieldCheck size={20} />
                                <p>
                                    {uiText("vidyalms.ui.c546fc20af7d3e22", "Runtime settings are shared across your learning site, APIs, and background jobs. Site timezone and payment currencies continue to follow their CMS and commerce configuration.")}</p>
                            </div>
                        )}
                        {isSection('credentials') && (
                            <section className="vy-settings-extra">
                                <h3>{uiText('vidyalms.ui.credentialPublicationTitle', 'Public verification privacy')}</h3>
                                <form onSubmit={(event) => {
                                    event.preventDefault();
                                    if (publicationDirty && !pending) saveRecord.mutate('publication');
                                }}>
                                    <fieldset disabled={pending}>
                                        <div className="vy-settings-field vy-settings-switch-field">
                                            <label htmlFor="vy-publish-learner-names">
                                                {uiText('vidyalms.ui.credentialPublicationSiteLabel', 'Allow learner names on public verification pages')}
                                            </label>
                                            <input id="vy-publish-learner-names" type="checkbox" role="switch"
                                                aria-describedby="vy-publish-learner-names-help"
                                                checked={publishNames}
                                                onChange={(event) => {
                                                    setPublishNames(event.target.checked);
                                                    setNotice('');
                                                }} />
                                            <p id="vy-publish-learner-names-help">
                                                {uiText('vidyalms.ui.credentialPublicationSiteHelp', 'Off by default. Applies to public certificate and badge verification pages. Learners can hide their own names in Achievements. Credentials remain verifiable when names are hidden. Names in downloaded files are not changed.')}
                                            </p>
                                        </div>
                                        <Button variant="outline" type="submit" disabled={!publicationDirty}>
                                            {uiText('vidyalms.ui.credentialPublicationSiteSave', 'Save name publication')}
                                        </Button>
                                    </fieldset>
                                </form>
                            </section>
                        )}
                        {isSection('credentials') && (
                            <section className="vy-settings-extra">
                                <h3>{uiText("vidyalms.ui.420daf33fe95430e", "Credential issuer")}</h3>
                                <p>
                                    {uiText("vidyalms.ui.a30e0f004aca1680", "Name the organization represented on your credentials. Blank fields inherit the academy defaults.")}</p>
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        saveRecord.mutate('issuer');
                                    }}
                                >
                                    <fieldset disabled={pending}>
                                        {(['name', 'url', 'image'] as const).map((key) => (
                                            <label key={key}>
                                                {key === 'name'
                                                    ? uiText("vidyalms.ui.fe154fcf7540421b", "Issuer name")
                                                    : key === 'url'
                                                      ? uiText("vidyalms.ui.9604823aa8aa35f1", "Issuer website")
                                                      : uiText("vidyalms.ui.e4f34c998bd1fd34", "Issuer logo URL")}
                                                <input
                                                    type={key === 'name' ? 'text' : 'url'}
                                                    value={issuer[key] ?? ''}
                                                    onChange={(event) =>
                                                        setIssuer({ ...issuer, [key]: event.target.value })
                                                    }
                                                />
                                            </label>
                                        ))}
                                        <Button variant="outline" type="submit" disabled={!issuerDirty}>
                                            {uiText("vidyalms.ui.5e716796205494ce", "Save issuer")}</Button>
                                    </fieldset>
                                </form>
                                <p className="vy-settings-muted">{uiText("vidyalms.ui.670e87d20805c730", "Current issuer: ")}{data.issuer.resolved.name}</p>
                                {links([['certificates', 'Manage certificates and templates']])}
                            </section>
                        )}
                        {isSection('email') &&
                            links([
                                ['email-templates', 'Customize email templates'],
                                ['notifications', 'Manage notifications'],
                                ['automations', 'Manage automations'],
                            ])}
                        {isSection('network') && (
                            <div className="vy-settings-info">
                                <ShieldCheck size={20} />
                                <p>{uiText("vidyalms.ui.outboundIntegrations", "These controls apply to general outbound integrations, including webhooks, directory sync and remote AI. Configure payment connections in your host shop.")}</p>
                            </div>
                        )}
                        {isSection('commerce') && (
                            <section className="vy-settings-extra">
                                <h3>{uiText("vidyalms.ui.shopSetup", "Shop setup")}</h3>
                                <p>{uiText('vidyalms.ui.paidCourseSetupPath', 'Open a course, choose Access → Visibility & pricing, select Paid and save. Then create a shop product, set its price and connect it to the course.')}</p>
                                {links([['courses', uiText('vidyalms.ui.manageCourseAccess', 'Manage course access')]])}
                                <ShopSetup shop={config.shop} />
                                {config.shop?.installed && config.shop.mappingApiUrl && (
                                    <ShopMappings endpoint={config.shop.mappingApiUrl} headers={config.api.headers} />
                                )}
                                <div className="vy-settings-links">
                                    {!config.shop && data.host_settings_url && <a href={data.host_settings_url}>
                                        {uiText("vidyalms.ui.openShopSetup", "Open shop setup")}<ArrowUpRight size={15} />
                                    </a>}
                                </div>
                                {links([['operations', uiText("vidyalms.ui.integrationHealth", "Open system health")]])}
                                {!config.shop && <>
                                <h3>{uiText("vidyalms.ui.registeredShopSources", "Registered shop sources")}</h3>
                                <p>{uiText("vidyalms.ui.registeredShopSourcesHint", "These are the sources registered by the server. Confirm plugin setup and course-product mapping in your host; registration alone does not confirm that purchases will grant access.")}</p>
                                <div className="vy-settings-connections">
                                    {(data.commerce.sources ?? []).map((source) => (
                                        <div key={source.key}>
                                            <strong>{source.label}</strong>
                                            <span>{source.is_default ? uiText("vidyalms.ui.8b5673ed60452ec2", "Default source") : uiText("vidyalms.ui.registeredSource", "Registered source")}</span>
                                        </div>
                                    ))}
                                    {!(data.commerce.sources ?? []).length && (
                                        <p>{uiText("vidyalms.ui.noShopSource", "No shop source is registered. Enable the host integration plugin to offer paid courses.")}</p>
                                    )}
                                </div>
                                </>}
                                <h3>{uiText("vidyalms.ui.12c1818dfe7d5802", "Background enrolment account")}</h3>
                                <p>
                                    {uiText("vidyalms.ui.9332c3efd2da55c8", "Webhooks and scheduled jobs grant course access as this account. It must have permission to enrol other learners.")}</p>
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        saveRecord.mutate('actor');
                                    }}
                                >
                                    <fieldset disabled={pending}>
                                        <UserPicker
                                            label={uiText("vidyalms.ui.5bdbcf5860ff5e86", "Background account")}
                                            placeholder={uiText("vidyalms.ui.a949ce5b6107fab1", "Search by name or email…")}
                                            hint={uiText("vidyalms.ui.da5d615be6107ba2", "Search for the account that should grant course access.")}
                                            value={actorAccount}
                                            onChange={(account) => {
                                                setActorAccount(account);
                                                setActor(account?.id.toString() ?? '');
                                            }}
                                        />
                                        <p>
                                            {data.background_actor.name
                                                ? uiText("vidyalms.ui.ae04d0e6f63d1e51", "{p0} · {p1}", { p0: data.background_actor.name, p1: data.background_actor.may_grant ? 'Can grant access' : 'Cannot grant access' })
                                                : uiText("vidyalms.ui.389ebcd38f622b3c", "No account selected. Leaving this empty prevents automatic access grants.")}
                                        </p>
                                        <Button variant="outline" type="submit" disabled={!actorDirty}>
                                            {uiText("vidyalms.ui.885ad553a26cfb26", "Save background account")}</Button>
                                    </fieldset>
                                </form>
                            </section>
                        )}
                        {isSection('operations') && (
                            <div className="vy-settings-facts">
                                <div>
                                    <strong>{uiText("vidyalms.ui.8668180b0aedbe64", "Progress heartbeat")}</strong>
                                    <p>
                                        {uiText("vidyalms.ui.73088c2d4cbdc2ca", "Every ")}{data.heartbeat.interval_seconds} {uiText("vidyalms.ui.09aed92886814315", " seconds, with at most")}{' '}
                                        {data.heartbeat.max_seconds_per_heartbeat} {uiText("vidyalms.ui.f0ebe7de193a9514", " seconds credited per heartbeat.")}</p>
                                </div>
                                <div>
                                    <strong>{uiText("vidyalms.ui.9905864022cacb8f", "Access expiry sweep")}</strong>
                                    <p>{data.expiry_sweep.schedule || 'No schedule configured.'}</p>
                                    <p>
                                        {uiText("vidyalms.ui.f636f90bbb7941ad", "Last run:")}{' '}
                                        {data.expiry_sweep.last_run_at
                                            ? new Date(data.expiry_sweep.last_run_at).toLocaleString(config.locale)
                                            : uiText("vidyalms.ui.b37c7879f6c359f6", "Not recorded")}
                                    </p>
                                </div>
                                <div>
                                    <strong>{uiText("vidyalms.ui.ef2ba24ab42aa5d5", "Default certificate template")}</strong>
                                    <p>{data.certificate_template?.name || 'No published default template.'}</p>
                                </div>
                                {links([['operations', 'Open system health']])}
                            </div>
                        )}
                        {isSection('appearance') && (
                            <div className="vy-settings-appearance">
                                <span>{uiText("vidyalms.ui.81acaafba961bb83", "Aa")}</span>
                                <h3>{uiText("vidyalms.ui.5f7809e60b0a7653", "Bricolage Grotesque")}</h3>
                                <p>{uiText("vidyalms.ui.9a0cf7170f33f45a", "Aa Bb Cc · 0123456789")}</p>
                                <p>{uiText("vidyalms.ui.d63c7bab1b9990b4", "Consistent typography across every workspace.")}</p>
                                <div className="vy-settings-info">
                                    {uiText("vidyalms.ui.3c4ad52882bd5458", "Use the light or dark theme control in the workspace header to change your personal appearance preference.")}</div>
                            </div>
                        )}
                    </Card>
                    <div className="vy-settings-savebar">
                        <span>
                            <Check size={16} />
                            {save.isPending
                                ? s.saving
                                : changed.length
                                  ? uiText("vidyalms.ui.3d94344642692e92", "{p0} unsaved {p1}", { p0: changed.length, p1: changed.length === 1 ? 'setting' : 'settings' })
                                  : issuerDirty
                                    ? uiText("vidyalms.ui.f961d427e3fc977e", "Unsaved issuer changes")
                                    : actorDirty
                                      ? uiText("vidyalms.ui.180a8d2e38db5081", "Unsaved background account")
                                      : s.saved}
                        </span>
                        <div>
                            <Button variant="ghost" disabled={!dirty || pending} onClick={() => setDiscardOpen(true)}>
                                {s.discard}
                            </Button>
                            <Button
                                type="submit"
                                form="vy-runtime-settings"
                                disabled={!changed.length || pending || conflict}
                            >
                                <Save size={16} />
                                {s.save}
                            </Button>
                        </div>
                    </div>
                    <div className="vy-settings-export">
                        <Button variant="ghost" onClick={exportSaved} title={s.exportHint}>
                            <Download size={15} />
                            {s.export}
                        </Button>
                    </div>
                </div>
            </div>
            <Dialog open={discardOpen} onOpenChange={setDiscardOpen}>
                <DialogContent className="vy-settings-dialog">
                    <DialogTitle>{s.resetTitle}</DialogTitle>
                    <DialogDescription>{s.resetBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDiscardOpen(false)}>
                            {s.cancel}
                        </Button>
                        <Button disabled={query.isFetching} onClick={() => void reload()}>
                            {s.confirmDiscard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
