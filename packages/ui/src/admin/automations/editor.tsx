import { useEffect, useMemo, useRef, useState } from 'react';
import { Activity, ArrowLeft, FlaskConical, Settings2, Workflow } from 'lucide-react';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { PageHeader } from '../components/page-header';
import { completeConditions, ConditionEditor } from './conditions';
import { bodyOf, draftFrom, newAction, type Draft, type ActionDraft } from './draft';
import { providerLabel, readable, RuleFlow } from './workspace';
import { ProblemError } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Skeleton,
} from '@/shared/components';
import {
    newIdempotencyKey,
    useArchiveAutomationRule,
    useArmAutomationRule,
    useAutomationCatalogue,
    useAutomationRule,
    useCreateAutomationRule,
    useDisarmAutomationRule,
    useUpdateAutomationRule,
    type AutomationActionDescriptor,
    type AutomationCatalogue,
} from './api';
import { ParamsForm } from './params-form';
import { AutomationPreviewPanel } from './preview';
import { AutomationTemplatePicker } from './template-picker';
import { automationHash } from './routes';
import { placeholderValues, readSentence } from './sentences';
import { automationStrings as strings } from './strings';

function descriptorOf(catalogue: AutomationCatalogue | undefined, key: string): AutomationActionDescriptor | undefined {
    return catalogue?.actions.find((action) => action.key === key);
}

function declares(descriptor: AutomationActionDescriptor | undefined, capability: string): boolean {
    return descriptor?.capabilities.includes(capability as never) ?? false;
}

