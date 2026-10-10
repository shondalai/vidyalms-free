import { translate as uiText } from '@/shared/i18n';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { useState } from 'react';
import { Badge, Button, DataTable, ErrorState, Skeleton } from '@/shared/components';
import {
    newIdempotencyKey,
    useAcceptAiDraft,
    useActivateAiPolicy,
    useAiDrafts,
    useAiPolicies,
    useAiRoutes,
    useAiRun,
    useAiRuns,
    useAiUsage,
    useCreateAiPolicy,
    useRejectAiDraft,
    type AiDraft,
    type AiDraftListParams,
    type AiPolicyDocument,
    type AiRoute,
    type AiRun,
    type AiRunListParams,
} from './api';
import { aiHash } from './routes';
import { aiStrings as strings } from './strings';

/*
 * The five reading panels of the AI screen.
 *
 * Three of them are the free build's whole answer to a site owner who bought
 * nothing: a policy version they drafted and activated themselves, a run log
 * whose rows are refusals rather than successes, and a usage report that says
 * the spend is unmetered rather than reporting zero. None of them is a Pro
 * screen with the buttons removed.
 */

function costOf(unit: string, amount: number | null): string {
    return amount === null ? strings.usage.unknown : `${amount} ${unit}`;
}

function stateBadge(state: string) {
    if (state === 'succeeded' || state === 'accepted' || state === 'active') {
        return <Badge variant="ok">{state}</Badge>;
    }
    if (state === 'failed' || state === 'refused') {
        return <Badge variant="danger">{state}</Badge>;
    }
    if (state === 'cancelled' || state === 'discarded' || state === 'superseded') {
        return <Badge variant="warn">{state}</Badge>;
    }
    return <Badge variant="neutral">{state}</Badge>;
}

/* ── The data-use policy ────────────────────────────────────────────── */

