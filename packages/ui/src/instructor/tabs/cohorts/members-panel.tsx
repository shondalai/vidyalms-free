import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import type { UserAccount } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Pagination,
    Skeleton,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useAddCohortMembers, useCohortMembers, useMoveCohortMember, useUpdateCohortMember } from '@/shared/flow';
import { flowStrings } from '../../flow-strings';
import { Field, Select, TextInput } from '../../components/field';
import { LearnerPicker } from '../../components/learner-picker';
import type { Cohort, CohortMember } from '../../lib/flow-types';

/*
 * The roster of one run.
 *
 * Every member carries their own anchor, which is the direct fix for a
 * documented commercial flaw elsewhere: with a shared anchor taken from when
 * the course was attached to the group, every later joiner gets
 * proportionally less time and the same drip timeline as somebody who
 * started months earlier.
 *
 * A move is three writes and no fourth, and the screen says so before the
 * operator commits: the old row closes, a new one opens, the enrolment
 * repoints, and no progress row, attempt, submission, grade or certificate
 * is written or deleted. That is exactly what makes it a move rather than an
 * unenrol and a re-enrol.
 */

const strings = flowStrings.cohorts.members;

const STATE_VARIANT: Record<string, 'ok' | 'warn' | 'neutral' | 'danger'> = {
    active: 'ok',
    completed: 'ok',
    waitlisted: 'warn',
    invited: 'neutral',
    withdrawn: 'neutral',
    moved: 'neutral',
};

