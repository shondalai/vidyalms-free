import { translate as uiText } from '@/shared/i18n';
import {
    Activity,
    CheckCheck,
    Clock,
    TriangleAlert,
    Download,
    RefreshCw,
    Server,
    HardDrive,
    Upload,
    History,
} from 'lucide-react';
import { useState } from 'react';
import { WorkspacePage, WorkspaceCallout, WorkspaceTabs, downloadWorkspaceJson } from '../components/workspace-page';
import { ImportWizard } from './import-wizard';
import { ImportRecovery } from './import-recovery';
import { ImportProblems } from './import-problems';
import type { components, OperationTask } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorState,
    Skeleton,
    buttonVariants,
    type DataTableColumn,
} from '@/shared/components';
import { useDebounced, useHashRoute } from '../hooks';
import { CompactStats } from '../components/compact-stats';
import { FilterSelect } from '../components/filter-toolbar';
import { ListFrame } from '../components/list-frame';
import { useAdminConfig } from '../config';
import { useImportJob, useImportJobs, useOperationJob, useOperationJobs, useOperations } from './api';
import { operationsStrings as s } from './strings';
import { operationOrigin } from './origin';

/** The one value every empty cell on these screens shows, so a gap reads the same everywhere. */
const NONE = '-';

const JOB_STATES = ['queued', 'running', 'complete', 'failed', 'cancelled'] as const;

function locationFor(hash: string) {
    const url = new URL(hash || '/', 'https://route.invalid');
    const page = Number(url.searchParams.get('page') ?? 1);
    const match = /^\/jobs\/([1-9][0-9]*)$/.exec(url.pathname);
    const id = match ? Number(match[1]) : null;

    return {
        url,
        page: Number.isSafeInteger(page) && page > 0 && page <= 1_000_000 ? page : 1,
        id: Number.isSafeInteger(id) ? id : null,
        invalid: url.pathname !== '/' && (id === null || !Number.isSafeInteger(id)),
        search: (url.searchParams.get('search') ?? '').slice(0, 100),
        state: url.searchParams.get('state') ?? '',
    };
}

function useLocation() {
    const [hash, navigate] = useHashRoute();
    const route = locationFor(hash);

    return {
        ...route,
        navigate,
        change: (changes: Record<string, string | number>) => {
            const url = new URL(route.url);

            for (const [key, value] of Object.entries(changes)) {
                if (value === '' || (key === 'page' && value === 1)) {
                    url.searchParams.delete(key);
                } else {
                    url.searchParams.set(key, String(value));
                }
            }

            navigate(`${url.pathname}${url.search}`);
        },
    };
}

const date = (value: string | null | undefined) => (value ? `${value} UTC` : NONE);

