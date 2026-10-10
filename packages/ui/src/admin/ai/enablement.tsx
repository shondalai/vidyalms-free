import { useState } from 'react';
import { Badge, Button, ErrorState, Skeleton } from '@/shared/components';
import {
    newIdempotencyKey,
    useAiEnablement,
    useClearAiStop,
    useSetAiEnablement,
    useSetAiStop,
} from './api';
import { aiStrings as strings } from './strings';

/*
 * Levels one and two, with the stop above them.
 *
 * The helper list is generated from what the server read out of the placement
 * registry, never from a list this file keeps: a newly installed helper arrives
 * with its own switch and this screen is not edited. That is the whole reason
 * the enablement route answers with placements rather than with a bag of
 * booleans.
 *
 * The stop is drawn as its own block rather than as a seventh switch, because a
 * control assembled from toggles is not the control the AI Act asks for. It
 * asks once before it stops, and it says who stopped it and when.
 */

function editionBadge(edition: string) {
    if (edition === 'pro') {
        return <Badge variant="accent">{strings.enablement.pro}</Badge>;
    }
    if (edition === 'third_party') {
        return <Badge variant="neutral">{strings.enablement.thirdParty}</Badge>;
    }
    return <Badge variant="neutral">{strings.enablement.free}</Badge>;
}