function MoveDialog({
    member,
    cohort,
    siblings,
    onClose,
}: {
    member: CohortMember;
    cohort: Cohort;
    siblings: readonly Cohort[];
    onClose: () => void;
}) {
    const move = useMoveCohortMember();
    const announce = useAnnounce();
    const destinations = siblings.filter((candidate) => candidate.id !== cohort.id);
    const [to, setTo] = useState(() => String(destinations[0]?.id ?? ''));
    const [anchorAt, setAnchorAt] = useState('');
    const [reason, setReason] = useState('');

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogTitle>{strings.moveTitle}</DialogTitle>
                <DialogDescription>{strings.movePreserves}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    {destinations.length === 0 ? (
                        <p className="text-[12.5px] text-fg-2">{strings.noDestinations}</p>
                    ) : (
                        <>
                            <Field label={strings.moveTo}>
                                {({ id }) => (
                                    <Select id={id} value={to} onChange={(event) => setTo(event.target.value)}>
                                        {destinations.map((candidate) => (
                                            <option key={candidate.id} value={String(candidate.id)}>
                                                {candidate.title}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field label={strings.moveAnchor} hint={strings.moveAnchorHint}>
                                {({ id, describedBy }) => (
                                    <TextInput
                                        id={id}
                                        aria-describedby={describedBy}
                                        placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                        value={anchorAt}
                                        onChange={(event) => setAnchorAt(event.target.value)}
                                    />
                                )}
                            </Field>
                            <Field label={strings.moveReason}>
                                {({ id }) => (
                                    <TextInput
                                        id={id}
                                        value={reason}
                                        onChange={(event) => setReason(event.target.value)}
                                    />
                                )}
                            </Field>
                        </>
                    )}
                    {move.isError && <ErrorState error={move.error} />}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {flowStrings.common.cancel}
                    </Button>
                    <Button
                        disabled={destinations.length === 0 || to === '' || move.isPending}
                        onClick={() =>
                            move.mutate(
                                {
                                    cohortId: cohort.id,
                                    userId: member.user_id,
                                    body: {
                                        to_cohort_id: Number(to),
                                        anchor_at: anchorAt.trim() === '' ? null : anchorAt.trim(),
                                        reason: reason.trim() === '' ? null : reason.trim(),
                                    },
                                },
                                {
                                    onSuccess: (result) => {
                                        announce(
                                            strings.moved(result.tasks_cancelled, result.tasks_scheduled),
                                        );
                                        onClose();
                                    },
                                },
                            )
                        }
                    >
                        {strings.moveConfirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AddMembersDialog({ cohort, onClose }: { cohort: Cohort; onClose: () => void }) {
    const add = useAddCohortMembers();
    const announce = useAnnounce();
    const [chosen, setChosen] = useState<UserAccount[]>([]);
    const [state, setState] = useState<'invited' | 'active'>('active');
    const [anchorAt, setAnchorAt] = useState('');

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogTitle>{strings.add}</DialogTitle>
                <DialogDescription>{strings.addHint}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    <LearnerPicker
                        label={strings.columns.learner}
                        value={null}
                        excludeIds={chosen.map((account) => account.id)}
                        onChange={(account) => {
                            if (account !== null) {
                                setChosen((current) => [...current, account]);
                            }
                        }}
                        autoFocus
                    />
                    {chosen.length > 0 && (
                        <ul className="grid gap-1">
                            {chosen.map((account) => (
                                <li
                                    key={account.id}
                                    className="flex items-center gap-2 rounded border border-border bg-surface-2 px-2.5 py-1.5 text-[12.5px]"
                                >
                                    <span className="min-w-0 flex-1 truncate">{account.name}</span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setChosen((current) => current.filter((entry) => entry.id !== account.id))
                                        }
                                    >
                                        {flowStrings.cohorts.overrides.remove}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Field label={strings.columns.state}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={state}
                                onChange={(event) => setState(event.target.value as 'invited' | 'active')}
                            >
                                <option value="active">{strings.states.active}</option>
                                <option value="invited">{strings.states.invited}</option>
                            </Select>
                        )}
                    </Field>
                    <Field label={strings.columns.anchor} hint={strings.anchorHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={anchorAt}
                                onChange={(event) => setAnchorAt(event.target.value)}
                            />
                        )}
                    </Field>
                    {add.isError && <ErrorState error={add.error} />}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {flowStrings.common.cancel}
                    </Button>
                    <Button
                        disabled={chosen.length === 0 || add.isPending}
                        onClick={() =>
                            add.mutate(
                                {
                                    cohortId: cohort.id,
                                    body: {
                                        user_ids: chosen.map((account) => account.id),
                                        state,
                                        anchor_at: anchorAt.trim() === '' ? null : anchorAt.trim(),
                                        source: 'manual',
                                    },
                                },
                                {
                                    onSuccess: (result) => {
                                        announce(strings.addResult(result.added, result.waitlisted, result.refused));
                                        onClose();
                                    },
                                },
                            )
                        }
                    >
                        {strings.add}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function MembersPanel({ cohort, siblings }: { cohort: Cohort; siblings: readonly Cohort[] }) {
    const [page, setPage] = useState(1);
    const [stateFilter, setStateFilter] = useState('');
    const members = useCohortMembers(cohort.id, {
        page,
        per_page: 25,
        ...(stateFilter === '' ? {} : { state: stateFilter as 'active' }),
    });
    const updateMember = useUpdateCohortMember();
    const [adding, setAdding] = useState(false);
    const [moving, setMoving] = useState<CohortMember | null>(null);

    if (members.isPending) {
        return <Skeleton className="h-32 w-full" />;
    }
    if (members.isError) {
        return (
            <ErrorState
                error={members.error}
                title={flowStrings.common.loadError}
                onRetry={() => void members.refetch()}
            />
        );
    }

    const data = members.data;
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-end gap-2">
                <h3 className="font-display text-xs font-semibold text-fg">{strings.title}</h3>
                <Field className="w-[200px]" label={strings.columns.state} hideLabel>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={stateFilter}
                            onChange={(event) => {
                                setStateFilter(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">{flowStrings.common.all}</option>
                            {Object.entries(strings.states).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Button className="ml-auto" size="sm" onClick={() => setAdding(true)}>
                    {strings.add}
                </Button>
            </div>

            {data.items.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.none}</p>
            ) : (
                <div className="overflow-x-auto rounded border border-border">
                    <table className="w-full min-w-[640px] border-collapse text-[12.5px]">
                        <thead>
                            <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                <th scope="col" className="px-3 py-1.5">{strings.columns.learner}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.state}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.role}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.anchor}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.progress}</th>
                                <th scope="col" className="px-3 py-1.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.items.map((member) => (
                                <tr key={member.user_id} className="border-t border-border/60">
                                    <td className="px-3 py-2">
                                        {member.learner?.name ?? `#${member.user_id}`}
                                        {member.behind_schedule === true && (
                                            <Badge className="ml-1.5" variant="warn">
                                                {strings.behind}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Badge variant={STATE_VARIANT[member.state] ?? 'neutral'}>
                                            {strings.states[member.state] ?? member.state}
                                        </Badge>
                                        {member.state === 'waitlisted' &&
                                            member.waitlist_position !== null &&
                                            member.waitlist_position !== undefined && (
                                                <span className="ml-1.5 text-[11px] text-fg-2">
                                                    {strings.waitlistPosition(member.waitlist_position)}
                                                </span>
                                            )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Select
                                            aria-label={strings.columns.role}
                                            className="h-8 py-0 text-[12px]"
                                            value={member.role}
                                            onChange={(event) =>
                                                updateMember.mutate({
                                                    cohortId: cohort.id,
                                                    userId: member.user_id,
                                                    body: { role: event.target.value as 'learner' },
                                                })
                                            }
                                        >
                                            {Object.entries(strings.roles).map(([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ))}
                                        </Select>
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">{member.anchor_at ?? '-'}</td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {member.progress_percent === null || member.progress_percent === undefined
                                            ? '-'
                                            : uiText("vidyalms.ui.d4bdf581de91640f", "{p0}%", { p0: member.progress_percent })}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Button size="sm" variant="ghost" onClick={() => setMoving(member)}>
                                            {strings.move}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Pagination
                label={strings.title}
                page={data.page}
                perPage={data.per_page}
                total={data.total}
                onPageChange={setPage}
            />

            {adding && <AddMembersDialog cohort={cohort} onClose={() => setAdding(false)} />}
            {moving !== null && (
                <MoveDialog member={moving} cohort={cohort} siblings={siblings} onClose={() => setMoving(null)} />
            )}
        </section>
    );
}