function Facts({ facts }: { facts: [string, string | number][] }) {
    return (
        <dl className="vy-detail-facts grid gap-x-6 gap-y-3 sm:grid-cols-2">
            {facts.map(([label, value]) => (
                <div key={label} className="min-w-0 border-b border-border pb-3">
                    <dt className="text-xs text-fg-2">{label}</dt>
                    <dd className="mt-1 break-words text-sm text-fg">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function OperationsScreen() {
    const location = useLocation();

    if (location.invalid) {
        return <EmptyState title={s.invalid} />;
    }

    if (location.id !== null) {
        return <OperationDetail id={location.id} back={() => location.navigate(`/${location.url.search}`)} />;
    }

    return <OperationsList location={location} />;
}

function OperationsList({ location }: { location: ReturnType<typeof useLocation> }) {
    const overview = useOperations();
    const type = useDebounced(location.search);
    const state = (JOB_STATES as readonly string[]).includes(location.state)
        ? (location.state as (typeof JOB_STATES)[number])
        : undefined;
    const jobs = useOperationJobs(location.page, state, type || undefined);
    const counts = overview.data?.scheduler.counts;
    const columns: DataTableColumn<OperationTask>[] = [
        {
            key: 'type',
            header: s.type,
            weight: 2,
            minWidth: 220,
            cell: (row) => (
                <span className="grid gap-1">
                    <span className="font-semibold text-fg">{row.type}</span>
                    <span className="text-xs text-fg-2">{s.job(row.id)}</span>
                </span>
            ),
        },
        {
            key: 'state',
            header: s.state,
            width: '120px',
            cell: (row) => (
                <Badge variant={row.state === 'failed' ? 'warn' : row.state === 'complete' ? 'accent' : 'neutral'}>
                    {row.state}
                </Badge>
            ),
        },
        { key: 'attempts', header: s.attempts, width: '90px', cell: (row) => row.attempts },
        { key: 'scheduled', header: s.scheduled, width: '190px', cell: (row) => row.scheduled_at },
        {
            key: 'actions',
            header: s.action,
            width: '145px',
            cell: (row) => (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => location.navigate(`/jobs/${row.id}${location.url.search}`)}
                >
                    {s.open}
                </Button>
            ),
        },
    ];

    return (
        <WorkspacePage
            title={s.title}
            description={uiText("vidyalms.ui.644e3cb4d8ce208a", "Keep learning running smoothly. Monitor background work, investigate issues, and follow every job through to completion.")}
            actions={
                <>
                    <Button
                        variant="outline"
                        disabled={!overview.data}
                        onClick={() => downloadWorkspaceJson('vidyalms-diagnostics.json', overview.data)}
                    >
                        <Download size={16} /> {uiText("vidyalms.ui.5af7f6ee77125436", " Export diagnostics")}</Button>
                    <Button
                        onClick={() => {
                            void overview.refetch();
                            void jobs.refetch();
                        }}
                    >
                        <RefreshCw size={16} />
                        {s.refresh}
                    </Button>
                </>
            }
        >
            {overview.isError && (
                <ErrorState
                    error={overview.error}
                    onRetry={() => {
                        void overview.refetch();
                    }}
                />
            )}
            <CompactStats
                items={[
                    { label: s.queued, value: counts?.queued ?? NONE, icon: Clock },
                    { label: s.running, value: counts?.running ?? NONE, icon: Activity, tone: 'accent' },
                    { label: s.failed, value: counts?.failed ?? NONE, icon: TriangleAlert, tone: 'warning' },
                    { label: s.complete, value: counts?.complete ?? NONE, icon: CheckCheck, tone: 'positive' },
                ]}
            />
            {overview.data && (
                <>
                    <WorkspaceCallout
                        icon={counts?.failed || counts?.stalled ? TriangleAlert : Activity}
                        tone={counts?.failed || counts?.stalled ? 'warning' : 'accent'}
                        title={
                            counts?.failed || counts?.stalled
                                ? uiText("vidyalms.ui.9f3342784b05ccb1", "Some background work needs your attention")
                                : uiText("vidyalms.ui.407ed5a115ca53c2", "Your operations, at a glance")
                        }
                        action={
                            <Button variant="outline" onClick={() => location.change({ state: 'failed', page: 1 })}>
                                {uiText("vidyalms.ui.fa7d9ef116fca0f0", "Review failed jobs")}</Button>
                        }
                    >
                        {s.observed}: {date(overview.data.generated_at)}. {s.intro}
                    </WorkspaceCallout>
                    <div className="vy-service-grid">
                        <section className="vy-workspace-panel">
                            <header>
                                <span className="vy-workspace-glyph">
                                    <Server size={20} />
                                </span>
                                <div>
                                    <h2>{uiText("vidyalms.ui.9273a4e87b39b5a5", "Scheduler & monitoring")}</h2>
                                    <p>{uiText("vidyalms.ui.9d9be948cd34c4a3", "Recorded queue activity and available signals.")}</p>
                                </div>
                            </header>
                            <Facts
                                facts={[
                                    [s.due, counts?.due ?? NONE],
                                    [s.stalled, counts?.stalled ?? NONE],
                                    [s.retention, overview.data.scheduler.retention_days ?? NONE],
                                    [s.heartbeat, s.unknown],
                                    [s.delivery, s.unknown],
                                ]}
                            />
                        </section>
                        <section className="vy-workspace-panel">
                            <header>
                                <span className="vy-workspace-glyph">
                                    <HardDrive size={20} />
                                </span>
                                <div>
                                    <h2>{uiText("vidyalms.ui.fd17fb527d1e452e", "Delivery & imports")}</h2>
                                    <p>{uiText("vidyalms.ui.f7837afc12e774e7", "Work waiting, running, and recently completed.")}</p>
                                </div>
                            </header>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {[
                                    [s.notifications, overview.data.notifications.counts],
                                    [s.importQueue, overview.data.imports.counts],
                                ].map(([label, values]) => (
                                    <section key={label as string}>
                                        <h3 className="mb-2 text-sm font-semibold text-fg">{label as string}</h3>
                                        <Facts facts={Object.entries(values as Record<string, number>)} />
                                    </section>
                                ))}
                            </div>
                        </section>
                    </div>
                </>
            )}
            <ListFrame
                title={s.jobs}
                search={{
                    label: s.type,
                    placeholder: s.type,
                    value: location.search,
                    onChange: (search) => location.change({ search, page: 1 }),
                }}
                chips={
                    <FilterSelect
                        label={s.state}
                        value={location.state}
                        options={[
                            { value: '', label: s.allStates },
                            ...JOB_STATES.map((value) => ({ value, label: s[value as 'queued'] })),
                        ]}
                        onValueChange={(state) => location.change({ state, page: 1 })}
                    />
                }
                action={
                    <Button
                        variant="outline"
                        onClick={() => {
                            void overview.refetch();
                            void jobs.refetch();
                        }}
                    >
                        {s.refresh}
                    </Button>
                }
                page={location.page}
                perPage={20}
                total={jobs.data?.total ?? 0}
                shownCount={jobs.data?.items.length ?? 0}
                onPageChange={(page) => location.change({ page })}
                banner={
                    jobs.isError ? (
                        <ErrorState
                            error={jobs.error}
                            onRetry={() => {
                                void jobs.refetch();
                            }}
                        />
                    ) : jobs.data?.available === false ? (
                        <p className="text-sm text-fg-2">{s.unavailable}</p>
                    ) : undefined
                }
            >
                <DataTable
                    caption={s.jobs}
                    rows={jobs.data?.items ?? []}
                    rowKey={(row) => row.id}
                    columns={columns}
                    loading={jobs.isPending}
                    empty={<EmptyState title={s.empty} />}
                />
            </ListFrame>
        </WorkspacePage>
    );
}

function OperationDetail({ id, back }: { id: number; back: () => void }) {
    const query = useOperationJob(id);
    const config = useAdminConfig();
    const origin = query.data ? operationOrigin(query.data, config.urls.screens, window.location.href) : null;

    return (
        <WorkspacePage
            title={s.job(id)}
            description={uiText("vidyalms.ui.2cce7548570346b9", "Understand what happened, review the diagnostic, and return to the workflow that owns this job.")}
        >
            <Button className="justify-self-start" variant="ghost" onClick={back}>
                {s.back}
            </Button>
            {query.isError && (
                <ErrorState
                    error={query.error}
                    onRetry={() => {
                        void query.refetch();
                    }}
                />
            )}
            {query.isPending && <Skeleton className="h-48 w-full" />}
            {query.data && (
                <section className="grid gap-5 rounded-lg border border-border bg-surface p-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 className="font-display text-xl font-semibold text-fg">{s.job(id)}</h2>
                        <Badge>{query.data.state}</Badge>
                        <Button
                            className="ml-auto"
                            variant="outline"
                            onClick={() => {
                                void query.refetch();
                            }}
                        >
                            {s.refresh}
                        </Button>
                    </div>
                    <Facts
                        facts={[
                            [s.type, query.data.type],
                            [s.group, query.data.group_key ?? NONE],
                            [s.attempts, query.data.attempts],
                            [s.scheduled, query.data.scheduled_at],
                            [s.started, date(query.data.started_at)],
                            [s.finished, date(query.data.finished_at)],
                        ]}
                    />
                    <p className="break-words text-sm text-fg-2">{query.data.diagnostic ?? s.noDiagnostic}</p>
                    <div className="grid justify-items-start gap-3 border-t border-border pt-4">
                        <p className="text-xs text-fg-2">{s.safeRetry}</p>
                        {origin && (
                            <a className={buttonVariants({ variant: 'outline' })} href={origin.href}>
                                {origin.label}
                            </a>
                        )}
                    </div>
                </section>
            )}
        </WorkspacePage>
    );
}

type ImportJob = components['schemas']['ImportJob'];

export function ImportsScreen() {
    const location = useLocation();
    const config = useAdminConfig();
    const tab = location.url.searchParams.get('tab') === 'new' ? 'new' : 'history';
    const [started, setStarted] = useState(tab === 'new');

    if (location.invalid) {
        return <EmptyState title={s.invalid} />;
    }

    return (
        <WorkspacePage
            title={uiText("vidyalms.ui.7addf6f77c4bc0b4", "Import & transfer")}
            description={s.importIntro}
            actions={
                config.importTransport ? (
                    <Button
                        onClick={() => {
                            setStarted(true);
                            location.navigate('/?tab=new');
                        }}
                    >
                        <Upload size={16} />
                        {uiText("vidyalms.ui.53085c600182060e", "New import")}</Button>
                ) : config.urls.nativeImport ? (
                    <a className={buttonVariants({ variant: 'primary' })} href={config.urls.nativeImport}>
                        {s.newImport}
                    </a>
                ) : undefined
            }
        >
            {location.id === null && (
                <WorkspaceTabs
                    label={uiText("vidyalms.ui.37756642b712150e", "Import workspace")}
                    tabs={[
                        { key: 'history', label: uiText("vidyalms.ui.feace94bed365e35", "Import history"), icon: History },
                        { key: 'new', label: uiText("vidyalms.ui.53085c600182060e", "New import"), icon: Upload },
                    ]}
                    current={tab}
                    onChange={(key) => {
                        if (key === 'new') setStarted(true);
                        location.change({ tab: key === 'history' ? '' : key });
                    }}
                />
            )}
            <div hidden={location.id !== null || tab !== 'new'}>
                {(started || tab === 'new') && <ImportWizard onOpen={(id) => location.navigate(`/jobs/${id}`)} />}
            </div>
            {location.id === null && tab === 'history' && <ImportList location={location} />}
            {location.id !== null && (
                <ImportDetail id={location.id} back={() => location.navigate(`/${location.url.search}`)} />
            )}
        </WorkspacePage>
    );
}

function ImportList({ location }: { location: ReturnType<typeof useLocation> }) {
    const query = useImportJobs(location.page);
    const columns: DataTableColumn<ImportJob>[] = [
        {
            key: 'id',
            header: s.importer,
            weight: 2,
            minWidth: 200,
            cell: (row) => (
                <span className="grid gap-1">
                    <strong>{row.importer}</strong>
                    <span className="text-xs text-fg-2">{s.job(row.id)}</span>
                </span>
            ),
        },
        {
            key: 'state',
            header: s.state,
            width: '180px',
            cell: (row) => (
                <span className="grid gap-1">
                    <Badge
                        variant={
                            row.status === 'failed' || row.status === 'completed_with_problems' || row.stalled
                                ? 'warn'
                                : 'neutral'
                        }
                    >
                        {s.states[row.status]}
                    </Badge>
                    {row.stalled && <span className="text-xs text-warn-strong">{s.importStalled}</span>}
                </span>
            ),
        },
        { key: 'mode', header: s.mode, width: '130px', cell: (row) => (row.dry_run ? s.preview : s.applied) },
        { key: 'created', header: s.created, width: '190px', cell: (row) => date(row.created_at) },
        {
            key: 'action',
            header: s.action,
            width: '150px',
            cell: (row) => (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => location.navigate(`/jobs/${row.id}${location.url.search}`)}
                >
                    {s.inspect}
                </Button>
            ),
        },
    ];

    return (
        <ListFrame
            title={s.importHistory}
            page={location.page}
            perPage={20}
            total={query.data?.total ?? 0}
            shownCount={query.data?.items.length ?? 0}
            onPageChange={(page) => location.change({ page })}
            action={
                <Button
                    variant="outline"
                    onClick={() => {
                        void query.refetch();
                    }}
                >
                    {s.refresh}
                </Button>
            }
            banner={
                query.isError ? (
                    <ErrorState
                        error={query.error}
                        onRetry={() => {
                            void query.refetch();
                        }}
                    />
                ) : undefined
            }
        >
            <DataTable
                caption={s.importHistory}
                rows={query.data?.items ?? []}
                rowKey={(row) => row.id}
                columns={columns}
                loading={query.isPending}
                empty={<EmptyState title={s.importEmpty} />}
            />
        </ListFrame>
    );
}

function ImportDetail({ id, back }: { id: number; back: () => void }) {
    const query = useImportJob(id);
    const config = useAdminConfig();
    const [, navigate] = useHashRoute();
    const [problems, setProblems] = useState(false);

    return (
        <div className="grid gap-4">
            <Button className="justify-self-start" variant="ghost" onClick={back}>
                {s.backImports}
            </Button>
            {query.isError && (
                <ErrorState
                    error={query.error}
                    onRetry={() => {
                        void query.refetch();
                    }}
                />
            )}
            {query.isPending && <Skeleton className="h-48 w-full" />}
            {query.data && (
                <section className="grid gap-5 rounded-lg border border-border bg-surface p-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 className="font-display text-xl font-semibold text-fg">{s.job(id)}</h2>
                        <Badge>{s.states[query.data.status]}</Badge>
                        <Button
                            variant="outline"
                            className="ml-auto"
                            onClick={() => {
                                void query.refetch();
                            }}
                        >
                            {s.refresh}
                        </Button>
                    </div>
                    {(query.data.status === 'failed' || query.data.stalled) && (
                        <p className="rounded border border-border bg-warn-soft p-3 text-sm text-warn-strong">
                            {uiText("vidyalms.ui.86cae3aa504b11ab", "A failed or stalled import may already have created records. Review its results before recovering it. Existing records are retained.")}</p>
                    )}
                    <Facts
                        facts={[
                            [s.importer, query.data.importer],
                            [s.source, query.data.source ?? 'Source removed by retention'],
                            [s.mode, query.data.dry_run ? s.preview : s.applied],
                            [s.started, date(query.data.started_at)],
                            [s.finished, date(query.data.finished_at)],
                        ]}
                    />
                    {(query.data.status === 'failed' ||
                        query.data.stalled ||
                        query.data.status === 'completed_with_problems') &&
                        !config.importTransport &&
                        config.urls.nativeImport && (
                            <a className={buttonVariants({ variant: 'outline' })} href={config.urls.nativeImport}>
                                {uiText("vidyalms.ui.39a54971a3f1bc10", "Open import recovery controls")}</a>
                        )}
                    <Button
                        className="justify-self-start"
                        variant="outline"
                        onClick={() => downloadWorkspaceJson(`vidyalms-import-${id}.json`, query.data)}
                    >
                        <Download size={16} />
                        {uiText("vidyalms.ui.7f23a1604edd9d30", "Download results")}</Button>
                    <ImportRecovery key={query.data.id} job={query.data} onOpen={(next) => navigate(`/jobs/${next}`)} />
                    <Button
                        variant="outline"
                        className="justify-self-start"
                        onClick={() => setProblems((value) => !value)}
                        aria-expanded={problems}
                    >
                        {problems ? uiText("vidyalms.ui.5d9e6d897fba8856", "Hide row problems") : uiText("vidyalms.ui.dc65db7da397273c", "Inspect row problems")}
                    </Button>
                    {problems && <ImportProblems key={id} id={id} />}
                    <div>
                        <h3 className="mb-3 text-sm font-semibold text-fg">{s.totals}</h3>
                        <Facts facts={Object.entries(query.data.totals)} />
                    </div>
                    <div>
                        <h3 className="mb-3 text-sm font-semibold text-fg">{s.notes}</h3>
                        {query.data.notes.length === 0 && <p className="text-sm text-fg-2">{s.noNotes}</p>}
                        <ol className="grid max-h-96 gap-2 overflow-y-auto rounded border border-border p-3">
                            {query.data.notes.map((note, index) => (
                                <li key={index} className="flex min-w-0 items-start gap-3 text-xs">
                                    <Badge variant={note.level === 'info' ? 'neutral' : 'warn'}>{note.level}</Badge>
                                    <span className="min-w-0 break-words text-fg">{note.message}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>
            )}
        </div>
    );
}