export function AiEnablementPanel() {
    const enablement = useAiEnablement();
    const save = useSetAiEnablement();
    const stop = useSetAiStop();
    const clear = useClearAiStop();
    const [draft, setDraft] = useState<{ site: boolean; placements: Record<string, boolean> } | null>(null);
    const [reason, setReason] = useState('');
    const [confirming, setConfirming] = useState(false);

    if (enablement.isError) {
        return (
            <ErrorState
                error={enablement.error}
                title={strings.errors.load}
                onRetry={() => void enablement.refetch()}
            />
        );
    }

    if (enablement.data === undefined) {
        return <Skeleton className="h-64 w-full" />;
    }

    const server = enablement.data;
    const current =
        draft ?? {
            site: server.site_enabled,
            placements: Object.fromEntries(server.placements.map((row) => [row.key, row.enabled])),
        };

    const dirty =
        current.site !== server.site_enabled ||
        server.placements.some((row) => current.placements[row.key] !== row.enabled);

    const set = (patch: Partial<{ site: boolean; placements: Record<string, boolean> }>) =>
        setDraft({ ...current, ...patch });

    const onSave = () => {
        save.mutate(
            {
                body: { site_enabled: current.site, placements: current.placements },
                idempotencyKey: newIdempotencyKey(),
            },
            { onSuccess: () => setDraft(null) },
        );
    };

    return (
        <div className="grid gap-5">
            <section className="grid gap-3 rounded-lg border border-border bg-surface p-5">
                <div>
                    <h2 className="font-display text-xl font-semibold text-fg">{strings.enablement.title}</h2>
                    <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.enablement.intro}</p>
                </div>

                <label className="flex items-start gap-2.5 text-sm text-fg">
                    <input
                        type="checkbox"
                        className="mt-0.5 h-4 w-4"
                        checked={current.site}
                        onChange={(event) => set({ site: event.target.checked })}
                    />
                    <span>
                        <span className="font-semibold">{strings.enablement.siteLabel}</span>
                        <span className="block text-xs text-fg-2">{strings.enablement.siteHint}</span>
                    </span>
                </label>

                <div className="grid gap-2">
                    <h3 className="text-sm font-semibold text-fg">{strings.enablement.placements}</h3>
                    <p className="max-w-[70ch] text-xs text-fg-2">{strings.enablement.placementsHint}</p>
                    {server.placements.length === 0 ? (
                        <p className="text-sm text-fg-2">{strings.enablement.placementsEmpty}</p>
                    ) : (
                        <ul className="grid gap-2">
                            {server.placements.map((placement) => (
                                <li key={placement.key} className="flex items-start justify-between gap-3 rounded-lg border border-border p-4">
                                    <div className="flex min-w-0 items-start gap-3 text-sm text-fg">
                                        <input
                                            id={`ai-helper-${placement.key}`}
                                            aria-describedby={`ai-helper-${placement.key}-description ai-helper-${placement.key}-location`}
                                            type="checkbox"
                                            className="mt-1 h-4 w-4 shrink-0"
                                            checked={current.placements[placement.key] ?? false}
                                            onChange={(event) =>
                                                set({
                                                    placements: {
                                                        ...current.placements,
                                                        [placement.key]: event.target.checked,
                                                    },
                                                })
                                            }
                                        />
                                        <div className="min-w-0">
                                            <label htmlFor={`ai-helper-${placement.key}`} className="cursor-pointer font-semibold">
                                                {placement.label}
                                            </label>
                                            <p id={`ai-helper-${placement.key}-description`} className="mt-1 max-w-[70ch] text-sm leading-relaxed text-fg-2">
                                                {placement.description}
                                            </p>
                                            <p id={`ai-helper-${placement.key}-location`} className="mt-2 text-xs text-fg-2">
                                                {placement.surface_label}
                                            </p>
                                        </div>
                                    </div>
                                    <span className="shrink-0">{editionBadge(placement.edition)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <p className="text-xs text-fg-2">
                    {server.routes.enabled === 0
                        ? strings.enablement.routesNone
                        : strings.enablement.routesSummary(
                              server.routes.enabled,
                              server.routes.total,
                              server.routes.tasks_with_enabled_route,
                          )}
                </p>
                <p className="text-xs text-fg-2">
                    {server.policy === null
                        ? strings.enablement.policyNone
                        : strings.enablement.policyLevel(server.policy.version, server.policy.acceptance_count ?? 0)}
                </p>

                <div className="flex items-center gap-3">
                    <Button size="sm" disabled={!dirty || save.isPending} onClick={onSave}>
                        {strings.enablement.save}
                    </Button>
                    {save.isSuccess && !dirty ? (
                        <span className="text-xs font-semibold text-ok-strong">{strings.enablement.saved}</span>
                    ) : null}
                    {save.isError ? (
                        <span role="alert" className="text-xs font-semibold text-danger-strong">
                            {strings.errors.save}
                        </span>
                    ) : null}
                </div>
            </section>

            <section className="grid gap-3 rounded-lg border border-border bg-surface p-5">
                <div>
                    <h2 className="font-display text-lg font-semibold text-fg">{strings.stop.title}</h2>
                    <p className="mt-1 max-w-[70ch] text-sm text-fg-2">{strings.stop.intro}</p>
                </div>

                <p className="text-sm font-semibold text-fg" data-testid="ai-stop-state">
                    {server.stop.stopped ? strings.stop.stopped : strings.stop.running}
                </p>

                {server.stop.stopped && server.stop.set_by !== null && server.stop.set_at !== null ? (
                    <p className="text-xs text-fg-2">
                        {strings.stop.setBy(server.stop.set_by ?? 0, server.stop.set_at ?? '')}
                    </p>
                ) : null}
                {!server.stop.stopped && server.stop.cleared_by != null && server.stop.cleared_at != null ? (
                    <p className="text-xs text-fg-2">
                        {strings.stop.clearedBy(server.stop.cleared_by, server.stop.cleared_at)}
                    </p>
                ) : null}

                {server.stop.stopped ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={clear.isPending}
                        onClick={() => clear.mutate({ idempotencyKey: newIdempotencyKey() })}
                    >
                        {strings.stop.clear}
                    </Button>
                ) : confirming ? (
                    <div role="group" aria-label={strings.stop.confirmTitle} className="grid gap-2">
                        <p className="text-sm font-semibold text-fg">{strings.stop.confirmTitle}</p>
                        <p className="max-w-[70ch] text-xs text-fg-2">{strings.stop.confirmBody}</p>
                        <label className="grid gap-1 text-xs font-semibold text-fg-2">
                            {strings.stop.reasonLabel}
                            <input
                                type="text"
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                            />
                        </label>
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="destructive"
                                disabled={stop.isPending}
                                onClick={() => {
                                    stop.mutate(
                                        { reason, idempotencyKey: newIdempotencyKey() },
                                        { onSuccess: () => setConfirming(false) },
                                    );
                                }}
                            >
                                {strings.stop.confirm}
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => setConfirming(false)}>
                                {strings.stop.cancel}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button size="sm" variant="destructive" onClick={() => setConfirming(true)}>
                        {strings.stop.set}
                    </Button>
                )}
            </section>
        </div>
    );
}
