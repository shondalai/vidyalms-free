import { useState } from 'react';
import { OrgAvatar, OrgIntro, OrgSegments } from './workspace';
import { orgWorkspaceStrings as ws } from './workspace-strings';
import type {
    Organisation,
    OrganisationCourseRelease,
    OrganisationLearningGrant,
    OrganisationRolePage,
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
    ProgressBar,
    type DataTableColumn,
} from '@/shared/components';
import { ListFrame } from '../components/list-frame';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import { useStudioDraft, useStudioNavigation } from '../credentials/studio-drafts';
import { TextField } from '../components/field';
import {
    useGrantOrganisationLearning,
    useOrganisationLearning,
    useOrganisationReleases,
    useReleaseCourse,
    useRevokeOrganisationLearning,
} from './learning-api';
import { MemberPicker, ReleasedCoursePicker, SiteCoursePicker } from './pickers';
import { organisationLearningStrings as s } from './learning-strings';
import type { OrganisationLocation } from './routes';

export function OrganisationLearning({
    organisation,
    roles,
    location,
    change,
    notify,
}: {
    organisation: Organisation;
    roles: OrganisationRolePage;
    location: OrganisationLocation;
    change: (changes: Partial<OrganisationLocation>) => void;
    notify: (text: string) => void;
}) {
    const mode = location.state === 'access' ? 'access' : 'releases';
    const [create, setCreate] = useState<'release' | 'grant' | null>(null);
    return (
        <div className="grid gap-4">
            <OrgIntro title={ws.courseAccess} description={ws.learningIntro} />
            <OrgSegments
                label={s.view}
                value={mode}
                onChange={(state) => change({ state, page: 1 })}
                options={[
                    { value: 'releases', label: s.releaseView },
                    { value: 'access', label: s.accessView },
                ]}
            />
            {mode === 'releases' ? (
                <Releases
                    organisation={organisation}
                    page={location.page}
                    setPage={(page) => change({ page })}
                    canRelease={roles.access.site_manager}
                    create={() => setCreate('release')}
                    notify={notify}
                />
            ) : (
                <Access
                    organisation={organisation}
                    page={location.page}
                    setPage={(page) => change({ page })}
                    create={() => setCreate('grant')}
                    notify={notify}
                />
            )}
            {create && (
                <LearningForm
                    organisation={organisation}
                    mode={create}
                    close={() => setCreate(null)}
                    notify={notify}
                />
            )}
        </div>
    );
}
function Releases({
    organisation: org,
    page,
    setPage,
    canRelease,
    create,
    notify,
}: {
    organisation: Organisation;
    page: number;
    setPage: (page: number) => void;
    canRelease: boolean;
    create: () => void;
    notify: (text: string) => void;
}) {
    const query = useOrganisationReleases(org.id, page);
    const save = useReleaseCourse();
    const write = useWriteAttempt();
    const [target, setTarget] = useState<{ row: OrganisationCourseRelease; revision: number } | null>(null);
    const columns: DataTableColumn<OrganisationCourseRelease>[] = [
        {
            key: 'course',
            header: s.course,
            weight: 2,
            minWidth: 220,
            cell: (row) => (
                <span className="font-semibold text-fg">{row.course_title ?? s.courseId(row.course_id)}</span>
            ),
        },
        {
            key: 'state',
            header: s.state,
            width: '140px',
            cell: (row) => (
                <Badge variant={row.state === 'active' ? 'accent' : 'neutral'}>
                    {row.state === 'active' ? s.active : s.withdrawn}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: s.action,
            width: '180px',
            cell: (row) =>
                canRelease ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setTarget({ row, revision: org.revision })}
                    >
                        {row.state === 'active' ? s.withdraw : s.restore}
                    </Button>
                ) : null,
        },
    ];
    return (
        <>
            <ListFrame
                title={s.releases}
                action={canRelease ? <Button onClick={create}>{s.release}</Button> : undefined}
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
                note={s.releaseHint}
            >
                <DataTable
                    caption={s.releases}
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
                    if (!open && !save.isPending) setTarget(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{target?.row.state === 'active' ? s.withdrawTitle : s.restore}</DialogTitle>
                    <DialogDescription>
                        {target?.row.state === 'active' ? s.withdrawHint : s.releaseHint}
                    </DialogDescription>
                    {save.isError && <ErrorState error={save.error} />}
                    <DialogFooter>
                        <Button variant="ghost" disabled={save.isPending} onClick={() => setTarget(null)}>
                            {s.cancel}
                        </Button>
                        <Button
                            disabled={save.isPending}
                            onClick={() => {
                                if (!target) return;
                                const body = {
                                    expected_revision: target.revision,
                                    course_id: target.row.course_id,
                                    state:
                                        target.row.state === 'active'
                                            ? ('withdrawn' as const)
                                            : ('active' as const),
                                };
                                const attempt = write.capture(body, () => ({ id: org.id, body }));
                                void save
                                    .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
                                    .then(() => {
                                        write.complete(attempt);
                                        setTarget(null);
                                        notify(s.releaseSaved);
                                    })
                                    .catch(() => undefined);
                            }}
                        >
                            {s.save}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
function Access({
    organisation: org,
    page,
    setPage,
    create,
    notify,
}: {
    organisation: Organisation;
    page: number;
    setPage: (page: number) => void;
    create: () => void;
    notify: (text: string) => void;
}) {
    const query = useOrganisationLearning(org.id, page);
    const revoke = useRevokeOrganisationLearning();
    const write = useWriteAttempt();
    const [target, setTarget] = useState<{ row: OrganisationLearningGrant; revision: number } | null>(null);
    const columns: DataTableColumn<OrganisationLearningGrant>[] = [
        {
            key: 'member',
            header: s.member,
            weight: 1,
            minWidth: 160,
            cell: (row) => (
                <div className="vy-org-name-cell">
                    <OrgAvatar name={row.user_name ?? s.user(row.user_id)} id={row.user_id} />
                    <strong>{row.user_name ?? s.user(row.user_id)}</strong>
                </div>
            ),
        },
        { key: 'course', header: s.course, weight: 2, minWidth: 200, cell: (row) => row.course_title },
        {
            key: 'source',
            header: s.source,
            width: '160px',
            cell: (row) => (row.source === 'seat' ? s.seat : s.direct),
        },
        { key: 'status', header: s.grantState, width: '155px', cell: (row) => <Badge>{row.status}</Badge> },
        {
            key: 'progress',
            header: s.progress,
            width: '140px',
            cell: (row) =>
                row.progress_percent === null ? (
                    '—'
                ) : (
                    <ProgressBar value={row.progress_percent} label={row.course_title} showValue />
                ),
        },
        {
            key: 'actions',
            header: s.action,
            width: '150px',
            cell: (row) =>
                row.source === 'org' && row.status === 'active' ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setTarget({ row, revision: org.revision })}
                    >
                        {s.revoke}
                    </Button>
                ) : null,
        },
    ];
    return (
        <>
            <ListFrame
                title={s.enrolments}
                action={<Button onClick={create}>{s.grant}</Button>}
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
                note={`${s.grantHint} ${s.effectiveAccess}`}
            >
                <DataTable
                    caption={s.enrolments}
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
                    if (!open && !revoke.isPending) setTarget(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{s.revokeTitle}</DialogTitle>
                    <DialogDescription>{s.revokeHint}</DialogDescription>
                    {revoke.isError && <ErrorState error={revoke.error} />}
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setTarget(null)} disabled={revoke.isPending}>
                            {s.cancel}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={revoke.isPending}
                            onClick={() => {
                                if (!target) return;
                                const input = {
                                    id: org.id,
                                    entitlementId: target.row.id,
                                    revision: target.revision,
                                };
                                const attempt = write.capture(input, () => input);
                                void revoke
                                    .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
                                    .then(() => {
                                        write.complete(attempt);
                                        setTarget(null);
                                        notify(s.revoked);
                                    })
                                    .catch(() => undefined);
                            }}
                        >
                            {s.revoke}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
function LearningForm({
    organisation: org,
    mode,
    close,
    notify,
}: {
    organisation: Organisation;
    mode: 'release' | 'grant';
    close: () => void;
    notify: (text: string) => void;
}) {
    const [revision] = useState(org.revision);
    const [course, setCourse] = useState<number | null>(null);
    const [member, setMember] = useState<number | null>(null);
    const [expiry, setExpiry] = useState('');
    const setDirty = useStudioDraft('org-learning-form');
    const navigation = useStudioNavigation();
    const write = useWriteAttempt();
    const release = useReleaseCourse();
    const grant = useGrantOrganisationLearning();
    const pending = release.isPending || grant.isPending;
    const closeSafely = () => {
        if (!pending) navigation.requestLeave(close);
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) closeSafely();
            }}
        >
            <DialogContent>
                <DialogTitle>{mode === 'release' ? s.release : s.grant}</DialogTitle>
                <DialogDescription>{mode === 'release' ? s.releaseHint : s.grantHint}</DialogDescription>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (course === null || (mode === 'grant' && member === null)) return;
                        const intent = { course, member, expiry };
                        const attempt = write.capture(intent, () => ({ revision, ...intent }));
                        const request =
                            mode === 'release'
                                ? release.mutateAsync({
                                      id: org.id,
                                      body: {
                                          expected_revision: attempt.input.revision,
                                          course_id: attempt.input.course!,
                                      },
                                      idempotencyKey: attempt.idempotencyKey,
                                  })
                                : grant.mutateAsync({
                                      id: org.id,
                                      body: {
                                          expected_revision: attempt.input.revision,
                                          course_id: attempt.input.course!,
                                          user_id: attempt.input.member!,
                                          expires_at: attempt.input.expiry
                                              ? `${attempt.input.expiry.replace('T', ' ')}:00`
                                              : null,
                                      },
                                      idempotencyKey: attempt.idempotencyKey,
                                  });
                        void request
                            .then(() => {
                                write.complete(attempt);
                                setDirty(false);
                                notify(mode === 'release' ? s.releaseSaved : s.grantSaved);
                                close();
                            })
                            .catch(() => undefined);
                    }}
                >
                    <fieldset className="grid gap-3" disabled={pending}>
                        {mode === 'release' ? (
                            <SiteCoursePicker
                                value={course}
                                choose={(id) => {
                                    setCourse(id);
                                    setDirty(true);
                                }}
                            />
                        ) : (
                            <>
                                <MemberPicker
                                    id={org.id}
                                    value={member}
                                    choose={(id) => {
                                        setMember(id);
                                        setDirty(true);
                                    }}
                                />
                                <ReleasedCoursePicker
                                    id={org.id}
                                    value={course}
                                    choose={(id) => {
                                        setCourse(id);
                                        setDirty(true);
                                    }}
                                />
                                <TextField
                                    label={s.expiry}
                                    type="datetime-local"
                                    value={expiry}
                                    onChange={(event) => {
                                        setExpiry(event.target.value);
                                        setDirty(true);
                                    }}
                                />
                            </>
                        )}
                    </fieldset>
                    {(release.isError || grant.isError) && (
                        <ErrorState error={release.error ?? grant.error} />
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" disabled={pending} onClick={closeSafely}>
                            {s.cancel}
                        </Button>
                        <Button
                            type="submit"
                            disabled={pending || course === null || (mode === 'grant' && member === null)}
                        >
                            {mode === 'release' ? s.release : s.grant}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
