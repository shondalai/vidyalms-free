import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Button, ErrorState, Icon, Skeleton } from '@/shared/components';
import { useCohortSchedule, useSetCohortSchedule } from '@/shared/flow';
import type { ConditionTarget } from '@/condition-editors';
import { flowStrings } from '../../flow-strings';
import { Field, Select, TextInput } from '../../components/field';
import type { CohortScheduleEntry, ScheduleAnchorInfo } from '../../lib/flow-types';

/*
 * The schedule of one run: authored intent, resolved when it is read.
 *
 * Offsets are stored in SECONDS with a unit hint beside them, never as a
 * whole-day count. A whole-day field makes every item in a sequence land at
 * the same clock hour with no local control, and a days-valued offset has to
 * step the local calendar rather than add blocks of 86400 seconds or a
 * daylight-saving change lands day seven an hour out.
 *
 * The resolved dates are shown beside the authored ones and are labelled
 * with the layer they came from, because three layers can answer and support
 * cannot explain a date without knowing which one did.
 */

const strings = flowStrings.cohorts.schedule;

const SECONDS = { hours: 3600, days: 86_400 } as const;

function toUnits(seconds: number | null | undefined, unit: 'hours' | 'days'): string {
    if (seconds === null || seconds === undefined) {
        return '';
    }
    return String(Math.round((seconds / SECONDS[unit]) * 100) / 100);
}

function fromUnits(raw: string, unit: 'hours' | 'days'): number | null {
    if (raw.trim() === '') {
        return null;
    }
    const value = Number(raw);
    return Number.isFinite(value) ? Math.round(value * SECONDS[unit]) : null;
}

function OffsetField({
    label,
    hint,
    seconds,
    unit,
    onChange,
}: {
    label: string;
    hint?: string;
    seconds: number | null | undefined;
    unit: 'hours' | 'days';
    onChange: (seconds: number | null) => void;
}) {
    return (
        <Field label={label} hint={hint}>
            {({ id, describedBy }) => (
                <TextInput
                    id={id}
                    aria-describedby={describedBy}
                    inputMode="decimal"
                    value={toUnits(seconds, unit)}
                    onChange={(event) => onChange(fromUnits(event.target.value, unit))}
                />
            )}
        </Field>
    );
}

