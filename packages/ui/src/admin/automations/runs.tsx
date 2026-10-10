import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import {
    Badge,
    Button,
    DataTable,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    ErrorState,
    Pagination,
} from '@/shared/components';
import { ArrowLeft, ArrowUpRight, RefreshCw } from 'lucide-react';
import { PageHeader } from '../components/page-header';
import { useAdminConfig, workbenchUrl } from '../config';
import {
    useAutomationCatalogue,
    useAutomationRule,
    useAutomationRunActions,
    useAutomationRuns,
    type AutomationActionLogEntry,
    type AutomationRun,
    type AutomationRunListParams,
} from './api';
import { automationHash } from './routes';
import { automationStrings as strings } from './strings';
import { providerLabel, readable } from './workspace';

function date(value?: string | null) {
    if (!value) return '—';
    const parsed = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
    return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
function Subject({ type, id }: { type?: string | null; id?: number | null }) {
    const config = useAdminConfig();
    const text = id ? `${type ?? ''} #${id}` : strings.common.none;
    const url = !id
        ? null
        : type === 'course'
          ? workbenchUrl(config, id)
          : ['user', 'learner'].includes(type ?? '') && config.urls.screens.learners
            ? `${config.urls.screens.learners.split('#')[0]}#/learner/${id}`
            : null;
    return url ? (
        <a href={url} className="vy-auto-text-link">
            {text}
        </a>
    ) : (
        <span>{text}</span>
    );
}

export function AutomationRunList({ ruleId, navigate }: { ruleId: number; navigate: (hash: string) => void }) {
    const [page, setPage] = useState(1);
    const [state, setState] = useState<AutomationRunListParams['state']>();
    const [mode, setMode] = useState<'all' | 'live' | 'rehearsal'>('all');
    const rule = useAutomationRule(ruleId);
    const runs = useAutomationRuns(ruleId, {
        page,
        per_page: 25,
        state,
        dry_run: mode === 'all' ? undefined : mode === 'rehearsal',
    });

    if (runs.isError) {
        return <ErrorState error={runs.error} title={strings.runs.errorTitle} onRetry={() => void runs.refetch()} />;
    }
    const data = runs.data;

    return (
        <div className="vy-auto-run-panel">
            <Button
                className="justify-self-start"
                variant="ghost"
                size="sm"
                onClick={() => navigate(automationHash.rule(ruleId))}
            >
                <ArrowLeft size={15} />
                {strings.runs.back}
            </Button>
            <PageHeader
                title={strings.workspace.activity}
                description={rule.data?.title ?? `#${ruleId}`}
                actions={
                    <Button variant="outline" disabled={runs.isFetching} onClick={() => void runs.refetch()}>
                        <RefreshCw size={15} />
                        {strings.workspace.refresh}
                    </Button>
                }
            />
            <div className="vy-auto-toolbar">
                <nav className="vy-auto-tabs" aria-label={strings.workspace.activity}>
                    {(
                        [
                            { key: 'all', label: strings.workspace.allRuns },
                            { key: 'live', label: strings.workspace.realRuns },
                            { key: 'rehearsal', label: strings.workspace.rehearsals },
                        ] as const
                    ).map(({ key, label }) => (
                        <button
                            key={key}
                            aria-current={mode === key ? 'page' : undefined}
                            onClick={() => {
                                setMode(key);
                                setPage(1);
                            }}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                <label className="vy-auto-field">
                    <span>{strings.workspace.runState}</span>
                    <select
                        value={state ?? ''}
                        onChange={(event) => {
                            setState(
                                event.target.value
                                    ? (event.target.value as AutomationRunListParams['state'])
                                    : undefined,
                            );
                            setPage(1);
                        }}
                    >
                        <option value="">{strings.list.anyState}</option>
                        {['pending_fanout', 'running', 'completed', 'failed', 'cancelled'].map((key) => (
                            <option key={key} value={key}>
                                {readable(key)}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <DataTable<AutomationRun>
                caption={strings.runs.title}
                loading={runs.isPending}
                rows={data?.items ?? []}
                rowKey={(run) => run.id}
                empty={<EmptyState title={strings.runs.empty} description={strings.runs.emptyBody} />}
                columns={[
                    {
                        key: 'when',
                        header: strings.runs.columns.when,
                        minWidth: 180,
                        cell: (run) => (
                            <div className="grid gap-0.5">
                                <span className="text-fg">{date(run.started_at ?? run.created_at)}</span>
                                <small className="text-fg-2">
                                    #{run.id} · {strings.workspace.version} {run.rule_version}
                                </small>
                                {run.dry_run && <Badge variant="neutral">{strings.runs.dryRunBadge}</Badge>}
                            </div>
                        ),
                    },
                    {
                        key: 'subject',
                        header: strings.runs.columns.subject,
                        width: '160px',
                        cell: (run) => <Subject type={run.subject_type} id={run.subject_id} />,
                    },
                    {
                        key: 'state',
                        header: strings.runs.columns.state,
                        width: '140px',
                        cell: (run) => (
                            <Badge
                                variant={
                                    run.state === 'completed' ? 'ok' : run.state === 'failed' ? 'danger' : 'neutral'
                                }
                            >
                                {readable(run.state)}
                            </Badge>
                        ),
                    },
                    {
                        key: 'depth',
                        header: strings.runs.columns.depth,
                        width: '120px',
                        align: 'end',
                        cell: (run) => run.cascade_depth,
                    },
                    {
                        key: 'counters',
                        header: strings.runs.columns.counters,
                        width: '200px',
                        cell: (run) => (
                            <span className="tabular-nums text-fg-2">
                                {run.counters.matched} / {run.counters.executed} / {run.counters.skipped} /{' '}
                                {run.counters.failed}
                            </span>
                        ),
                    },
                    {
                        key: 'go',
                        header: strings.runs.openActions,
                        hideHeader: true,
                        width: '130px',
                        align: 'end',
                        cell: (run) => (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => navigate(automationHash.actions(ruleId, run.id))}
                            >
                                {strings.runs.openActions}
                            </Button>
                        ),
                    },
                ]}
            />
            {data !== undefined && data.total > data.per_page && (
                <Pagination
                    label={strings.runs.title}
                    page={data.page}
                    perPage={data.per_page}
                    total={data.total}
                    onPageChange={setPage}
                />
            )}
        </div>
    );
}

export function AutomationActionLog({
    ruleId,
    runId,
    navigate,
}: {
    ruleId: number;
    runId: number;
    navigate: (hash: string) => void;
}) {
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<AutomationActionLogEntry | null>(null);
    const catalogue = useAutomationCatalogue();
    const log = useAutomationRunActions(ruleId, runId, page);
    const strings2 = strings.runs.actionLog;

    if (log.isError) {
        return <ErrorState error={log.error} title={strings2.errorTitle} onRetry={() => void log.refetch()} />;
    }
    const data = log.data;

    return (
        <div className="vy-auto-run-panel">
            <Button
                className="justify-self-start"
                variant="ghost"
                size="sm"
                onClick={() => navigate(automationHash.runs(ruleId))}
            >
                <ArrowLeft size={15} />
                {strings2.back}
            </Button>
            <PageHeader
                title={strings2.title}
                description={uiText("vidyalms.ui.9d066be43ed42a5b", "{p0} #{p1}", { p0: strings.runs.title, p1: runId })}
                actions={
                    <Button variant="outline" disabled={log.isFetching} onClick={() => void log.refetch()}>
                        <RefreshCw size={15} />
                        {strings.workspace.refresh}
                    </Button>
                }
            />
            <DataTable<AutomationActionLogEntry>
                caption={strings2.title}
                loading={log.isPending}
                rows={data?.items ?? []}
                rowKey={(entry) => entry.id}
                empty={<EmptyState title={strings2.empty} description={strings2.emptyBody} />}
                columns={[
                    {
                        key: 'step',
                        header: strings2.columns.step,
                        width: '80px',
                        align: 'end',
                        cell: (entry) => entry.step_index + 1,
                    },
                    {
                        key: 'action',
                        header: strings2.columns.action,
                        minWidth: 180,
                        cell: (entry) => (
                            <span className="text-fg">
                                {providerLabel(
                                    catalogue.data?.actions.find((item) => item.key === entry.action_key),
                                    entry.action_key,
                                )}
                            </span>
                        ),
                    },
                    {
                        key: 'subject',
                        header: strings2.columns.subject,
                        width: '150px',
                        cell: (entry) => <Subject type={entry.subject_type} id={entry.subject_id} />,
                    },
                    {
                        key: 'actor',
                        header: strings2.columns.actor,
                        width: '130px',
                        cell: (entry) =>
                            entry.actor_user_id === null || entry.actor_user_id === undefined
                                ? strings.common.none
                                : `#${entry.actor_user_id}`,
                    },
                    {
                        key: 'outcome',
                        header: strings2.columns.outcome,
                        width: '170px',
                        cell: (entry) => (
                            <div className="grid gap-0.5">
                                <Badge variant={entry.success ? 'ok' : 'danger'}>
                                    {entry.success ? strings2.success : strings2.failure}
                                </Badge>
                                {entry.error_code !== null && entry.error_code !== undefined && (
                                    <span className="text-xs text-danger">{entry.error_code}</span>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'when',
                        header: strings2.columns.when,
                        width: '170px',
                        cell: (entry) => <span className="text-fg-2">{date(entry.executed_at)}</span>,
                    },
                    {
                        key: 'detail',
                        header: strings.workspace.details,
                        hideHeader: true,
                        width: '70px',
                        cell: (entry) => (
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: strings.workspace.details, p1: entry.id })}
                                onClick={() => setSelected(entry)}
                            >
                                <ArrowUpRight size={16} />
                            </Button>
                        ),
                    },
                ]}
            />
            {data !== undefined && data.total > data.per_page && (
                <Pagination
                    label={strings2.title}
                    page={data.page}
                    perPage={data.per_page}
                    total={data.total}
                    onPageChange={setPage}
                />
            )}
            {selected && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) setSelected(null);
                    }}
                >
                    <DialogContent className="vy-auto-dialog">
                        <DialogTitle>{strings.workspace.details}</DialogTitle>
                        <DialogDescription>
                            {strings.editor.stepLabel(selected.step_index)} · {readable(selected.action_key)}
                        </DialogDescription>
                        <dl className="vy-auto-log-details">
                            {Object.entries(selected).map(([key, value]) => (
                                <div key={key}>
                                    <dt>{readable(key)}</dt>
                                    <dd>
                                        {value === null || value === undefined ? (
                                            '—'
                                        ) : typeof value === 'object' ? (
                                            <details>
                                                <summary>{strings.workspace.details}</summary>
                                                <pre>{JSON.stringify(value, null, 2)}</pre>
                                            </details>
                                        ) : (
                                            String(value)
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}
