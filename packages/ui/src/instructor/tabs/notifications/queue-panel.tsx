import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Badge, Button, ErrorState, Pagination, Skeleton } from '@/shared/components';
import { useCancelQueuedNotification, useNotificationQueue } from '@/shared/flow';
import { flowStrings } from '../../flow-strings';
import { Field, Select } from '../../components/field';

/*
 * The queue.
 *
 * Every enumerated state is shown, because an unenumerated state is a
 * stalled queue nobody can see. Two of them carry a sentence rather than a
 * badge alone: deferred is NOT dropped, it is a send moved forward to the
 * next permitted local hour, and a row already claimed for sending cannot be
 * cancelled and says so instead of pretending.
 */

const strings = flowStrings.notifications.queue;

const STATE_VARIANT: Record<string, 'ok' | 'warn' | 'danger' | 'neutral'> = {
    sent: 'ok',
    pending: 'neutral',
    pending_fanout: 'neutral',
    sending: 'neutral',
    deferred: 'warn',
    suppressed: 'warn',
    failed_permanent: 'danger',
    cancelled: 'neutral',
};

const CANCELLABLE = new Set(['pending', 'pending_fanout', 'deferred']);

export function QueuePanel({ ruleId }: { ruleId?: number }) {
    const [page, setPage] = useState(1);
    const [state, setState] = useState('');
    const queue = useNotificationQueue({
        page,
        per_page: 25,
        ...(ruleId === undefined ? {} : { rule_id: ruleId }),
        ...(state === '' ? {} : { state: state as 'pending' }),
    });
    const cancel = useCancelQueuedNotification();

    if (queue.isPending) {
        return <Skeleton className="h-32 w-full" />;
    }
    if (queue.isError) {
        return (
            <ErrorState error={queue.error} title={flowStrings.common.loadError} onRetry={() => void queue.refetch()} />
        );
    }

    const data = queue.data;
    return (
        <section className="grid gap-3">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.deferredHint}</p>
            </div>

            <div className="flex flex-wrap items-end gap-2">
                <Field className="w-[220px]" label={strings.columns.state}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={state}
                            onChange={(event) => {
                                setState(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">{strings.filterAll}</option>
                            {Object.entries(strings.states).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                    {data.counts_by_state[key] === undefined ? '' : uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: data.counts_by_state[key] })}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            </div>

            {data.items.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.none}</p>
            ) : (
                <div className="overflow-x-auto rounded border border-border">
                    <table className="w-full min-w-[720px] border-collapse text-[12.5px]">
                        <thead>
                            <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                <th scope="col" className="px-3 py-1.5">{strings.columns.message}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.recipient}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.channel}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.state}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.runAt}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.attempts}</th>
                                <th scope="col" className="px-3 py-1.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.items.map((row) => (
                                <tr key={row.id} className="border-t border-border/60">
                                    <td className="px-3 py-2">
                                        <span className="block font-medium text-fg">{row.template_key}</span>
                                        <span className="block text-[11px] text-fg-2">
                                            {row.trigger_key} · {strings.occurrence(row.occurrence_key)}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.recipient?.name ??
                                            (row.recipient_user_id === null || row.recipient_user_id === undefined
                                                ? '-'
                                                : `#${row.recipient_user_id}`)}
                                    </td>
                                    <td className="px-3 py-2">{row.channel_key ?? '-'}</td>
                                    <td className="px-3 py-2">
                                        <Badge variant={STATE_VARIANT[row.state] ?? 'neutral'}>
                                            {strings.states[row.state] ?? row.state}
                                        </Badge>
                                        {row.suppressed_reason !== null && row.suppressed_reason !== undefined && (
                                            <span className="block text-[11px] text-fg-2">
                                                {strings.suppressedReason(row.suppressed_reason)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {row.next_attempt_at ?? row.run_at}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">{row.attempts}</td>
                                    <td className="px-3 py-2 text-right">
                                        {CANCELLABLE.has(row.state) && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                title={strings.cancelHint}
                                                disabled={cancel.isPending}
                                                onClick={() => cancel.mutate({ queueId: row.id })}
                                            >
                                                {strings.cancel}
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {cancel.isError && <ErrorState error={cancel.error} />}

            <Pagination
                label={strings.title}
                page={data.page}
                perPage={data.per_page}
                total={data.total}
                onPageChange={setPage}
            />
        </section>
    );
}
