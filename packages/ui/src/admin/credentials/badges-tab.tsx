import { Award } from 'lucide-react';
import { CredentialIntro } from './workspace';
import { certificateWorkspaceStrings as ws } from './workspace-strings';
import { useState } from 'react';
import {
    Badge as StatusBadge,
    Button,
    DataTable,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    EmptyState,
    ErrorState,
    type DataTableColumn,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import type { Badge as BadgeRecord, BadgeAward } from '@vidyalms/api-sdk';
import { screenUrl, useAdminConfig } from '../config';
import { useListState } from '../hooks';
import { formatDate } from '../lib';
import { adminStrings } from '../strings';
import { credentialStrings } from '../credential-strings';
import { FilterChips } from '../components/filter-chips';
import { ListFrame } from '../components/list-frame';
import { AdminNotice, ScreenError } from '../components/states';
import { SelectField, TextField } from '../components/field';
import { TextAreaField } from './fields';
import {
    useAwardBadge,
    useBadgeAwards,
    useBadges,
    useCreateBadge,
    useRevokeBadgeAward,
    useUpdateBadge,
} from './api';

/*
 * Badges, the second credential type over the same machinery.
 *
 * They reuse the issuance record's lifecycle columns, the status
 * enumeration, the credential columns and the verification contract. What
 * differs is that the artefact is a baked image rather than a document and
 * the achievement type is Badge or Competency rather than
 * CertificateOfCompletion, so the screen is the certificates screen with
 * different nouns and no template aggregate.
 *
 * With no package defining badges the route answers 200 with an empty list
 * and `available` false. Nothing is gated: a route that failed without a
 * paid package would be a gate by another name, and this screen says so in
 * words rather than showing an error.
 */

const PER_PAGE = 20;
const strings = credentialStrings.badges;

export function BadgesTab() {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const list = useListState('all', { key: 'name', order: 'asc' });
    const [message, setMessage] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<BadgeRecord | null>(null);
    const [showingAwards, setShowingAwards] = useState<BadgeRecord | null>(null);

    const badges = useBadges({
        state: list.filter === 'all' ? undefined : (list.filter as 'draft' | 'published' | 'archived'),
        page: list.page,
        per_page: PER_PAGE,
    });
    const items = badges.data?.items ?? [];
    const total = badges.data?.total ?? 0;
    const available = badges.data?.available ?? true;

    const done = (text: string) => {
        setMessage(text);
        announce(text);
    };

    const columns: DataTableColumn<BadgeRecord>[] = [
        {
            key: 'name',
            header: strings.columns.name,
            weight: 1,
            cell: (row) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    <Award size={22} className="text-accent" aria-hidden="true" />
                    <span className="font-semibold text-fg">{row.name}</span>
                    {row.criteria_locked && <StatusBadge variant="warn">{strings.lockedBadge}</StatusBadge>}
                </span>
            ),
        },
        {
            key: 'type',
            header: strings.columns.type,
            width: '140px',
            cell: (row) => <span className="text-fg-2">{row.achievement_type}</span>,
        },
        {
            key: 'state',
            header: strings.columns.state,
            width: '110px',
            cell: (row) => (
                <StatusBadge variant={row.state === 'published' ? 'ok' : 'neutral'}>
                    {strings.states[row.state] ?? row.state}
                </StatusBadge>
            ),
        },
        {
            key: 'awards',
            header: strings.columns.awards,
            width: '90px',
            cell: (row) => <span className="text-fg-2">{row.award_count}</span>,
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '200px',
            align: 'end',
            cell: (row) => (
                <span className="flex flex-wrap justify-end gap-1.5">
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label={strings.awardsNamed(row.name)}
                        onClick={() => setShowingAwards(row)}
                    >
                        {strings.awards}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={strings.editNamed(row.name)}
                        onClick={() => setEditing(row)}
                    >
                        {strings.edit}
                    </Button>
                </span>
            ),
        },
    ];

    return (
        <>
            <CredentialIntro title={ws.badgesTitle} description={ws.badgesHint} />
            <ListFrame
                title={strings.title}
                meta={badges.isSuccess ? strings.count(total) : undefined}
                action={
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={!available}
                        onClick={() => setCreating(true)}
                    >
                        {strings.create}
                    </Button>
                }
                chips={
                    <FilterChips
                        label={adminStrings.list.filters}
                        value={list.filter}
                        onChange={list.setFilter}
                        chips={[
                            { value: 'all', label: strings.chips.all },
                            { value: 'draft', label: strings.chips.draft },
                            { value: 'published', label: strings.chips.published },
                            { value: 'archived', label: strings.chips.archived },
                        ]}
                    />
                }
                banner={
                    !available ? (
                        <AdminNotice glyph="◇" title={strings.unavailable} />
                    ) : message === null ? undefined : (
                        <AdminNotice
                            glyph="✓"
                            title={message}
                            action={
                                <Button variant="ghost" size="sm" onClick={() => setMessage(null)}>
                                    {adminStrings.courses.dismiss}
                                </Button>
                            }
                        />
                    )
                }
                page={list.page}
                perPage={PER_PAGE}
                total={total}
                shownCount={items.length}
                onPageChange={list.setPage}
            >
                {badges.isError ? (
                    <div className="p-4">
                        <ScreenError
                            error={badges.error}
                            onRetry={() => void badges.refetch()}
                            coursesUrl={screenUrl(config, 'courses')}
                        />
                    </div>
                ) : (
                    <DataTable
                        caption={strings.table}
                        columns={columns}
                        rows={items}
                        rowKey={(row) => row.id}
                        loading={badges.isPending}
                        empty={<EmptyState title={strings.empty} />}
                    />
                )}
            </ListFrame>
            {(creating || editing !== null) && (
                <BadgeDialog
                    open
                    badge={editing}
                    onOpenChange={(next) => {
                        if (!next) {
                            setCreating(false);
                            setEditing(null);
                        }
                    }}
                    onDone={done}
                />
            )}
            {showingAwards !== null && (
                <AwardsDialog
                    open
                    badge={showingAwards}
                    onOpenChange={(next) => {
                        if (!next) {
                            setShowingAwards(null);
                        }
                    }}
                    onDone={done}
                />
            )}
        </>
    );
}

