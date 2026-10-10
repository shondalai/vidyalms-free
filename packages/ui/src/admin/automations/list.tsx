import { useEffect, useState } from 'react';
import {
    Activity,
    ArrowUpRight,
    FlaskConical,
    LayoutGrid,
    List,
    Pause,
    Plus,
    RefreshCw,
    ShieldCheck,
    Workflow,
    Zap,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { ProblemError } from '@vidyalms/api-sdk';
import { Badge, Button, DataTable, EmptyState, ErrorState, Pagination, Skeleton } from '@/shared/components';
import {
    useAutomationCatalogue,
    useAutomationRules,
    automationKeys,
    type AutomationRule,
    type AutomationRuleListParams,
} from './api';
import { automationHash } from './routes';
import { automationStrings as strings } from './strings';
import { PageHeader } from '../components/page-header';
import { useDebounced } from '../hooks';
import { providerLabel, RuleFlow, StarterLibrary } from './workspace';

const PER_PAGE = 25;

function statusOf(error: unknown): number {
    return error instanceof ProblemError ? error.status : 0;
}

function stateBadge(rule: AutomationRule) {
    if (rule.state === 'degraded') {
        return <Badge variant="danger">{strings.state.degraded}</Badge>;
    }
    if (rule.state === 'enabled') {
        return <Badge variant="ok">{strings.state.enabled}</Badge>;
    }
    return <Badge variant="neutral">{strings.state.disabled}</Badge>;
}

export function AutomationRuleList({
    navigate,
    hash = '/',
    activity = false,
    templates = false,
}: {
    navigate: (hash: string) => void;
    hash?: string;
    activity?: boolean;
    templates?: boolean;
}) {
    const readFilters = (source: string): AutomationRuleListParams => {
        const params = new URLSearchParams(source.split('?')[1]);
        const state = params.get('state');
        const page = Number(params.get('page'));
        return {
            page: Number.isSafeInteger(page) && page > 0 ? page : 1,
            per_page: PER_PAGE,
            search: params.get('search') || undefined,
            trigger_key: params.get('trigger_key') || undefined,
            action_key: params.get('action_key') || undefined,
            state: state === 'enabled' || state === 'disabled' || state === 'degraded' ? state : undefined,
        };
    };
    const [filters, setFilters] = useState<AutomationRuleListParams>(() => readFilters(hash));
    useEffect(() => setFilters(readFilters(hash)), [hash]);
    const debounced = useDebounced(filters.search ?? '');
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const catalogue = useAutomationCatalogue();
    const rules = useAutomationRules({ ...filters, search: debounced || undefined });
    const total = useAutomationRules({ page: 1, per_page: 1 });
    const enabled = useAutomationRules({ state: 'enabled', page: 1, per_page: 1 });
    const paused = useAutomationRules({ state: 'disabled', page: 1, per_page: 1 });
    const attention = useAutomationRules({ state: 'degraded', page: 1, per_page: 1 });
    const cache = useQueryClient();
    const filtered =
        filters.trigger_key !== undefined ||
        filters.state !== undefined ||
        filters.action_key !== undefined ||
        (filters.search ?? '') !== '';

    const set = (patch: Partial<AutomationRuleListParams>, resetPage = true) => {
        const next = { ...filters, ...patch, ...(resetPage ? { page: 1 } : {}) };
        setFilters(next);
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(next))
            if (value !== undefined && key !== 'per_page') params.set(key, String(value));
        navigate(`${activity ? '/activity' : '/'}?${params}`);
    };

    if (rules.isError) {
        const status = statusOf(rules.error);
        if (status === 403) {
            return (
                <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                    <h2 className="font-display text-lg font-semibold text-fg">{strings.list.forbiddenTitle}</h2>
                    <p className="mt-1.5 max-w-[70ch] text-sm text-fg-2">{strings.list.forbiddenBody}</p>
                </section>
            );
        }
        return <ErrorState error={rules.error} title={strings.list.errorTitle} onRetry={() => void rules.refetch()} />;
    }

    const page = rules.data;
    const triggers = catalogue.data?.triggers ?? [];
    const actions = catalogue.data?.actions ?? [];

    return (
        <div className="vy-auto-list">
            <PageHeader
                title={
                    <>
                        {strings.screen}
                        <span className="vy-auto-dot">.</span>
                    </>
                }
                description={strings.workspace.intro}
                actions={
                    <Button
                        variant="outline"
                        onClick={() => void cache.invalidateQueries({ queryKey: automationKeys.root })}
                    >
                        <RefreshCw size={15} />
                        {strings.workspace.refresh}
                    </Button>
                }
            />
            <dl className="vy-auto-stats">
                {[
                    { label: strings.workspace.total, query: total, Icon: Workflow },
                    { label: strings.workspace.enabled, query: enabled, Icon: Zap },
                    { label: strings.workspace.paused, query: paused, Icon: Pause },
                    { label: strings.workspace.attention, query: attention, Icon: Activity },
                ].map(({ label, query, Icon }) => (
                    <div key={label}>
                        <Icon size={21} />
                        <div>
                            <dd>{query.data?.total.toLocaleString() ?? '—'}</dd>
                            <dt>{label}</dt>
                        </div>
                    </div>
                ))}
            </dl>
            <div className="vy-auto-toolbar">
                <nav className="vy-auto-tabs" aria-label={strings.screen}>
                    {[
                        { path: '/', text: strings.workspace.rules, selected: !activity && !templates },
                        { path: '/activity', text: strings.workspace.activity, selected: activity },
                        { path: '/templates', text: strings.workspace.templates, selected: templates },
                    ].map((tab) => (
                        <button
                            key={tab.path}
                            aria-current={tab.selected ? 'page' : undefined}
                            onClick={() => navigate(tab.path)}
                        >
                            {tab.text}
                        </button>
                    ))}
                </nav>
                <Button onClick={() => navigate(automationHash.newRule())}>
                    <Plus size={16} />
                    {strings.workspace.newRule}
                </Button>
            </div>
            {catalogue.isError && (
                <ErrorState
                    title={strings.workspace.catalogueError}
                    error={catalogue.error}
                    onRetry={() => void catalogue.refetch()}
                />
            )}
            {templates ? (
                <StarterLibrary catalogue={catalogue.data} navigate={navigate} />
            ) : (
                <>
                    <div className="vy-auto-notice">
                        <ShieldCheck size={21} />
                        <div>
                            <strong>{activity ? strings.workspace.chooseActivity : strings.workspace.notice}</strong>
                            <p>{activity ? strings.workspace.activityHint : strings.workspace.noticeHint}</p>
                        </div>
                    </div>
                    <div className="vy-auto-filters">
                        <label className="grid gap-1 text-xs font-semibold text-fg-2">
                            {strings.list.searchLabel}
                            <input
                                type="search"
                                className="h-8 min-w-52 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                placeholder={strings.list.searchPlaceholder}
                                value={filters.search ?? ''}
                                onChange={(event) =>
                                    set({ search: event.target.value === '' ? undefined : event.target.value })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2">
                            {strings.list.triggerLabel}
                            <select
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={filters.trigger_key ?? ''}
                                onChange={(event) =>
                                    set({ trigger_key: event.target.value === '' ? undefined : event.target.value })
                                }
                            >
                                <option value="">{strings.list.anyTrigger}</option>
                                {triggers.map((trigger) => (
                                    <option key={trigger.key} value={trigger.key}>
                                        {providerLabel(trigger)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2">
                            {strings.list.stateLabel}
                            <select
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={filters.state ?? ''}
                                onChange={(event) =>
                                    set({
                                        state:
                                            event.target.value === ''
                                                ? undefined
                                                : (event.target.value as AutomationRuleListParams['state']),
                                    })
                                }
                            >
                                <option value="">{strings.list.anyState}</option>
                                <option value="disabled">{strings.state.disabled}</option>
                                <option value="enabled">{strings.state.enabled}</option>
                                <option value="degraded">{strings.state.degraded}</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2">
                            {strings.list.actionLabel}
                            <select
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={filters.action_key ?? ''}
                                onChange={(event) =>
                                    set({ action_key: event.target.value === '' ? undefined : event.target.value })
                                }
                            >
                                <option value="">{strings.list.anyAction}</option>
                                {actions.map((action) => (
                                    <option key={action.key} value={action.key}>
                                        {providerLabel(action)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="vy-auto-toolbar">
                        <p className="vy-auto-muted">
                            {page ? strings.workspace.matching(page.total) : strings.list.loading}
                        </p>
                        <div className="vy-auto-actions">
                            {filtered && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        set({
                                            search: undefined,
                                            state: undefined,
                                            trigger_key: undefined,
                                            action_key: undefined,
                                        })
                                    }
                                >
                                    {strings.workspace.reset}
                                </Button>
                            )}
                            <button
                                className="vy-auto-view"
                                aria-label={strings.workspace.cards}
                                aria-pressed={view === 'cards'}
                                onClick={() => setView('cards')}
                            >
                                <LayoutGrid size={17} />
                            </button>
                            <button
                                className="vy-auto-view"
                                aria-label={strings.workspace.table}
                                aria-pressed={view === 'table'}
                                onClick={() => setView('table')}
                            >
                                <List size={17} />
                            </button>
                        </div>
                    </div>
                    {view === 'cards' ? (
                        <div
                            className="vy-auto-grid"
                            role="region"
                            aria-label={strings.list.title}
                            aria-busy={rules.isFetching}
                        >
                            {rules.isPending
                                ? [0, 1, 2].map((i) => <Skeleton key={i} className="h-80 w-full" />)
                                : page?.items.map((rule) => (
                                      <article key={rule.id} className="vy-auto-card" aria-label={rule.title}>
                                          <div className="vy-auto-card-heading">
                                              <span className="vy-auto-icon peach">
                                                  <Workflow size={21} />
                                              </span>
                                              {stateBadge(rule)}
                                          </div>
                                          <button
                                              className="vy-auto-card-title"
                                              onClick={() =>
                                                  navigate(
                                                      activity
                                                          ? automationHash.runs(rule.id)
                                                          : automationHash.rule(rule.id),
                                                  )
                                              }
                                              disabled={rules.isPlaceholderData}
                                          >
                                              {rule.title}
                                          </button>
                                          <p className="vy-auto-muted">
                                              {rule.armed_by
                                                  ? strings.list.armed(String(rule.armed_by))
                                                  : strings.list.notArmed}{' '}
                                              · {strings.list.columns.order} {rule.run_order}
                                          </p>
                                          {rule.missing_keys.length > 0 && (
                                              <p className="vy-auto-error">
                                                  {strings.list.missingKeys(rule.missing_keys.join(', '))}
                                              </p>
                                          )}
                                          {rule.last_test_result === 'failed' && (
                                              <p className="vy-auto-error">{strings.list.testFailing}</p>
                                          )}
                                          <RuleFlow rule={rule} catalogue={catalogue.data} />
                                          <dl className="vy-auto-counters">
                                              {(['queued', 'executed', 'suppressed', 'failed'] as const).map((key) => (
                                                  <div key={key}>
                                                      <dd>{rule.counters[key].toLocaleString()}</dd>
                                                      <dt>{strings.workspace[key]}</dt>
                                                  </div>
                                              ))}
                                          </dl>
                                          <div className="vy-auto-card-footer">
                                              <Button
                                                  size="sm"
                                                  variant="ghost"
                                                  disabled={rules.isPlaceholderData}
                                                  onClick={() => navigate(automationHash.runs(rule.id))}
                                              >
                                                  <Activity size={14} />
                                                  {strings.list.openRuns}
                                              </Button>
                                              <Button
                                                  size="sm"
                                                  variant="outline"
                                                  disabled={rules.isPlaceholderData}
                                                  onClick={() =>
                                                      navigate(
                                                          activity
                                                              ? automationHash.runs(rule.id)
                                                              : `${automationHash.rule(rule.id)}?panel=rehearse`,
                                                      )
                                                  }
                                              >
                                                  {activity ? <ArrowUpRight size={14} /> : <FlaskConical size={14} />}
                                                  {activity ? strings.workspace.history : strings.workspace.rehearse}
                                              </Button>
                                          </div>
                                      </article>
                                  ))}
                        </div>
                    ) : (
                        <DataTable<AutomationRule>
                            caption={strings.list.title}
                            loading={rules.isPending}
                            rows={page?.items ?? []}
                            rowKey={(rule) => rule.id}
                            empty={
                                <EmptyState
                                    title={filtered ? strings.list.emptyFiltered : strings.list.empty}
                                    description={filtered ? strings.list.emptyFilteredBody : strings.list.emptyBody}
                                    action={
                                        filtered ? undefined : (
                                            <Button size="sm" onClick={() => navigate(automationHash.newRule())}>
                                                {strings.list.newRule}
                                            </Button>
                                        )
                                    }
                                />
                            }
                            columns={[
                                {
                                    key: 'title',
                                    header: strings.list.columns.title,
                                    minWidth: 240,
                                    cell: (rule) => (
                                        <div className="grid gap-0.5">
                                            <button
                                                type="button"
                                                className="cursor-pointer text-left font-semibold text-accent underline-offset-2 hover:underline"
                                                onClick={() => navigate(automationHash.rule(rule.id))}
                                            >
                                                {rule.title}
                                            </button>
                                            <span className="text-xs text-fg-2">{rule.rule_key}</span>
                                            {rule.missing_keys.length > 0 && (
                                                <span className="text-xs text-danger">
                                                    {strings.list.missingKeys(rule.missing_keys.join(', '))}
                                                </span>
                                            )}
                                            {rule.last_test_result === 'failed' && (
                                                <span className="text-xs text-danger">{strings.list.testFailing}</span>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'trigger',
                                    header: strings.list.columns.trigger,
                                    minWidth: 180,
                                    cell: (rule) => (
                                        <span className="text-fg-2">
                                            {providerLabel(
                                                triggers.find((trigger) => trigger.key === rule.trigger_key),
                                                rule.trigger_key,
                                            )}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'state',
                                    header: strings.list.columns.state,
                                    width: '150px',
                                    cell: (rule) => (
                                        <div className="grid gap-1">
                                            {stateBadge(rule)}
                                            <span className="text-xs text-fg-2">
                                                {rule.armed_by === null || rule.armed_by === undefined
                                                    ? strings.list.notArmed
                                                    : strings.list.armed(String(rule.armed_by))}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'order',
                                    header: strings.list.columns.order,
                                    width: '90px',
                                    align: 'end',
                                    cell: (rule) => rule.run_order,
                                },
                                {
                                    key: 'steps',
                                    header: strings.list.columns.actions,
                                    width: '80px',
                                    align: 'end',
                                    cell: (rule) => rule.actions.length,
                                },
                                {
                                    key: 'counters',
                                    header: strings.list.columns.counters,
                                    width: '190px',
                                    cell: (rule) => (
                                        <span className="tabular-nums text-fg-2">
                                            {rule.counters.queued} / {rule.counters.executed} /{' '}
                                            {rule.counters.suppressed} / {rule.counters.failed}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'go',
                                    header: strings.list.openRuns,
                                    hideHeader: true,
                                    width: '160px',
                                    align: 'end',
                                    cell: (rule) => (
                                        <div className="flex justify-end gap-1.5">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => navigate(automationHash.rule(rule.id))}
                                            >
                                                {strings.list.openRule}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => navigate(automationHash.runs(rule.id))}
                                            >
                                                {strings.list.openRuns}
                                            </Button>
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    )}
                    {view === 'cards' && page?.items.length === 0 && (
                        <EmptyState
                            title={filtered ? strings.list.emptyFiltered : strings.list.empty}
                            description={filtered ? strings.list.emptyFilteredBody : strings.list.emptyBody}
                        />
                    )}
                    {!filtered && page?.total === 0 && !activity && (
                        <StarterLibrary catalogue={catalogue.data} navigate={navigate} />
                    )}

                    {page !== undefined && page.total > (page.per_page ?? PER_PAGE) && (
                        <Pagination
                            label={strings.list.title}
                            page={page.page}
                            perPage={page.per_page}
                            total={page.total}
                            onPageChange={(next) => set({ page: next }, false)}
                        />
                    )}
                </>
            )}
        </div>
    );
}