export function SchedulePanel({
    cohortId,
    targets,
    anchors,
}: {
    cohortId: number;
    targets: readonly ConditionTarget[];
    anchors: readonly ScheduleAnchorInfo[];
}) {
    const schedule = useCohortSchedule(cohortId);
    const save = useSetCohortSchedule();
    const [entries, setEntries] = useState<CohortScheduleEntry[]>([]);
    const [dirty, setDirty] = useState(false);

    const stored = schedule.data ?? null;
    useEffect(() => {
        if (stored !== null) {
            setEntries(stored.entries);
            setDirty(false);
        }
    }, [stored]);

    if (schedule.isPending) {
        return <Skeleton className="h-24 w-full" />;
    }
    if (schedule.isError) {
        return (
            <ErrorState
                error={schedule.error}
                title={flowStrings.common.loadError}
                onRetry={() => void schedule.refetch()}
            />
        );
    }

    const resolved = stored?.resolved ?? [];
    const scheduleTargets = targets.filter((target) => target.kind !== 'course');
    const defaultAnchor = anchors[0]?.key ?? 'cohort.starts_at';

    const update = (index: number, change: Partial<CohortScheduleEntry>) => {
        setEntries((current) => current.map((entry, position) => (position === index ? { ...entry, ...change } : entry)));
        setDirty(true);
    };

    return (
        <section className="grid gap-3">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.dueNote}</p>
            </div>

            {entries.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.noEntries}</p>
            ) : (
                <ul className="grid gap-2.5">
                    {entries.map((entry, index) => {
                        const unit = entry.offset_unit;
                        const match = resolved.find(
                            (row) => row.target_type === entry.target_type && row.target_id === entry.target_id,
                        );
                        return (
                            <li key={`${entry.target_type}-${entry.target_id}-${index}`} className="rounded border border-border bg-bg p-3">
                                <div className="grid gap-2.5 sm:grid-cols-3">
                                    <Field label={strings.target}>
                                        {({ id }) => (
                                            <Select
                                                id={id}
                                                value={`${entry.target_type}:${entry.target_id}`}
                                                onChange={(event) => {
                                                    const [kind, targetId] = event.target.value.split(':');
                                                    update(index, {
                                                        target_type: (kind ?? 'module') as CohortScheduleEntry['target_type'],
                                                        target_id: Number(targetId ?? 0),
                                                    });
                                                }}
                                            >
                                                {scheduleTargets.map((target) => (
                                                    <option key={`${target.kind}:${target.id}`} value={`${target.kind}:${target.id}`}>
                                                        {target.title}
                                                    </option>
                                                ))}
                                            </Select>
                                        )}
                                    </Field>
                                    <Field label={strings.anchor}>
                                        {({ id }) => (
                                            <Select
                                                id={id}
                                                value={entry.anchor_key}
                                                onChange={(event) => update(index, { anchor_key: event.target.value })}
                                            >
                                                {anchors.map((anchor) => (
                                                    <option key={anchor.key} value={anchor.key}>
                                                        {anchor.label}
                                                        {anchor.available === false ? uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: flowStrings.common.unavailable }) : ''}
                                                    </option>
                                                ))}
                                            </Select>
                                        )}
                                    </Field>
                                    <Field label={strings.unit} hint={strings.unitHint}>
                                        {({ id, describedBy }) => (
                                            <Select
                                                id={id}
                                                aria-describedby={describedBy}
                                                value={unit}
                                                onChange={(event) =>
                                                    update(index, {
                                                        offset_unit: event.target.value as 'hours' | 'days',
                                                    })
                                                }
                                            >
                                                <option value="days">{strings.units.days}</option>
                                                <option value="hours">{strings.units.hours}</option>
                                            </Select>
                                        )}
                                    </Field>
                                    <OffsetField
                                        label={strings.offset}
                                        seconds={entry.offset_seconds}
                                        unit={unit}
                                        onChange={(seconds) => update(index, { offset_seconds: seconds ?? 0 })}
                                    />
                                    <OffsetField
                                        label={strings.due}
                                        seconds={entry.due_offset_seconds}
                                        unit={unit}
                                        onChange={(seconds) => update(index, { due_offset_seconds: seconds })}
                                    />
                                    <OffsetField
                                        label={strings.cutoff}
                                        seconds={entry.cutoff_offset_seconds}
                                        unit={unit}
                                        onChange={(seconds) => update(index, { cutoff_offset_seconds: seconds })}
                                    />
                                </div>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {match !== undefined && (
                                        <span className="text-[11.5px] text-fg-2">
                                            {strings.resolved}: {strings.dates.available} {match.available_from ?? '-'} ·{' '}
                                            {strings.dates.due} {match.due_at ?? '-'} · {strings.dates.cutoff}{' '}
                                            {match.cutoff_at ?? '-'} ({strings.resolvedFrom[match.source] ?? match.source})
                                        </span>
                                    )}
                                    <Button
                                        className="ml-auto"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            setEntries((current) => current.filter((_, position) => position !== index));
                                            setDirty(true);
                                        }}
                                    >
                                        <Icon icon={Trash2} size={13} />
                                        {flowStrings.cohorts.overrides.remove}
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={scheduleTargets.length === 0}
                    onClick={() => {
                        const first = scheduleTargets[0];
                        if (first === undefined) {
                            return;
                        }
                        setEntries((current) => [
                            ...current,
                            {
                                target_type: first.kind as CohortScheduleEntry['target_type'],
                                target_id: first.id,
                                anchor_key: defaultAnchor,
                                offset_seconds: 0,
                                offset_unit: 'days',
                            },
                        ]);
                        setDirty(true);
                    }}
                >
                    <Icon icon={Plus} size={13} />
                    {strings.addEntry}
                </Button>
                <Button
                    size="sm"
                    disabled={!dirty || save.isPending}
                    onClick={() => save.mutate({ cohortId, entries })}
                >
                    {strings.save}
                </Button>
            </div>
            {save.isError && <ErrorState error={save.error} />}
        </section>
    );
}