function BadgeDialog({
    open,
    badge,
    onOpenChange,
    onDone,
}: {
    open: boolean;
    badge: BadgeRecord | null;
    onOpenChange: (open: boolean) => void;
    onDone: (message: string) => void;
}) {
    const create = useCreateBadge();
    const update = useUpdateBadge();
    const [name, setName] = useState(badge?.name ?? '');
    const [badgeKey, setBadgeKey] = useState(badge?.badge_key ?? '');
    const [description, setDescription] = useState(badge?.description ?? '');
    const [narrative, setNarrative] = useState(badge?.criteria_narrative ?? '');
    const [achievementType, setAchievementType] = useState(badge?.achievement_type ?? 'Badge');
    const [state, setState] = useState(badge?.state ?? 'draft');

    const pending = create.isPending || update.isPending;
    const error = create.error ?? update.error;

    const submit = async () => {
        const body = {
            name: name.trim(),
            badge_key: badgeKey.trim() === '' ? undefined : badgeKey.trim(),
            description: description.trim() === '' ? null : description.trim(),
            criteria_narrative: narrative.trim() === '' ? null : narrative.trim(),
            achievement_type: achievementType,
            state,
        };
        try {
            if (badge === null) {
                await create.mutateAsync({ body });
            } else {
                await update.mutateAsync({ id: badge.id, body });
            }
        } catch {
            return;
        }
        onDone(strings.saved);
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogTitle>
                    {badge === null ? strings.createTitle : strings.editNamed(badge.name)}
                </DialogTitle>
                {badge?.criteria_locked === true && (
                    <DialogDescription>{strings.lockedHint}</DialogDescription>
                )}
                <div className="mt-3 grid max-h-[60vh] gap-2.5 overflow-y-auto pr-1">
                    <TextField
                        label={strings.fields.name}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <TextField
                        label={strings.fields.key}
                        value={badgeKey}
                        onChange={(event) => setBadgeKey(event.target.value)}
                    />
                    <TextAreaField
                        label={strings.fields.description}
                        rows={3}
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                    />
                    <TextAreaField
                        label={strings.fields.narrative}
                        hint={strings.fields.narrativeHint}
                        rows={3}
                        value={narrative}
                        onChange={(event) => setNarrative(event.target.value)}
                    />
                    <TextField
                        label={strings.fields.achievementType}
                        value={achievementType}
                        onChange={(event) => setAchievementType(event.target.value)}
                    />
                    <SelectField
                        label={strings.fields.state}
                        value={state}
                        onChange={(event) => setState(event.target.value as BadgeRecord['state'])}
                    >
                        {Object.entries(strings.states).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </SelectField>
                </div>
                {error !== null && <ErrorState className="mt-3" error={error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
                        {credentialStrings.form.cancel}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={pending || name.trim() === ''}
                        onClick={() => {
                            void submit();
                        }}
                    >
                        {pending ? strings.saving : strings.save}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AwardsDialog({
    open,
    badge,
    onOpenChange,
    onDone,
}: {
    open: boolean;
    badge: BadgeRecord;
    onOpenChange: (open: boolean) => void;
    onDone: (message: string) => void;
}) {
    const config = useAdminConfig();
    const awards = useBadgeAwards(open ? badge.id : null);
    const grant = useAwardBadge();
    const revoke = useRevokeBadgeAward();
    const [userId, setUserId] = useState('');
    const [revoking, setRevoking] = useState<BadgeAward | null>(null);

    const columns: DataTableColumn<BadgeAward>[] = [
        {
            key: 'code',
            header: strings.awardColumns.code,
            width: '160px',
            cell: (row) =>
                row.verification_url === null ? (
                    <code className="font-mono text-[11px]">{row.code}</code>
                ) : (
                    <a
                        className="font-mono text-[11px] text-accent underline-offset-2 hover:underline"
                        href={row.verification_url}
                    >
                        {row.code}
                    </a>
                ),
        },
        {
            key: 'learner',
            header: strings.awardColumns.learner,
            weight: 1,
            cell: (row) => <span className="text-fg-2">#{row.user_id}</span>,
        },
        {
            key: 'status',
            header: strings.awardColumns.status,
            width: '120px',
            cell: (row) => (
                <StatusBadge variant={row.status === 'revoked' ? 'danger' : 'neutral'}>
                    {credentialStrings.statuses[row.status] ?? row.status}
                </StatusBadge>
            ),
        },
        {
            key: 'issued',
            header: strings.awardColumns.issued,
            width: '110px',
            cell: (row) => <span className="text-fg-2">{formatDate(row.issued_at, config.locale)}</span>,
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '110px',
            align: 'end',
            cell: (row) =>
                row.status === 'revoked' ? null : (
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={strings.revokeAwardNamed(row.code)}
                        onClick={() => setRevoking(row)}
                    >
                        {strings.revokeAward}
                    </Button>
                ),
        },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogTitle>{strings.awardsOf(badge.name)}</DialogTitle>
                <div className="mt-3 grid max-h-[60vh] gap-3 overflow-y-auto pr-1">
                    <div className="flex flex-wrap items-end gap-2">
                        <TextField
                            fieldClassName="min-w-[160px] flex-1"
                            label={strings.awardLearner}
                            inputMode="numeric"
                            value={userId}
                            onChange={(event) => setUserId(event.target.value)}
                        />
                        <Button
                            variant="primary"
                            size="sm"
                            disabled={grant.isPending || userId.trim() === ''}
                            onClick={() => {
                                void grant
                                    .mutateAsync({ badgeId: badge.id, body: { user_id: Number(userId) } })
                                    .then(() => {
                                        setUserId('');
                                        onDone(strings.awarded);
                                    })
                                    .catch(() => undefined);
                            }}
                        >
                            {grant.isPending ? strings.awardSubmitting : strings.award}
                        </Button>
                    </div>
                    {grant.isError && <ErrorState error={grant.error} />}
                    {awards.isError ? (
                        <ScreenError error={awards.error} onRetry={() => void awards.refetch()} />
                    ) : (
                        <DataTable
                            caption={strings.awardsOf(badge.name)}
                            columns={columns}
                            rows={awards.data?.items ?? []}
                            rowKey={(row) => row.id}
                            loading={awards.isPending}
                            empty={<EmptyState title={strings.awardsEmpty} />}
                        />
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
                        {credentialStrings.form.close}
                    </Button>
                </DialogFooter>
                {revoking !== null && (
                    <Dialog
                        open
                        onOpenChange={(next) => {
                            if (!next) {
                                setRevoking(null);
                            }
                        }}
                    >
                        <DialogContent>
                            <DialogTitle>{strings.revokeTitle}</DialogTitle>
                            <DialogDescription>{strings.revokeBody(revoking.code)}</DialogDescription>
                            {revoke.isError && <ErrorState className="mt-3" error={revoke.error} />}
                            <DialogFooter>
                                <Button variant="outline" size="sm" onClick={() => setRevoking(null)}>
                                    {strings.revokeCancel}
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    disabled={revoke.isPending}
                                    onClick={() => {
                                        void revoke
                                            .mutateAsync({ awardId: revoking.id, reason: 'issued_in_error' })
                                            .then(() => {
                                                onDone(strings.revoked(revoking.code));
                                                setRevoking(null);
                                            })
                                            .catch(() => undefined);
                                    }}
                                >
                                    {revoke.isPending ? strings.revokeSubmitting : strings.revokeSubmit}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}
            </DialogContent>
        </Dialog>
    );
}
