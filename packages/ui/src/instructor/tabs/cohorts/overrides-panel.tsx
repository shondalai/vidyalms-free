import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import type { UserAccount } from '@vidyalms/api-sdk';
import { Button, ErrorState, Icon, Skeleton } from '@/shared/components';
import { useDateOverrides, useDeleteDateOverride, useSetDateOverride } from '@/shared/flow';
import type { ConditionTarget } from '@/condition-editors';
import { flowStrings } from '../../flow-strings';
import { Field, Select, TextInput } from '../../components/field';
import { LearnerPicker } from '../../components/learner-picker';

/*
 * Date exceptions, and the merge rule printed beside them.
 *
 * One table and one published order, because support cannot answer "why did
 * this student get until Friday" without knowing which layer won. The order
 * comes back on the payload rather than being written here, so the screen
 * and the code cannot drift into two different answers.
 *
 * The most generous value wins inside one level: the earliest opening, the
 * latest closing, the longest time limit and the most attempts.
 */

const strings = flowStrings.cohorts.overrides;

export function OverridesPanel({
    cohortId,
    targets,
}: {
    cohortId: number;
    targets: readonly ConditionTarget[];
}) {
    const overrides = useDateOverrides(cohortId);
    const setOverride = useSetDateOverride();
    const remove = useDeleteDateOverride();
    const scopeTargets = targets.filter((target) => target.kind !== 'course');

    const [scope, setScope] = useState<'cohort' | 'user'>('cohort');
    const [learner, setLearner] = useState<UserAccount | null>(null);
    const [target, setTarget] = useState(() =>
        scopeTargets[0] === undefined ? '' : `${scopeTargets[0].kind}:${scopeTargets[0].id}`,
    );
    const [availableFrom, setAvailableFrom] = useState('');
    const [dueAt, setDueAt] = useState('');
    const [cutoffAt, setCutoffAt] = useState('');

    if (overrides.isPending) {
        return <Skeleton className="h-24 w-full" />;
    }
    if (overrides.isError) {
        return (
            <ErrorState
                error={overrides.error}
                title={flowStrings.common.loadError}
                onRetry={() => void overrides.refetch()}
            />
        );
    }

    const page = overrides.data;
    const order = (page.merge_order ?? ['user', 'group', 'cohort']).join(', ');
    const optional = (value: string) => (value.trim() === '' ? null : value.trim());

    const submit = () => {
        const [kind, targetId] = target.split(':');
        if (kind === undefined || targetId === undefined) {
            return;
        }
        setOverride.mutate({
            cohortId,
            body: {
                scope_type: scope,
                scope_id: scope === 'user' ? (learner?.id ?? 0) : cohortId,
                target_type: kind as 'module',
                target_id: Number(targetId),
                available_from: optional(availableFrom),
                due_at: optional(dueAt),
                cutoff_at: optional(cutoffAt),
            },
        });
    };

    return (
        <section className="grid gap-3">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{strings.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.mergeOrder(order)}</p>
            </div>

            {page.items.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.none}</p>
            ) : (
                <ul className="rounded border border-border">
                    {page.items.map((override) => {
                        const match = targets.find(
                            (entry) => entry.kind === override.target_type && entry.id === override.target_id,
                        );
                        return (
                            <li
                                key={override.id}
                                className="flex flex-wrap items-center gap-2 border-b border-border/60 px-3 py-2 text-[12.5px] last:border-b-0"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium text-fg">
                                        {match?.title ?? uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: override.target_type, p1: override.target_id })}
                                    </span>
                                    <span className="block text-[11.5px] text-fg-2">
                                        {strings.scopes[override.scope_type] ?? override.scope_type} ·{' '}
                                        {[override.available_from, override.due_at, override.cutoff_at]
                                            .filter((value): value is string => typeof value === 'string')
                                            .join(' · ')}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={remove.isPending}
                                    onClick={() => remove.mutate({ overrideId: override.id })}
                                >
                                    <Icon icon={Trash2} size={13} />
                                    {strings.remove}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}

            <div className="grid gap-2.5 rounded border border-border bg-bg p-3 sm:grid-cols-2">
                <Field label={strings.scope}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={scope}
                            onChange={(event) => setScope(event.target.value as 'cohort' | 'user')}
                        >
                            <option value="cohort">{strings.scopes.cohort}</option>
                            <option value="user">{strings.scopes.user}</option>
                        </Select>
                    )}
                </Field>
                <Field label={strings.target}>
                    {({ id }) => (
                        <Select id={id} value={target} onChange={(event) => setTarget(event.target.value)}>
                            {scopeTargets.map((entry) => (
                                <option key={`${entry.kind}:${entry.id}`} value={`${entry.kind}:${entry.id}`}>
                                    {entry.title}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                {scope === 'user' && (
                    <div className="sm:col-span-2">
                        <LearnerPicker
                            label={flowStrings.cohorts.members.columns.learner}
                            value={learner}
                            onChange={setLearner}
                        />
                    </div>
                )}
                <Field label={flowStrings.cohorts.schedule.dates.available}>
                    {({ id }) => (
                        <TextInput
                            id={id}
                            placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                            value={availableFrom}
                            onChange={(event) => setAvailableFrom(event.target.value)}
                        />
                    )}
                </Field>
                <Field label={flowStrings.cohorts.schedule.dates.due}>
                    {({ id }) => (
                        <TextInput
                            id={id}
                            placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                            value={dueAt}
                            onChange={(event) => setDueAt(event.target.value)}
                        />
                    )}
                </Field>
                <Field label={flowStrings.cohorts.schedule.dates.cutoff}>
                    {({ id }) => (
                        <TextInput
                            id={id}
                            placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                            value={cutoffAt}
                            onChange={(event) => setCutoffAt(event.target.value)}
                        />
                    )}
                </Field>
                <div className="sm:col-span-2">
                    <Button
                        size="sm"
                        disabled={
                            target === '' || setOverride.isPending || (scope === 'user' && learner === null)
                        }
                        onClick={submit}
                    >
                        {strings.save}
                    </Button>
                </div>
                {setOverride.isError && (
                    <div className="sm:col-span-2">
                        <ErrorState error={setOverride.error} />
                    </div>
                )}
            </div>
        </section>
    );
}
