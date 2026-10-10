import { OrgIntro, OrgSegments } from './workspace';
import { orgWorkspaceStrings as ws } from './workspace-strings';
import { useState } from 'react';
import type {
    Organisation,
    OrganisationRolePage,
    OrganisationSeatAssignment,
    OrganisationSeatEvent,
    OrganisationSeatPool,
} from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    DataTable,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    EmptyState,
    ErrorState,
    Skeleton,
    type DataTableColumn,
} from '@/shared/components';
import { ListFrame } from '../components/list-frame';
import { FilterSelect } from '../components/filter-toolbar';
import { TextField } from '../components/field';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import { useStudioDraft, useStudioNavigation } from '../credentials/studio-drafts';
import {
    useAssignSeat,
    useCreateSeatPool,
    useOrganisationAssignments,
    useOrganisationLedger,
    useOrganisationPool,
    useOrganisationPools,
    useReleaseSeat,
    useUpdateSeatPool,
} from './learning-api';
import { CompactStats } from '../components/compact-stats';
import { MemberPicker, ReleasedCoursePicker } from './pickers';
import { organisationLearningStrings as s } from './learning-strings';
import type { OrganisationLocation } from './routes';

/** The one value every empty cell on these screens shows, so a gap reads the same everywhere. */
const NONE = '-';

/** The assignment states a seat can still be returned from. */
const RELEASABLE = ['reserved', 'assigned', 'consumed', 'pending'];

type Props = {
    organisation: Organisation;
    roles: OrganisationRolePage;
    location: OrganisationLocation;
    change: (changes: Partial<OrganisationLocation>) => void;
    notify: (text: string) => void;
};

export function OrganisationSeats(props: Props) {
    return (
        <div className="vy-org-section-stack">
            <OrgIntro title={ws.capacity} description={ws.seatsIntro} />
            {props.location.pool === null ? (
                <Pools {...props} />
            ) : (
                <PoolWorkspace {...props} poolId={props.location.pool} />
            )}
        </div>
    );
}