export function AutomationRuleEditor({
    ruleId,
    navigate,
    panel = 'workflow',
    starter,
}: {
    ruleId: number | null;
    navigate: (hash: string) => void;
    panel?: string;
    starter?: string;
}) {
    const catalogue = useAutomationCatalogue();
    const rule = useAutomationRule(ruleId);
    const create = useCreateAutomationRule();
    const update = useUpdateAutomationRule();
    const arm = useArmAutomationRule();
    const disarm = useDisarmAutomationRule();
    const archive = useArchiveAutomationRule();
    const [draft, setDraft] = useState<Draft | null>(null);
    const [armOpen, setArmOpen] = useState(false);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [saved, setSaved] = useState(false);
    const [activePanel, setActivePanel] = useState(panel);
    useEffect(() => setActivePanel(panel), [panel]);
    const [leave, setLeave] = useState<string | null>(null);
    const [validation, setValidation] = useState(false);
    const baseVersion = useRef<number | null>(null);
    const replay = useRef({ fingerprint: '', key: '' });
    const form = useRef<HTMLFieldSetElement>(null);
    useNavigationGuard(draft !== null);
    const go = (destination: string) => (draft !== null ? setLeave(destination) : navigate(destination));

    const loaded = ruleId === null ? catalogue.isSuccess : catalogue.isSuccess && rule.isSuccess;
    const current = useMemo(
        () => draft ?? (loaded ? draftFrom(rule.data, catalogue.data, starter) : null),
        [draft, loaded, rule.data, catalogue.data, starter],
    );

    if (catalogue.isError) {
        return (
            <ErrorState
                error={catalogue.error}
                title={strings.editor.catalogueErrorTitle}
                onRetry={() => void catalogue.refetch()}
            />
        );
    }
    if (rule.isError) {
        const status = rule.error instanceof ProblemError ? rule.error.status : 0;
        if (status === 404) {
            return (
                <section role="alert" className="grid gap-2 rounded-lg border border-border bg-surface p-5">
                    <h2 className="font-display text-lg font-semibold text-fg">{strings.editor.notFoundTitle}</h2>
                    <p className="text-sm text-fg-2">{strings.editor.notFoundBody}</p>
                    <Button
                        className="justify-self-start"
                        variant="outline"
                        size="sm"
                        onClick={() => navigate(automationHash.list())}
                    >
                        {strings.editor.back}
                    </Button>
                </section>
            );
        }
        return <ErrorState error={rule.error} onRetry={() => void rule.refetch()} />;
    }
    if (current === null) {
        return (
            <div className="grid gap-3" aria-busy="true" aria-label={strings.editor.loading}>
                <Skeleton className="h-9 w-64" />
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-40 w-full" />
            </div>
        );
    }

    const book = catalogue.data as AutomationCatalogue;
    const stored = rule.data;
    const edit = (patch: Partial<Draft>) => {
        setSaved(false);
        if (draft === null) baseVersion.current = rule.data?.version ?? null;
        setDraft({ ...current, ...patch });
    };
    const editAction = (index: number, patch: Partial<ActionDraft>) =>
        edit({ actions: current.actions.map((action, at) => (at === index ? { ...action, ...patch } : action)) });
    const move = (index: number, by: number) => {
        const next = [...current.actions];
        const target = index + by;
        if (target < 0 || target >= next.length) return;
        const moved = next[index] as ActionDraft;
        next[index] = next[target] as ActionDraft;
        next[target] = moved;
        edit({ actions: next });
    };

    const emitsEvent = current.actions.some((action) => action.actionKey === 'emit_event');
    const cooldownProblem = emitsEvent && current.cooldownSeconds === 0;
    const conditionProblem =
        !completeConditions(current.conditions) || current.actions.some((action) => !completeConditions(action.guard));
    const recoveryProblem = current.actions.some(
        (action) =>
            book.failure_policies.find((policy) => policy.key === action.failurePolicyKey)?.cancels_retries === true &&
            (book.retry_policies.find((policy) => policy.key === action.retryPolicyKey)?.max_attempts ?? 1) > 1,
    );
    const trigger = book.triggers.find((candidate) => candidate.key === current.triggerKey);
    const saveError = create.error ?? update.error;
    const saveStatus = saveError instanceof ProblemError ? saveError.status : 0;
    const busy = create.isPending || update.isPending || arm.isPending || disarm.isPending || archive.isPending;

    const submit = () => {
        const fields = Array.from(
            form.current?.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(
                'input, select, textarea',
            ) ?? [],
        );
        const invalid = fields.find((field) => !field.checkValidity());
        if (invalid || cooldownProblem || conditionProblem || recoveryProblem) {
            setValidation(true);
            setActivePanel(invalid?.closest('[data-panel="settings"]') ? 'settings' : 'workflow');
            window.setTimeout(() => {
                let parent = invalid?.parentElement;
                while (parent && parent !== form.current) {
                    if (parent instanceof HTMLDetailsElement) parent.open = true;
                    parent = parent.parentElement;
                }
                invalid?.reportValidity();
            }, 0);
            return;
        }
        setValidation(false);
        const body = bodyOf(current, draft === null ? (stored?.version ?? null) : baseVersion.current);
        const fingerprint = JSON.stringify(body);
        if (replay.current.fingerprint !== fingerprint) replay.current = { fingerprint, key: newIdempotencyKey() };
        const key = replay.current.key;
        if (stored === undefined) {
            create.mutate(
                { body, idempotencyKey: key },
                {
                    onSuccess: (created) => {
                        setDraft(null);
                        replay.current = { fingerprint: '', key: '' };
                        setSaved(true);
                        navigate(automationHash.rule(created.id));
                    },
                },
            );
            return;
        }
        update.mutate(
            { id: stored.id, body, idempotencyKey: key },
            {
                onSuccess: () => {
                    setDraft(null);
                    setSaved(true);
                },
            },
        );
    };

    return (
        <fieldset className="vy-auto-editor" ref={form} disabled={busy} aria-busy={busy}>
            <Button variant="ghost" className="vy-auto-back" onClick={() => go(automationHash.list())}>
                <ArrowLeft size={15} />
                {strings.editor.back}
            </Button>
            <PageHeader
                title={stored?.title || strings.editor.newTitle}
                description={strings.workspace.workflowHint}
                actions={
                    stored && (
                        <div className="vy-auto-actions">
                            <Badge
                                variant={
                                    stored.state === 'enabled'
                                        ? 'ok'
                                        : stored.state === 'degraded'
                                          ? 'danger'
                                          : 'neutral'
                                }
                            >
                                {strings.state[stored.state]}
                            </Badge>
                            <span className="vy-auto-muted">
                                {strings.workspace.version} {stored.version}
                            </span>
                            {stored.armed_by ? (
                                <Button
                                    variant="outline"
                                    disabled={busy || draft !== null}
                                    onClick={() =>
                                        disarm.mutate({
                                            id: stored.id,
                                            expectedVersion: stored.version,
                                            idempotencyKey: newIdempotencyKey(),
                                        })
                                    }
                                >
                                    {strings.editor.disarm}
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    disabled={busy || draft !== null || stored.state === 'degraded'}
                                    onClick={() => setArmOpen(true)}
                                >
                                    {strings.editor.arm}
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                disabled={busy || draft !== null}
                                onClick={() => setArchiveOpen(true)}
                            >
                                {strings.editor.archive}
                            </Button>
                        </div>
                    )
                }
            />
            <nav className="vy-auto-tabs" aria-label={strings.workspace.edit}>
                {[
                    { key: 'workflow', label: strings.workspace.workflow, Icon: Workflow },
                    { key: 'settings', label: strings.workspace.settings, Icon: Settings2 },
                    { key: 'rehearse', label: strings.workspace.rehearsal, Icon: FlaskConical },
                ].map(({ key, label, Icon }) => (
                    <button
                        key={key}
                        aria-current={activePanel === key ? 'page' : undefined}
                        onClick={() => setActivePanel(key)}
                    >
                        <Icon size={15} />
                        {label}
                    </button>
                ))}
                {stored && (
                    <button onClick={() => go(automationHash.runs(stored.id))}>
                        <Activity size={15} />
                        {strings.editor.openRuns}
                    </button>
                )}
            </nav>
            {saveError !== null && saveError !== undefined && (
                <ErrorState
                    error={saveError}
                    title={saveStatus === 409 ? strings.editor.conflict : strings.editor.saveError}
                    onRetry={
                        saveStatus === 409
                            ? () => {
                                  setDraft(null);
                                  baseVersion.current = null;
                                  void rule.refetch();
                              }
                            : undefined
                    }
                />
            )}
            {saved && (
                <p role="status" className="text-xs font-semibold text-ok-strong">
                    {strings.editor.saved}
                </p>
            )}
            {disarm.isError && <ErrorState error={disarm.error} />}

            {validation && (
                <p role="alert" className="vy-auto-error">
                    {conditionProblem
                        ? strings.workspace.incompleteConditions
                        : recoveryProblem
                          ? strings.workspace.incompatibleRecovery
                          : strings.workspace.invalidParams}
                </p>
            )}
            <div className="vy-auto-editor-layout" hidden={activePanel !== 'workflow'} data-panel="workflow">
                <div className="vy-auto-editor-main">
                    <section className="grid gap-2 rounded-lg border border-border bg-surface p-4">
                        <div className="grid gap-2 sm:grid-cols-2">
                            <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-title">
                                {strings.editor.titleLabel}
                                <input
                                    id="automation-title"
                                    className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                    value={current.title}
                                    onChange={(event) => edit({ title: event.target.value })}
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-key">
                                {strings.editor.keyLabel}
                                <input
                                    id="automation-key"
                                    className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                    value={current.ruleKey}
                                    onChange={(event) => edit({ ruleKey: event.target.value })}
                                    aria-describedby="automation-key-help"
                                />
                                <span id="automation-key-help" className="font-normal text-fg-2">
                                    {strings.editor.keyHelp}
                                </span>
                            </label>
                        </div>
                    </section>

                    <fieldset className="grid gap-2 rounded-lg border border-border bg-surface p-4">
                        <legend className="px-1 font-display text-sm font-semibold text-fg">
                            {strings.editor.triggerLegend}
                        </legend>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-trigger">
                            {strings.editor.triggerLabel}
                            <select
                                id="automation-trigger"
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.triggerKey}
                                onChange={(event) => edit({ triggerKey: event.target.value })}
                            >
                                <option value="">{strings.editor.triggerPick}</option>
                                {book.triggers.map((candidate) => (
                                    <option key={candidate.key} value={candidate.key}>
                                        {providerLabel(candidate)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <p className="text-xs text-fg-2">{strings.editor.triggerHelp}</p>
                        {trigger !== undefined && (
                            <p className="text-[13px] text-fg">
                                {readable(readSentence(trigger.sentence, providerLabel(trigger)))}
                            </p>
                        )}
                    </fieldset>

                    <fieldset className="vy-auto-builder-section">
                        <legend>
                            <span>02</span>
                            {strings.editor.conditionsLegend}
                        </legend>
                        <ConditionEditor
                            value={current.conditions}
                            catalogue={book}
                            onChange={(conditions) => edit({ conditions })}
                        />
                    </fieldset>
                    <fieldset className="grid gap-2 rounded-lg border border-border bg-surface p-4">
                        <legend className="px-1 font-display text-sm font-semibold text-fg">
                            {strings.editor.actionsLegend}
                        </legend>
                        {current.actions.length === 0 && (
                            <p className="text-xs text-danger">{strings.editor.actionEmpty}</p>
                        )}
                        {current.actions.map((action, index) => {
                            const descriptor = descriptorOf(book, action.actionKey);
                            const failure = book.failure_policies.find(
                                (candidate) => candidate.key === action.failurePolicyKey,
                            );
                            const retry = book.retry_policies.find(
                                (candidate) => candidate.key === action.retryPolicyKey,
                            );
                            const inert = failure?.cancels_retries === true && (retry?.max_attempts ?? 1) > 1;
                            return (
                                <div key={index} className="grid gap-2 rounded border border-border p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-[13px] font-semibold text-fg">
                                            {strings.editor.stepLabel(index)}:{' '}
                                            {descriptor === undefined
                                                ? strings.common.unknownAction(action.actionKey)
                                                : readSentence(
                                                      descriptor.sentence,
                                                      providerLabel(descriptor),
                                                      placeholderValues(descriptor.sentence, action.params),
                                                  )}
                                        </p>
                                        <div className="flex gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label={strings.editor.actionUp}
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                            >
                                                ↑
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label={strings.editor.actionDown}
                                                disabled={index === current.actions.length - 1}
                                                onClick={() => move(index, 1)}
                                            >
                                                ↓
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    edit({ actions: current.actions.filter((_, at) => at !== index) })
                                                }
                                            >
                                                {strings.editor.actionRemove}
                                            </Button>
                                        </div>
                                    </div>
                                    {descriptor !== undefined && descriptor.reversible === false && (
                                        <p className="text-xs text-warn-strong">{strings.editor.irreversible}</p>
                                    )}
                                    {declares(descriptor, 'RequiresApprovalBeforeEffect') && (
                                        <p className="text-xs text-fg-2">{strings.editor.approvalBefore}</p>
                                    )}
                                    {declares(descriptor, 'ProducesReviewItem') && (
                                        <p className="text-xs text-fg-2">{strings.editor.reviewAfter}</p>
                                    )}
                                    {action.actionKey === 'notify' && (
                                        <AutomationTemplatePicker
                                            triggerKey={current.triggerKey}
                                            value={action.params}
                                            onChange={(params) => editAction(index, { params })}
                                        />
                                    )}
                                    <ParamsForm
                                        idPrefix={`action-${index}`}
                                        schema={descriptor?.params_schema}
                                        value={action.params}
                                        emptyNote={strings.common.none}
                                        onChange={(params) => editAction(index, { params })}
                                    />
                                    <details className="vy-auto-step-advanced">
                                        <summary>{strings.workspace.advanced}</summary>
                                        <div className="grid gap-2 sm:grid-cols-3">
                                            <label
                                                className="grid gap-1 text-xs font-semibold text-fg-2"
                                                htmlFor={`action-${index}-retry`}
                                            >
                                                {strings.editor.retryLabel}
                                                <select
                                                    id={`action-${index}-retry`}
                                                    className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                                    value={action.retryPolicyKey}
                                                    onChange={(event) =>
                                                        editAction(index, { retryPolicyKey: event.target.value })
                                                    }
                                                >
                                                    <option value="" />
                                                    {book.retry_policies.map((policy) => (
                                                        <option key={policy.key} value={policy.key}>
                                                            {providerLabel(policy)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label
                                                className="grid gap-1 text-xs font-semibold text-fg-2"
                                                htmlFor={`action-${index}-failure`}
                                            >
                                                {strings.editor.failureLabel}
                                                <select
                                                    id={`action-${index}-failure`}
                                                    className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                                    value={action.failurePolicyKey}
                                                    onChange={(event) =>
                                                        editAction(index, { failurePolicyKey: event.target.value })
                                                    }
                                                >
                                                    <option value="" />
                                                    {book.failure_policies.map((policy) => (
                                                        <option key={policy.key} value={policy.key}>
                                                            {providerLabel(policy)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label
                                                className="grid gap-1 text-xs font-semibold text-fg-2"
                                                htmlFor={`action-${index}-delay`}
                                            >
                                                {strings.editor.delayLabel}
                                                <select
                                                    id={`action-${index}-delay`}
                                                    className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                                    value={action.delayAnchorKey}
                                                    onChange={(event) =>
                                                        editAction(index, {
                                                            delayAnchorKey: event.target.value,
                                                            delay: {},
                                                            delayVersion: 1,
                                                        })
                                                    }
                                                >
                                                    <option value="">{strings.editor.delayNone}</option>
                                                    {book.anchors.map((anchor) => (
                                                        <option key={anchor.key} value={anchor.key}>
                                                            {providerLabel(anchor)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                        </div>
                                        {action.delayAnchorKey && (
                                            <section>
                                                <h3>{strings.workspace.delay}</h3>
                                                <ParamsForm
                                                    idPrefix={`delay-${index}`}
                                                    schema={
                                                        book.anchors.find(
                                                            (entry) => entry.key === action.delayAnchorKey,
                                                        )?.params_schema
                                                    }
                                                    value={action.delay}
                                                    onChange={(delay) => editAction(index, { delay })}
                                                    emptyNote={strings.common.none}
                                                />
                                            </section>
                                        )}
                                        <section>
                                            <h3>{strings.workspace.guard}</h3>
                                            <ConditionEditor
                                                value={action.guard}
                                                catalogue={book}
                                                prefix={`guard-${index}`}
                                                onChange={(guard) => editAction(index, { guard })}
                                            />
                                        </section>
                                        <label className="vy-auto-field">
                                            <span>{strings.workspace.owner}</span>
                                            <input
                                                type="number"
                                                min={1}
                                                step={1}
                                                value={action.owner ?? ''}
                                                onChange={(e) =>
                                                    editAction(index, {
                                                        owner: e.target.value ? Number(e.target.value) : null,
                                                    })
                                                }
                                            />
                                        </label>
                                        {inert && (
                                            <p className="text-xs text-danger">{strings.editor.inertCombination}</p>
                                        )}
                                    </details>
                                </div>
                            );
                        })}
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-action-add">
                            {strings.editor.actionAdd}
                            <select
                                id="automation-action-add"
                                className="h-8 max-w-sm rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value=""
                                onChange={(event) => {
                                    if (event.target.value === '') return;
                                    edit({
                                        actions: [...current.actions, newAction(event.target.value, book)],
                                    });
                                }}
                            >
                                <option value="">{strings.editor.actionPick}</option>
                                {book.actions.map((candidate) => (
                                    <option key={candidate.key} value={candidate.key}>
                                        {providerLabel(candidate)}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </fieldset>
                </div>
                <aside className="vy-auto-outline">
                    <h2>{strings.workspace.summary}</h2>
                    <RuleFlow
                        catalogue={book}
                        rule={{
                            trigger_key: current.triggerKey,
                            conditions: current.conditions,
                            actions: bodyOf(current, null).actions ?? [],
                        }}
                    />
                    <p className="vy-auto-muted">{strings.workspace.noticeHint}</p>
                </aside>
            </div>
            <div hidden={activePanel !== 'settings'} data-panel="settings">
                <fieldset className="grid gap-2 rounded-lg border border-border bg-surface p-4">
                    <legend className="px-1 font-display text-sm font-semibold text-fg">
                        {strings.editor.settingsLegend}
                    </legend>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-reentry">
                            {strings.editor.reentryLabel}
                            <select
                                id="automation-reentry"
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.reentry}
                                onChange={(event) => edit({ reentry: event.target.value as Draft['reentry'] })}
                            >
                                <option value="never">{strings.workspace.never}</option>
                                <option value="on_new_occurrence">{strings.workspace.occurrence}</option>
                                <option value="always">{strings.workspace.always}</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-cooldown">
                            {strings.editor.cooldownLabel}
                            <input
                                id="automation-cooldown"
                                type="number"
                                min={0}
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.cooldownSeconds}
                                onChange={(event) => edit({ cooldownSeconds: Number(event.target.value) })}
                                aria-describedby="automation-cooldown-help"
                            />
                            <span id="automation-cooldown-help" className="font-normal text-fg-2">
                                {strings.editor.cooldownHelp}
                            </span>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-run-order">
                            {strings.editor.runOrderLabel}
                            <input
                                id="automation-run-order"
                                type="number"
                                min={0}
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.runOrder}
                                onChange={(event) => edit({ runOrder: Number(event.target.value) })}
                                aria-describedby="automation-run-order-help"
                            />
                            <span id="automation-run-order-help" className="font-normal text-fg-2">
                                {strings.editor.runOrderHelp}
                            </span>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-subject">
                            {strings.editor.subjectLabel}
                            <select
                                id="automation-subject"
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.subjectBinding}
                                onChange={(event) =>
                                    edit({ subjectBinding: event.target.value as Draft['subjectBinding'] })
                                }
                            >
                                <option value="learner">{strings.workspace.learner}</option>
                                <option value="instructor">{strings.workspace.instructor}</option>
                                <option value="course">{strings.workspace.courseSubject}</option>
                                <option value="cohort">{strings.workspace.cohort}</option>
                                <option value="organisation">{strings.workspace.organisation}</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-times">
                            {strings.editor.timesPerSubjectLabel}
                            <input
                                id="automation-times"
                                type="number"
                                min={1}
                                placeholder={strings.editor.unlimited}
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.timesPerSubject ?? ''}
                                onChange={(event) =>
                                    edit({
                                        timesPerSubject: event.target.value === '' ? null : Number(event.target.value),
                                    })
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-completions">
                            {strings.editor.totalCompletionsLabel}
                            <input
                                id="automation-completions"
                                type="number"
                                min={1}
                                placeholder={strings.editor.unlimited}
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={current.totalCompletions ?? ''}
                                onChange={(event) =>
                                    edit({
                                        totalCompletions: event.target.value === '' ? null : Number(event.target.value),
                                    })
                                }
                            />
                        </label>
                    </div>
                    {cooldownProblem && <p className="text-xs text-danger">{strings.editor.cooldownZeroWithEmit}</p>}
                </fieldset>{' '}
                <section className="vy-auto-builder-section">
                    <h2>{strings.workspace.settingsHint}</h2>
                    <div className="vy-auto-fields">
                        {[
                            { label: strings.workspace.course, key: 'courseId', hint: strings.workspace.allCourses },
                            {
                                label: strings.workspace.aiOccurrence,
                                key: 'maxAiOccurrence',
                                hint: strings.editor.unlimited,
                            },
                            { label: strings.workspace.aiArming, key: 'maxAiArming', hint: strings.editor.unlimited },
                        ].map(({ label, key, hint }) => (
                            <label key={key} className="vy-auto-field">
                                <span>{label}</span>
                                <input
                                    type="number"
                                    min={1}
                                    step={1}
                                    value={current[key as 'courseId'] ?? ''}
                                    placeholder={hint}
                                    onChange={(e) => edit({ [key]: e.target.value ? Number(e.target.value) : null })}
                                />
                            </label>
                        ))}
                    </div>
                </section>
            </div>
            <div hidden={activePanel !== 'rehearse'} data-panel="rehearse">
                {stored ? (
                    draft !== null ? (
                        <p className="vy-auto-notice">{strings.workspace.previewHint}</p>
                    ) : (
                        <AutomationPreviewPanel ruleId={stored.id} />
                    )
                ) : (
                    <p className="vy-auto-notice">{strings.workspace.saveFirst}</p>
                )}
            </div>

            <div className="vy-auto-savebar">
                <span>
                    {draft !== null
                        ? strings.workspace.previewHint
                        : stored
                          ? strings.editor.saved
                          : strings.workspace.saveFirst}
                </span>
                <Button
                    onClick={submit}
                    disabled={
                        busy ||
                        current.title.trim() === '' ||
                        current.triggerKey === '' ||
                        current.actions.length === 0 ||
                        cooldownProblem
                    }
                >
                    {busy ? strings.editor.saving : strings.editor.save}
                </Button>
            </div>

            <Dialog
                open={leave !== null}
                onOpenChange={(open) => {
                    if (!open) setLeave(null);
                }}
            >
                <DialogContent className="vy-auto-dialog">
                    <DialogTitle>{strings.workspace.discardTitle}</DialogTitle>
                    <DialogDescription>{strings.workspace.discardHint}</DialogDescription>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setLeave(null)}>
                            {strings.workspace.keepEditing}
                        </Button>
                        <Button
                            onClick={() => {
                                if (leave) {
                                    setDraft(null);
                                    navigate(leave);
                                }
                            }}
                        >
                            {strings.workspace.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog open={armOpen} onOpenChange={setArmOpen}>
                <DialogContent className="vy-auto-dialog">
                    <DialogTitle>{strings.editor.armDialogTitle}</DialogTitle>
                    <DialogDescription>{strings.editor.armDialogBody}</DialogDescription>
                    {arm.isError && <ErrorState error={arm.error} />}
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setArmOpen(false)}>
                            {strings.editor.cancel}
                        </Button>
                        <Button
                            disabled={arm.isPending || stored === undefined}
                            onClick={() => {
                                if (stored === undefined) return;
                                arm.mutate(
                                    {
                                        id: stored.id,
                                        expectedVersion: stored.version,
                                        idempotencyKey: newIdempotencyKey(),
                                    },
                                    { onSuccess: () => setArmOpen(false) },
                                );
                            }}
                        >
                            {arm.isPending ? strings.editor.arming : strings.editor.armConfirm}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={archiveOpen} onOpenChange={setArchiveOpen}>
                <DialogContent className="vy-auto-dialog">
                    <DialogTitle>{strings.editor.archiveDialogTitle}</DialogTitle>
                    <DialogDescription>{strings.editor.archiveDialogBody}</DialogDescription>
                    {archive.isError && <ErrorState error={archive.error} />}
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setArchiveOpen(false)}>
                            {strings.editor.cancel}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={archive.isPending || stored === undefined}
                            onClick={() => {
                                if (stored === undefined) return;
                                archive.mutate(
                                    { id: stored.id, idempotencyKey: newIdempotencyKey() },
                                    {
                                        onSuccess: () => {
                                            setArchiveOpen(false);
                                            navigate(automationHash.list());
                                        },
                                    },
                                );
                            }}
                        >
                            {strings.editor.archiveConfirm}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </fieldset>
    );
}