export function AiPolicyPanel() {
    const policies = useAiPolicies();
    const create = useCreateAiPolicy();
    const activate = useActivateAiPolicy();
    const [drafting, setDrafting] = useState(false);
    const [form, setForm] = useState({ title: '', body: '', retention: 400 });
    useNavigationGuard(drafting && (form.title !== '' || form.body !== ''));

    if (policies.isError) {
        return <ErrorState error={policies.error} title={strings.errors.load} onRetry={() => void policies.refetch()} />;
    }

    if (policies.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const columns = [
        {
            key: 'version',
            header: strings.policy.version,
            width: '96px' as const,
            cell: (row: AiPolicyDocument) => <span className="font-mono text-xs">{row.version}</span>,
        },
        { key: 'title', header: strings.policy.form.title, cell: (row: AiPolicyDocument) => row.title },
        {
            key: 'state',
            header: strings.policy.state,
            width: '140px' as const,
            cell: (row: AiPolicyDocument) => stateBadge(row.state),
        },
        {
            key: 'retention',
            header: strings.policy.retention,
            width: '140px' as const,
            cell: (row: AiPolicyDocument) => strings.policy.days(row.retention_days),
        },
        {
            key: 'redaction',
            header: strings.policy.redaction,
            width: '140px' as const,
            cell: (row: AiPolicyDocument) => row.redaction_level,
        },
        {
            key: 'acceptances',
            header: strings.policy.acceptances,
            width: '120px' as const,
            align: 'end' as const,
            cell: (row: AiPolicyDocument) => String(row.acceptance_count ?? 0),
        },
        {
            key: 'actions',
            header: strings.policy.activate,
            hideHeader: true,
            width: '140px' as const,
            align: 'end' as const,
            cell: (row: AiPolicyDocument) =>
                row.state === 'draft' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={activate.isPending}
                        onClick={() => activate.mutate({ version: row.version, idempotencyKey: newIdempotencyKey() })}
                    >
                        {strings.policy.activate}
                    </Button>
                ) : null,
        },
    ];

    return (
        <div className="grid gap-3.5">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="font-display text-xl font-semibold text-fg">{strings.policy.title}</h2>
                    <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.policy.intro}</p>
                    <p className="mt-1 text-xs text-fg-2">{strings.policy.activateHint}</p>
                </div>
                <Button size="sm" onClick={() => setDrafting((open) => !open)}>
                    {strings.policy.newVersion}
                </Button>
            </header>

            {drafting ? (
                <form
                    className="grid gap-2 rounded-lg border border-border bg-surface p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        create.mutate(
                            {
                                body: {
                                    title: form.title,
                                    body: form.body,
                                    retention_days: form.retention,
                                },
                                idempotencyKey: newIdempotencyKey(),
                            },
                            { onSuccess: () => setDrafting(false) },
                        );
                    }}
                >
                    <label className="grid gap-1 text-xs font-semibold text-fg-2">
                        {strings.policy.form.title}
                        <input
                            required
                            type="text"
                            className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                            value={form.title}
                            onChange={(event) => setForm({ ...form, title: event.target.value })}
                        />
                    </label>
                    <label className="grid gap-1 text-xs font-semibold text-fg-2">
                        {strings.policy.form.body}
                        <textarea
                            required
                            rows={5}
                            className="rounded border border-border bg-surface p-2 text-[13px] text-fg"
                            value={form.body}
                            onChange={(event) => setForm({ ...form, body: event.target.value })}
                        />
                    </label>
                    <label className="grid gap-1 text-xs font-semibold text-fg-2">
                        {strings.policy.form.retention}
                        <input
                            type="number"
                            min={180}
                            className="h-8 w-40 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                            value={form.retention}
                            onChange={(event) => setForm({ ...form, retention: Number(event.target.value) })}
                        />
                        <span className="font-normal text-fg-2">{strings.policy.floor}</span>
                    </label>
                    <div className="flex gap-2">
                        <Button size="sm" type="submit" disabled={create.isPending}>
                            {strings.policy.form.save}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setDrafting(false)}>
                            {strings.policy.form.cancel}
                        </Button>
                    </div>
                    {create.isError ? (
                        <p role="alert" className="text-xs font-semibold text-danger-strong">
                            {strings.errors.save}
                        </p>
                    ) : null}
                </form>
            ) : null}

            <DataTable
                caption={strings.policy.title}
                columns={columns}
                rows={policies.data.items}
                rowKey={(row) => row.version}
                empty={<p className="text-sm text-fg-2">{strings.policy.empty}</p>}
            />
        </div>
    );
}

/* ── Routes ─────────────────────────────────────────────────────────── */

export function AiRoutesPanel() {
    const routes = useAiRoutes();

    if (routes.isError) {
        return <ErrorState error={routes.error} title={strings.errors.load} onRetry={() => void routes.refetch()} />;
    }

    if (routes.data === undefined) {
        return <Skeleton className="h-48 w-full" />;
    }

    const columns = [
        { key: 'task', header: strings.routes.task, cell: (row: AiRoute) => row.task_label ?? row.task_key },
        {
            key: 'bridge',
            header: strings.routes.bridge,
            cell: (row: AiRoute) =>
                row.bridge_available ? (
                    (row.bridge_label ?? row.bridge_key)
                ) : (
                    <span className="text-danger-strong">{strings.routes.missingBridge}</span>
                ),
        },
        { key: 'model', header: strings.routes.model, cell: (row: AiRoute) => row.model ?? '' },
        {
            key: 'position',
            header: strings.routes.position,
            width: '96px' as const,
            align: 'end' as const,
            cell: (row: AiRoute) => String(row.position),
        },
        {
            key: 'ceiling',
            header: strings.routes.ceiling,
            width: '160px' as const,
            align: 'end' as const,
            cell: (row: AiRoute) => String(row.max_cost_per_run),
        },
        {
            key: 'enabled',
            header: strings.routes.enabled,
            width: '120px' as const,
            cell: (row: AiRoute) =>
                row.enabled ? <Badge variant="ok">{strings.runs.yes}</Badge> : <Badge variant="neutral">{strings.runs.no}</Badge>,
        },
    ];

    return (
        <div className="grid gap-3.5">
            <header>
                <h2 className="font-display text-xl font-semibold text-fg">{strings.routes.title}</h2>
                <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.routes.intro}</p>
            </header>
            <DataTable
                caption={strings.routes.title}
                columns={columns}
                rows={routes.data.items}
                rowKey={(row) => row.id}
                empty={<p className="text-sm text-fg-2">{strings.routes.empty}</p>}
            />
        </div>
    );
}