function Pools({ organisation: org, roles, location, change, notify }: Props) {
    const query = useOrganisationPools(org.id, location.page);
    const [editor, setEditor] = useState<OrganisationSeatPool | 'new' | null>(null);
    const columns: DataTableColumn<OrganisationSeatPool>[] = [
        {
            key: 'name',
            header: s.name,
            weight: 2,
            minWidth: 220,
            cell: (row) => (
                <span className="grid gap-1">
                    <strong className="text-fg">{row.name}</strong>
                    <span className="text-xs text-fg-2">{row.course_title ?? s.courseId(row.course_id)}</span>
                </span>
            ),
        },
        { key: 'capacity', header: s.capacity, width: '110px', cell: (row) => row.balance?.capacity ?? NONE },
        { key: 'held', header: s.held, width: '80px', cell: (row) => row.balance?.held ?? NONE },
        {
            key: 'available',
            header: s.available,
            width: '95px',
            cell: (row) => row.balance?.available ?? NONE,
        },
        {
            key: 'state',
            header: s.state,
            width: '100px',
            cell: (row) => (
                <Badge variant={row.state === 'active' ? 'accent' : 'neutral'}>
                    {s.stateLabels[row.state]}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: s.action,
            width: '220px',
            cell: (row) => (
                <span className="flex flex-wrap gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => change({ pool: row.id, page: 1, state: '' })}
                    >
                        {s.managePool}
                    </Button>
                    {roles.access.site_manager && (
                        <Button variant="ghost" size="sm" onClick={() => setEditor(row)}>
                            {s.editPool}
                        </Button>
                    )}
                </span>
            ),
        },
    ];

    return (
        <>
            <ListFrame
                title={s.pools}
                action={
                    roles.access.site_manager ? (
                        <Button onClick={() => setEditor('new')}>{s.createPool}</Button>
                    ) : undefined
                }
                page={location.page}
                perPage={20}
                total={query.data?.total ?? 0}
                shownCount={query.data?.items.length ?? 0}
                onPageChange={(page) => change({ page })}
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
                note={s.poolHint}
            >
                <DataTable
                    caption={s.pools}
                    rows={query.data?.items ?? []}
                    rowKey={(row) => row.id}
                    columns={columns}
                    loading={query.isPending}
                    empty={<EmptyState title={s.empty} />}
                />
            </ListFrame>
            {editor !== null && (
                <PoolEditor
                    organisation={org}
                    pool={editor === 'new' ? null : editor}
                    close={() => setEditor(null)}
                    notify={notify}
                />
            )}
        </>
    );
}

function PoolEditor({
    organisation: org,
    pool,
    close,
    notify,
}: {
    organisation: Organisation;
    pool: OrganisationSeatPool | null;
    close: () => void;
    notify: (text: string) => void;
}) {
    const [revision] = useState(org.revision);
    const [name, setName] = useState(pool?.name ?? '');
    const [course, setCourse] = useState<number | null>(pool?.course_id ?? null);
    const [capacity, setCapacity] = useState(pool?.seats_granted ?? 10);
    const [policy, setPolicy] = useState<OrganisationSeatPool['reclaim_policy']>(
        pool?.reclaim_policy ?? 'before_start',
    );
    const [state, setState] = useState<OrganisationSeatPool['state']>(pool?.state ?? 'active');
    const setDirty = useStudioDraft('org-seat-pool');
    const navigation = useStudioNavigation();
    const write = useWriteAttempt();
    const create = useCreateSeatPool();
    const update = useUpdateSeatPool();
    const pending = create.isPending || update.isPending;
    const closeSafely = () => {
        if (!pending) {
            navigation.requestLeave(close);
        }
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (course === null) {
            return;
        }

        const body = {
            expected_revision: revision,
            name: name.trim(),
            course_id: course,
            seats_granted: capacity,
            reclaim_policy: policy,
            state,
        };
        const attempt = write.capture(body, () => ({ id: org.id, body }));
        const result = pool
            ? update.mutateAsync({
                  ...attempt.input,
                  poolId: pool.id,
                  idempotencyKey: attempt.idempotencyKey,
              })
            : create.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey });

        void result
            .then(() => {
                write.complete(attempt);
                setDirty(false);
                notify(s.poolSaved);
                close();
            })
            .catch(() => undefined);
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) closeSafely();
            }}
        >
            <DialogContent>
                <DialogTitle>{pool ? s.editPool : s.createPool}</DialogTitle>
                <DialogDescription>{s.poolHint}</DialogDescription>
                <form className="grid gap-4" onSubmit={submit}>
                    <fieldset disabled={pending} className="grid gap-3">
                        <TextField
                            label={s.name}
                            value={name}
                            required
                            maxLength={190}
                            onChange={(event) => {
                                setName(event.target.value);
                                setDirty(true);
                            }}
                        />
                        {!pool && (
                            <ReleasedCoursePicker
                                id={org.id}
                                value={course}
                                choose={(id) => {
                                    setCourse(id);
                                    setDirty(true);
                                }}
                            />
                        )}
                        <TextField
                            label={s.capacity}
                            type="number"
                            min={0}
                            max={1000000}
                            step={1}
                            value={capacity}
                            required
                            onChange={(event) => {
                                setCapacity(Number(event.target.value));
                                setDirty(true);
                            }}
                        />
                        <FilterSelect
                            label={s.policy}
                            value={policy}
                            options={Object.entries(s.policies).map(([value, label]) => ({ value, label }))}
                            onValueChange={(value) => {
                                setPolicy(value as typeof policy);
                                setDirty(true);
                            }}
                        />
                        <FilterSelect
                            label={s.state}
                            value={state}
                            options={Object.entries(s.stateLabels).map(([value, label]) => ({
                                value,
                                label,
                            }))}
                            onValueChange={(value) => {
                                setState(value as typeof state);
                                setDirty(true);
                            }}
                        />
                    </fieldset>
                    {(create.isError || update.isError) && (
                        <ErrorState error={create.error ?? update.error} />
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" disabled={pending} onClick={closeSafely}>
                            {s.cancel}
                        </Button>
                        <Button type="submit" disabled={pending || course === null || !name.trim()}>
                            {s.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PoolWorkspace({
    organisation: org,
    roles,
    location,
    change,
    notify,
    poolId,
}: Props & { poolId: number }) {
    const organisationWide = roles.access.unit_id === null;
    const ledger = organisationWide && location.state === 'ledger';
    const [assign, setAssign] = useState(false);
    const query = useOrganisationPool(org.id, poolId);

    if (!query.data) {
        return query.isError ? (
            <ErrorState
                error={query.error}
                onRetry={() => {
                    void query.refetch();
                }}
            />
        ) : (
            <Skeleton className="h-48 w-full" />
        );
    }

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <Button variant="ghost" onClick={() => change({ pool: null, page: 1, state: '' })}>
                    {s.back}
                </Button>
                {organisationWide && (
                    <OrgSegments
                        label={s.poolView}
                        value={ledger ? 'ledger' : 'assignments'}
                        options={[
                            { value: 'assignments', label: s.assignments },
                            { value: 'ledger', label: s.ledger },
                        ]}
                        onChange={(state) => change({ state, page: 1 })}
                    />
                )}
            </div>
            <header>
                <h3 className="font-display text-xl font-semibold text-fg">{query.data.name}</h3>
                <p className="mt-1 text-xs text-fg-2">
                    {query.data.course_title ?? s.courseId(query.data.course_id)}
                    {' · '}
                    {s.policies[query.data.reclaim_policy as keyof typeof s.policies] ??
                        query.data.reclaim_policy}
                </p>
            </header>
            {!organisationWide && <p className="text-xs text-fg-2">{s.sharedCapacity}</p>}
            {query.isError && <ErrorState error={query.error} />}
            <CompactStats
                columns={3}
                items={[
                    { label: s.capacity, value: query.data.balance?.capacity ?? NONE },
                    { label: s.held, value: query.data.balance?.held ?? NONE },
                    { label: s.available, value: query.data.balance?.available ?? NONE, tone: 'accent' },
                ]}
            />
            {ledger ? (
                <Ledger
                    id={org.id}
                    poolId={poolId}
                    page={location.page}
                    setPage={(page) => change({ page })}
                />
            ) : (
                <Assignments
                    organisation={org}
                    poolId={poolId}
                    page={location.page}
                    setPage={(page) => change({ page })}
                    assign={() => setAssign(true)}
                    notify={notify}
                />
            )}
            {assign && (
                <AssignSeat
                    organisation={org}
                    poolId={poolId}
                    close={() => setAssign(false)}
                    notify={notify}
                />
            )}
        </div>
    );
}

function Assignments({
    organisation: org,
    poolId,
    page,
    setPage,
    assign,
    notify,
}: {
    organisation: Organisation;
    poolId: number;
    page: number;
    setPage: (page: number) => void;
    assign: () => void;
    notify: (text: string) => void;
}) {
    const query = useOrganisationAssignments(org.id, poolId, page);
    const release = useReleaseSeat();
    const write = useWriteAttempt();
    const [target, setTarget] = useState<{ id: number; revision: number } | null>(null);
    const columns: DataTableColumn<OrganisationSeatAssignment>[] = [
        {
            key: 'member',
            header: s.member,
            weight: 2,
            minWidth: 180,
            // A seat reserved against an invitation has no account behind it
            // until somebody accepts, so the column shows the reservation
            // rather than an account number nobody has yet.
            cell: (row) => row.user_name ?? (row.user_id === null ? NONE : s.user(row.user_id)),
        },
        {
            key: 'state',
            header: s.state,
            width: '160px',
            cell: (row) => (
                <span className="grid gap-1">
                    <Badge>{row.state}</Badge>
                    {row.problem_code && <span className="text-xs text-warn-strong">{row.problem_code}</span>}
                </span>
            ),
        },
        {
            key: 'date',
            header: s.assignedAt,
            width: '190px',
            cell: (row) => (row.assigned_at ? `${row.assigned_at} UTC` : NONE),
        },
        {
            key: 'action',
            header: s.action,
            width: '140px',
            cell: (row) =>
                RELEASABLE.includes(row.state) ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setTarget({ id: row.id, revision: org.revision })}
                    >
                        {s.releaseSeat}
                    </Button>
                ) : null,
        },
    ];
    const confirmRelease = () => {
        if (!target) {
            return;
        }

        const input = { id: org.id, assignmentId: target.id, revision: target.revision };
        const attempt = write.capture(input, () => input);

        void release
            .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
            .then(() => {
                write.complete(attempt);
                setTarget(null);
                notify(s.seatReturned);
            })
            .catch(() => undefined);
    };

    return (
        <>
            <ListFrame
                title={s.assignments}
                action={<Button onClick={assign}>{s.assign}</Button>}
                page={page}
                perPage={20}
                total={query.data?.total ?? 0}
                shownCount={query.data?.items.length ?? 0}
                onPageChange={setPage}
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
                note={s.assignHint}
            >
                <DataTable
                    caption={s.assignments}
                    rows={query.data?.items ?? []}
                    rowKey={(row) => row.id}
                    columns={columns}
                    loading={query.isPending}
                    empty={<EmptyState title={s.empty} />}
                />
            </ListFrame>
            <Dialog
                open={target !== null}
                onOpenChange={(open) => {
                    if (!open && !release.isPending) setTarget(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{s.releaseSeatTitle}</DialogTitle>
                    <DialogDescription>{s.releaseSeatHint}</DialogDescription>
                    {release.isError && <ErrorState error={release.error} />}
                    <DialogFooter>
                        <Button variant="ghost" disabled={release.isPending} onClick={() => setTarget(null)}>
                            {s.cancel}
                        </Button>
                        <Button variant="destructive" disabled={release.isPending} onClick={confirmRelease}>
                            {s.releaseSeat}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function AssignSeat({
    organisation: org,
    poolId,
    close,
    notify,
}: {
    organisation: Organisation;
    poolId: number;
    close: () => void;
    notify: (text: string) => void;
}) {
    const [revision] = useState(org.revision);
    const [member, setMember] = useState<number | null>(null);
    const assign = useAssignSeat();
    const write = useWriteAttempt();
    const setDirty = useStudioDraft('org-assign-seat');
    const navigation = useStudioNavigation();
    const closeSafely = () => {
        if (!assign.isPending) {
            navigation.requestLeave(close);
        }
    };
    const confirmAssign = () => {
        if (member === null) {
            return;
        }

        const input = { id: org.id, poolId, userId: member, revision };
        const attempt = write.capture(input, () => input);

        void assign
            .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
            .then((result) => {
                write.complete(attempt);
                setDirty(false);
                notify(result.outcome === 'pending_capacity' ? s.pending : s.assigned);
                close();
            })
            .catch(() => undefined);
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) closeSafely();
            }}
        >
            <DialogContent>
                <DialogTitle>{s.assign}</DialogTitle>
                <DialogDescription>{s.assignHint}</DialogDescription>
                <fieldset disabled={assign.isPending}>
                    <MemberPicker
                        id={org.id}
                        value={member}
                        choose={(id) => {
                            setMember(id);
                            setDirty(true);
                        }}
                    />
                </fieldset>
                {assign.isError && <ErrorState error={assign.error} />}
                <DialogFooter>
                    <Button variant="ghost" disabled={assign.isPending} onClick={closeSafely}>
                        {s.cancel}
                    </Button>
                    <Button disabled={assign.isPending || member === null} onClick={confirmAssign}>
                        {s.assign}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Ledger({
    id,
    poolId,
    page,
    setPage,
}: {
    id: number;
    poolId: number;
    page: number;
    setPage: (page: number) => void;
}) {
    const query = useOrganisationLedger(id, poolId, page);
    const columns: DataTableColumn<OrganisationSeatEvent>[] = [
        { key: 'event', header: s.audit, weight: 2, minWidth: 220, cell: (row) => row.event_key },
        { key: 'delta', header: s.delta, width: '95px', cell: (row) => row.seat_delta },
        { key: 'balance', header: s.balance, width: '120px', cell: (row) => row.balance_after },
        {
            key: 'when',
            header: s.date,
            width: '190px',
            cell: (row) => (row.occurred_at ? `${row.occurred_at} UTC` : NONE),
        },
    ];

    return (
        <ListFrame
            title={s.ledger}
            page={page}
            perPage={20}
            total={query.data?.total ?? 0}
            shownCount={query.data?.items.length ?? 0}
            onPageChange={setPage}
            banner={
                query.isError ? (
                    <ErrorState
                        error={query.error}
                        onRetry={() => {
                            void query.refetch();
                        }}
                    />
                ) : query.data ? (
                    <p
                        className={
                            query.data.balance.reconciled
                                ? 'text-xs text-ok-strong'
                                : 'text-xs text-danger-strong'
                        }
                    >
                        {query.data.balance.reconciled ? s.reconciled : s.mismatch}
                    </p>
                ) : undefined
            }
        >
            <DataTable
                caption={s.ledger}
                rows={query.data?.items ?? []}
                rowKey={(row) => row.id}
                columns={columns}
                loading={query.isPending}
                empty={<EmptyState title={s.empty} />}
            />
        </ListFrame>
    );
}