/* ── Runs ───────────────────────────────────────────────────────────── */

export function AiRunsPanel({ navigate }: { navigate: (hash: string) => void }) {
    const [filters, setFilters] = useState<AiRunListParams>({ page: 1, per_page: 25 });
    const runs = useAiRuns(filters);

    if (runs.isError) {
        return <ErrorState error={runs.error} title={strings.errors.load} onRetry={() => void runs.refetch()} />;
    }

    const columns = [
        {
            key: 'when',
            header: strings.runs.when,
            width: '180px' as const,
            cell: (row: AiRun) => row.created_at ?? '',
        },
        { key: 'task', header: strings.runs.task, cell: (row: AiRun) => row.task_key },
        {
            key: 'who',
            header: strings.runs.who,
            width: '120px' as const,
            cell: (row: AiRun) => (row.principal_user_id === null ? row.actor_kind : String(row.principal_user_id)),
        },
        {
            key: 'state',
            header: strings.runs.state,
            width: '140px' as const,
            cell: (row: AiRun) => stateBadge(row.state),
        },
        {
            key: 'cost',
            header: strings.runs.cost,
            width: '160px' as const,
            align: 'end' as const,
            cell: (row: AiRun) => costOf(row.cost_unit, row.cost_charged ?? null),
        },
        {
            key: 'attempts',
            header: strings.runs.attempts,
            width: '110px' as const,
            align: 'end' as const,
            cell: (row: AiRun) => String(row.attempts.length),
        },
        {
            key: 'open',
            header: strings.runs.open,
            hideHeader: true,
            width: '110px' as const,
            align: 'end' as const,
            cell: (row: AiRun) => (
                <Button size="sm" variant="ghost" onClick={() => navigate(aiHash.run(row.id))}>
                    {strings.runs.open}
                </Button>
            ),
        },
    ];

    return (
        <div className="grid gap-3.5">
            <header>
                <h2 className="font-display text-xl font-semibold text-fg">{strings.runs.title}</h2>
                <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.runs.intro}</p>
            </header>

            <div className="flex flex-wrap items-end gap-3">
                <label className="grid gap-1 text-xs font-semibold text-fg-2">
                    {strings.runs.filterState}
                    <select
                        className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                        value={filters.state ?? ''}
                        onChange={(event) =>
                            setFilters((current) => ({
                                ...current,
                                page: 1,
                                state:
                                    event.target.value === ''
                                        ? undefined
                                        : (event.target.value as NonNullable<AiRunListParams['state']>),
                            }))
                        }
                    >
                        <option value="">{strings.runs.filterAll}</option>
                        {['reserved', 'running', 'succeeded', 'failed', 'refused', 'previewed', 'cancelled'].map(
                            (state) => (
                                <option key={state} value={state}>
                                    {state}
                                </option>
                            ),
                        )}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold text-fg-2">
                    {strings.runs.filterDryRun}
                    <select
                        className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                        value={filters.dry_run === undefined ? '' : filters.dry_run ? 'yes' : 'no'}
                        onChange={(event) =>
                            setFilters((current) => ({
                                ...current,
                                page: 1,
                                dry_run: event.target.value === '' ? undefined : event.target.value === 'yes',
                            }))
                        }
                    >
                        <option value="">{strings.runs.filterAll}</option>
                        <option value="yes">{strings.runs.yes}</option>
                        <option value="no">{strings.runs.no}</option>
                    </select>
                </label>
            </div>

            <DataTable
                caption={strings.runs.title}
                columns={columns}
                rows={runs.data?.items ?? []}
                rowKey={(row) => row.id}
                loading={runs.data === undefined}
                empty={<p className="text-sm text-fg-2">{strings.runs.empty}</p>}
            />
        </div>
    );
}

export function AiRunDetail({ runId, navigate }: { runId: number; navigate: (hash: string) => void }) {
    const run = useAiRun(runId);

    if (run.isError) {
        return <ErrorState error={run.error} title={strings.errors.load} onRetry={() => void run.refetch()} />;
    }

    if (run.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const manifest = run.data.redaction_manifest ?? [];

    return (
        <div className="grid gap-4">
            <header className="grid gap-1">
                <Button
                    size="sm"
                    variant="ghost"
                    className="justify-self-start"
                    onClick={() => navigate(aiHash.runs())}
                >
                    {strings.runs.back}
                </Button>
                <h2 className="font-display text-xl font-semibold text-fg">{strings.runs.detailTitle(run.data.id)}</h2>
                <p className="text-sm text-fg-2">
                    {run.data.task_key} {stateBadge(run.data.state)}
                </p>
                {run.data.refusal != null ? (
                    <p className="text-sm font-semibold text-danger-strong">{run.data.refusal.kind}</p>
                ) : null}
                {run.data.cost_charged === null ? (
                    <p className="text-xs text-fg-2">{strings.runs.unmetered}</p>
                ) : null}
            </header>

            <section className="grid gap-2">
                <h3 className="text-sm font-semibold text-fg">{strings.runs.attemptsTitle}</h3>
                <DataTable
                    caption={strings.runs.attemptsTitle}
                    columns={[
                        {
                            key: 'no',
                            header: strings.runs.attemptNo,
                            width: '110px' as const,
                            cell: (row: (typeof run.data.attempts)[number]) => String(row.attempt_no),
                        },
                        {
                            key: 'bridge',
                            header: strings.runs.attemptBridge,
                            cell: (row: (typeof run.data.attempts)[number]) => row.bridge_key,
                        },
                        {
                            key: 'outcome',
                            header: strings.runs.attemptOutcome,
                            width: '150px' as const,
                            cell: (row: (typeof run.data.attempts)[number]) =>
                                row.success ? (
                                    <Badge variant="ok">{strings.runs.success}</Badge>
                                ) : (
                                    <Badge variant="danger">{strings.runs.failed}</Badge>
                                ),
                        },
                        {
                            key: 'refusal',
                            header: strings.runs.attemptRefusal,
                            cell: (row: (typeof run.data.attempts)[number]) => row.refusal_kind ?? '',
                        },
                        {
                            key: 'latency',
                            header: strings.runs.attemptLatency,
                            width: '120px' as const,
                            align: 'end' as const,
                            cell: (row: (typeof run.data.attempts)[number]) =>
                                row.latency_ms === null ? '' : `${row.latency_ms} ms`,
                        },
                    ]}
                    rows={run.data.attempts}
                    rowKey={(row) => row.attempt_no}
                    empty={<p className="text-sm text-fg-2">{strings.runs.empty}</p>}
                />
            </section>

            <section className="grid gap-2">
                <h3 className="text-sm font-semibold text-fg">{strings.runs.manifestTitle}</h3>
                <p className="max-w-[70ch] text-xs text-fg-2">{strings.runs.manifestIntro}</p>
                {manifest.length === 0 ? (
                    <p className="text-sm text-fg-2">{strings.runs.manifestEmpty}</p>
                ) : (
                    <ul className="grid gap-0.5 text-sm text-fg-2">
                        {manifest.map((label) => (
                            <li key={label} className="font-mono text-xs text-fg">
                                {label}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

/* ── Drafts ─────────────────────────────────────────────────────────── */

export function AiDraftsPanel() {
    const [filters] = useState<AiDraftListParams>({ page: 1, per_page: 25 });
    const drafts = useAiDrafts(filters);
    const accept = useAcceptAiDraft();
    const reject = useRejectAiDraft();
    const [acknowledged, setAcknowledged] = useState<Record<number, boolean>>({});
    const [reasons, setReasons] = useState<Record<number, string>>({});

    if (drafts.isError) {
        return <ErrorState error={drafts.error} title={strings.errors.load} onRetry={() => void drafts.refetch()} />;
    }

    if (drafts.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    if (drafts.data.items.length === 0) {
        return (
            <div className="grid gap-3.5">
                <header>
                    <h2 className="font-display text-xl font-semibold text-fg">{strings.drafts.title}</h2>
                    <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.drafts.intro}</p>
                </header>
                <p className="text-sm text-fg-2">{strings.drafts.empty}</p>
            </div>
        );
    }

    return (
        <div className="grid gap-3.5">
            <header>
                <h2 className="font-display text-xl font-semibold text-fg">{strings.drafts.title}</h2>
                <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.drafts.intro}</p>
            </header>

            <ul className="grid gap-3">
                {drafts.data.items.map((draft: AiDraft) => {
                    const blocked = draft.findings_blocking > 0 && acknowledged[draft.id] !== true;

                    return (
                        <li key={draft.id} className="grid gap-2 rounded-lg border border-border bg-surface p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-semibold text-fg">{draft.draft_kind}</span>
                                {stateBadge(draft.state)}
                                <Badge variant={draft.findings_blocking > 0 ? 'danger' : 'neutral'}>
                                    {uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.drafts.blocking, p1: draft.findings_blocking })}
                                </Badge>
                                <Badge variant="neutral">
                                    {uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.drafts.advisory, p1: draft.findings_advisory })}
                                </Badge>
                            </div>

                            {draft.findings.length > 0 ? (
                                <ul className="grid gap-1 text-xs text-fg-2">
                                    {draft.findings.map((finding) => (
                                        <li key={finding.id}>
                                            <span className="font-semibold">{finding.severity}</span> {finding.message}
                                        </li>
                                    ))}
                                </ul>
                            ) : null}

                            {draft.acceptor_available === false ? (
                                <p className="text-xs font-semibold text-danger-strong">
                                    {strings.drafts.noAcceptor(draft.draft_kind)}
                                </p>
                            ) : null}

                            {draft.findings_blocking > 0 ? (
                                <label className="flex items-center gap-2 text-xs text-fg-2">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4"
                                        checked={acknowledged[draft.id] === true}
                                        onChange={(event) =>
                                            setAcknowledged((current) => ({
                                                ...current,
                                                [draft.id]: event.target.checked,
                                            }))
                                        }
                                    />
                                    {strings.drafts.acknowledge}
                                </label>
                            ) : null}

                            <div className="flex flex-wrap items-end gap-2">
                                <Button
                                    size="sm"
                                    disabled={blocked || draft.acceptor_available === false || accept.isPending}
                                    onClick={() =>
                                        accept.mutate({
                                            id: draft.id,
                                            acknowledgeBlocking: acknowledged[draft.id] === true,
                                            idempotencyKey: newIdempotencyKey(),
                                        })
                                    }
                                >
                                    {strings.drafts.accept}
                                </Button>
                                <label className="grid gap-1 text-xs font-semibold text-fg-2">
                                    {strings.drafts.reasonLabel}
                                    <input
                                        type="text"
                                        className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                        placeholder={strings.drafts.reasonPlaceholder}
                                        value={reasons[draft.id] ?? ''}
                                        onChange={(event) =>
                                            setReasons((current) => ({ ...current, [draft.id]: event.target.value }))
                                        }
                                    />
                                </label>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={(reasons[draft.id] ?? '') === '' || reject.isPending}
                                    onClick={() =>
                                        reject.mutate({
                                            id: draft.id,
                                            reasonCode: reasons[draft.id] ?? '',
                                            idempotencyKey: newIdempotencyKey(),
                                        })
                                    }
                                >
                                    {strings.drafts.reject}
                                </Button>
                            </div>

                            {blocked ? <p className="text-xs text-fg-2">{strings.drafts.acceptBlocked}</p> : null}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/* ── Usage and oversight ────────────────────────────────────────────── */

export function AiUsagePanel() {
    const usage = useAiUsage();

    if (usage.isError) {
        return <ErrorState error={usage.error} title={strings.errors.load} onRetry={() => void usage.refetch()} />;
    }

    if (usage.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const report = usage.data;
    const groupColumns = [
        { key: 'label', header: strings.usage.byTask, cell: (row: { label: string }) => row.label },
        { key: 'unit', header: strings.usage.unit, width: '140px' as const, cell: (row: { cost_unit: string }) => row.cost_unit },
        {
            key: 'runs',
            header: strings.usage.runs,
            width: '100px' as const,
            align: 'end' as const,
            cell: (row: { runs: number }) => String(row.runs),
        },
        {
            key: 'refused',
            header: strings.usage.refused,
            width: '110px' as const,
            align: 'end' as const,
            cell: (row: { refusals: number }) => String(row.refusals),
        },
        {
            key: 'spend',
            header: strings.usage.spend,
            width: '150px' as const,
            align: 'end' as const,
            cell: (row: { cost_unit: string; cost_charged: number | null }) =>
                costOf(row.cost_unit, row.cost_charged ?? null),
        },
    ];

    return (
        <div className="grid gap-5">
            <header>
                <h2 className="font-display text-xl font-semibold text-fg">{strings.usage.title}</h2>
                <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.usage.intro}</p>
                {report.unmetered ? <p className="mt-1 text-xs text-fg-2">{strings.usage.unmetered}</p> : null}
            </header>

            <section className="grid gap-2">
                <h3 className="text-sm font-semibold text-fg">{strings.usage.totals}</h3>
                <DataTable
                    caption={strings.usage.totals}
                    columns={groupColumns}
                    rows={report.totals}
                    rowKey={(row) => `${row.key}:${row.cost_unit}`}
                    empty={<p className="text-sm text-fg-2">{strings.usage.empty}</p>}
                />
            </section>

            <section className="grid gap-2">
                <h3 className="text-sm font-semibold text-fg">{strings.usage.byTask}</h3>
                <DataTable
                    caption={strings.usage.byTask}
                    columns={groupColumns}
                    rows={report.by_task}
                    rowKey={(row) => `${row.key}:${row.cost_unit}`}
                    empty={<p className="text-sm text-fg-2">{strings.usage.empty}</p>}
                />
            </section>

            <section className="grid gap-2">
                <h3 className="text-sm font-semibold text-fg">{strings.usage.oversight}</h3>
                <p className="max-w-[70ch] text-xs text-fg-2">{strings.usage.oversightHint}</p>
                <DataTable
                    caption={strings.usage.oversight}
                    columns={[
                        { key: 'task', header: strings.usage.byTask, cell: (row: { label: string }) => row.label },
                        {
                            key: 'accepted',
                            header: strings.usage.acceptedCount,
                            width: '130px' as const,
                            align: 'end' as const,
                            cell: (row: { accepted: number }) => String(row.accepted),
                        },
                        {
                            key: 'unedited',
                            header: strings.usage.uneditedCount,
                            width: '170px' as const,
                            align: 'end' as const,
                            cell: (row: { accepted_unedited: number }) => String(row.accepted_unedited),
                        },
                        {
                            key: 'rate',
                            header: strings.usage.rate,
                            width: '150px' as const,
                            align: 'end' as const,
                            cell: (row: { accept_unedited_rate: string }) => row.accept_unedited_rate,
                        },
                        {
                            key: 'median',
                            header: strings.usage.median,
                            width: '190px' as const,
                            align: 'end' as const,
                            cell: (row: { median_edit_distance?: string | null }) => row.median_edit_distance ?? '',
                        },
                    ]}
                    rows={report.oversight}
                    rowKey={(row) => row.task_key}
                    empty={<p className="text-sm text-fg-2">{strings.usage.oversightEmpty}</p>}
                />
            </section>

            <section className="grid gap-2">
                <h3 className="text-sm font-semibold text-fg">{strings.usage.refusals}</h3>
                <ul className="grid gap-1 text-sm text-fg-2">
                    {report.refusals.length === 0 ? (
                        <li>{strings.usage.empty}</li>
                    ) : (
                        report.refusals.map((refusal) => (
                            <li key={refusal.kind}>
                                <span className="font-semibold text-fg">{refusal.kind}</span> {refusal.count}
                            </li>
                        ))
                    )}
                </ul>
            </section>
        </div>
    );
}
