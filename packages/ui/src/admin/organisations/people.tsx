import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { OrgAvatar, OrgIntro, OrgSegments, orgDate } from './workspace';
import { orgWorkspaceStrings as ws } from './workspace-strings';
import type {
    Organisation,
    OrganisationInvitation,
    OrganisationMember,
    OrganisationRolePage,
    UserAccount,
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
    type DataTableColumn,
} from '@/shared/components';
import { SelectField, TextField } from '../components/field';
import { ListFrame } from '../components/list-frame';
import { FilterSelect } from '../components/filter-toolbar';
import { UserPicker } from '../components/user-picker';
import { useAdminConfig } from '../config';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import {
    useStudioDraft as useOrganisationDraft,
    useStudioNavigation as useOrganisationNavigation,
} from '../credentials/studio-drafts';
import {
    useAddOrganisationMember,
    useInviteOrganisationMember,
    useOrganisationInvitations,
    useOrganisationMembers,
    useRevokeOrganisationInvitation,
    useUpdateOrganisationMember,
} from './api';
import { organisationStrings as strings } from './strings';
import type { OrganisationLocation } from './routes';
import { OrganisationUnitLabel, OrganisationUnitPicker } from './units';
import { unitStrings } from './unit-strings';
import { RelationshipErasure, relationshipErasureStrings } from './relationship-erasure';
import { OrganisationRosterImport } from './roster-import';

export interface PeopleProps {
    organisation: Organisation;
    roles: OrganisationRolePage;
    location: OrganisationLocation;
    change: (changes: Partial<OrganisationLocation>) => void;
    notify: (text: string) => void;
}

export function OrganisationPeople({ organisation, roles, location, change, notify }: PeopleProps) {
    const [adding, setAdding] = useState(false);
    const deliveryAvailable =
        useAdminConfig().routes?.includes('/manage/organisations/{id}/invitations') === true;
    const canPeople = roles.access.actions.includes('people');
    const unitScoped = roles.access.unit_id !== null;
    const canInvitations = canPeople && !unitScoped && deliveryAvailable;
    const canAdd = canPeople && !unitScoped && (deliveryAvailable || roles.access.site_manager);
    const memberView = location.people === 'members' || !canInvitations;
    return (
        <div className="grid gap-4">
            <OrgIntro
                title={strings.people}
                description={ws.peopleIntro}
                action={
                    canAdd ? <Button onClick={() => setAdding(true)}>{strings.addPerson}</Button> : undefined
                }
            />
            <OrgSegments
                label={strings.people}
                value={memberView ? 'members' : 'invitations'}
                options={[
                    { value: 'members', label: strings.members },
                    ...(canInvitations ? [{ value: 'invitations', label: strings.invitations }] : []),
                ]}
                onChange={(people) =>
                    change({ people: people as 'members' | 'invitations', page: 1, state: '' })
                }
            />
            {canPeople && !unitScoped && !deliveryAvailable && (
                <p role="status" className="rounded border border-border bg-warn-soft p-3 text-xs text-fg">
                    {unitStrings.deliveryUnavailable}
                </p>
            )}
            {unitScoped && <p className="text-xs text-fg-2">{unitStrings.membersOnly}</p>}
            {canPeople && !unitScoped && <OrganisationRosterImport key={organisation.id} organisationId={organisation.id} />}
            {memberView ? (
                <Members
                    organisation={organisation}
                    roles={roles}
                    location={location}
                    change={change}
                    notify={notify}
                />
            ) : (
                <Invitations
                    organisation={organisation}
                    roles={roles}
                    location={location}
                    change={change}
                    notify={notify}
                />
            )}
            {adding && canAdd && (
                <AddPerson
                    organisation={organisation}
                    roles={roles}
                    canInvite={canInvitations}
                    close={() => setAdding(false)}
                    notify={notify}
                />
            )}
        </div>
    );
}

function Members({ organisation, roles, location, change, notify }: PeopleProps) {
    const filter = ['active', 'suspended', 'left'].includes(location.state)
        ? (location.state as OrganisationMember['state'])
        : undefined;
    const query = useOrganisationMembers(organisation.id, location.page, filter);
    const [editing, setEditing] = useState<OrganisationMember | null>(null);
    const [erasing, setErasing] = useState<OrganisationMember | null>(null);
    const columns: DataTableColumn<OrganisationMember>[] = [
        {
            key: 'name',
            header: strings.members,
            weight: 2,
            minWidth: 200,
            cell: (row) => (
                <div className="vy-org-name-cell">
                    <OrgAvatar name={row.user_name ?? strings.accountRemoved} id={row.user_id} />
                    <div>
                        <strong>{row.user_name ?? strings.accountRemoved}</strong>
                        <p>{strings.userId(row.user_id)}</p>
                    </div>
                </div>
            ),
        },
        {
            key: 'role',
            header: strings.roleColumn,
            weight: 1,
            minWidth: 150,
            cell: (row) => (
                <span>
                    {row.role_keys
                        .map((key) => roles.items.find((role) => role.key === key)?.label ?? key)
                        .join(', ')}
                </span>
            ),
        },
        {
            key: 'unit',
            header: unitStrings.membership,
            weight: 1,
            minWidth: 160,
            cell: (row) => (
                <OrganisationUnitLabel organisationId={organisation.id} unitId={row.org_unit_id ?? null} />
            ),
        },
        {
            key: 'state',
            header: strings.state,
            width: '130px',
            cell: (row) => (
                <Badge variant={row.state === 'active' ? 'accent' : 'neutral'}>
                    {strings.stateLabels[row.state]}
                </Badge>
            ),
        },
        {
            key: 'joined',
            header: strings.joined,
            width: '165px',
            cell: (row) => <span className="text-xs text-fg-2">{orgDate(row.joined_at)}</span>,
        },
        {
            key: 'actions',
            header: strings.actions,
            width: '190px',
            cell: (row) => (
                <div className="flex flex-wrap gap-2">
                    {roles.access.actions.includes('people') && row.state !== 'left' && (
                        <Button variant="outline" size="sm" onClick={() => setEditing(row)}>
                            {strings.editMember}
                        </Button>
                    )}
                    {roles.access.unit_id === null &&
                        roles.access.actions.includes('settings') &&
                        row.user_id > 0 &&
                        row.directory_managed !== 1 && (
                            <Button variant="ghost" size="sm" onClick={() => setErasing(row)}>
                                {relationshipErasureStrings.action}
                            </Button>
                        )}
                </div>
            ),
        },
    ];
    return (
        <>
            <ListFrame
                title={strings.members}
                page={location.page}
                perPage={20}
                total={query.data?.total ?? 0}
                shownCount={query.data?.items.length ?? 0}
                onPageChange={(page) => change({ page })}
                chips={
                    <FilterSelect
                        label={strings.memberState}
                        value={filter ?? ''}
                        onValueChange={(state) => change({ state, page: 1 })}
                        options={[
                            { value: '', label: strings.allStates },
                            ...Object.entries(strings.stateLabels).map(([value, label]) => ({
                                value,
                                label,
                            })),
                        ]}
                    />
                }
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
            >
                <DataTable
                    caption={strings.members}
                    rows={query.data?.items ?? []}
                    rowKey={(row) => row.id}
                    columns={columns}
                    loading={query.isPending}
                    empty={<EmptyState title={strings.memberEmpty} />}
                />
            </ListFrame>
            {editing !== null && (
                <EditMember
                    organisation={organisation}
                    member={editing}
                    roles={roles}
                    close={() => setEditing(null)}
                    notify={notify}
                />
            )}
            {erasing !== null && (
                <RelationshipErasure
                    organisation={organisation}
                    member={erasing}
                    close={() => setErasing(null)}
                    openCleanup={() => {
                        setErasing(null);
                        change({ tab: 'settings', page: 1, state: 'lifecycle', search: '' });
                    }}
                />
            )}
        </>
    );
}

function AddPerson({
    organisation,
    roles,
    canInvite,
    close,
    notify,
}: {
    organisation: Organisation;
    roles: OrganisationRolePage;
    canInvite: boolean;
    close: () => void;
    notify: (text: string) => void;
}) {
    const invite = useInviteOrganisationMember();
    const add = useAddOrganisationMember();
    const write = useWriteAttempt();
    const navigation = useOrganisationNavigation();
    const setDirty = useOrganisationDraft(`org-${organisation.id}-add-person`);
    const [revision] = useState(organisation.revision);
    const [requestedMode, setMode] = useState<'invite' | 'existing'>(canInvite ? 'invite' : 'existing');
    const [email, setEmail] = useState('');
    const mode = canInvite ? requestedMode : 'existing';
    const [account, setAccount] = useState<UserAccount | null>(null);
    const choices = roles.items.filter((role) => role.assignable);
    const [role, setRole] = useState(
        choices.find((item) => item.key === 'org_member')?.key ?? choices[0]?.key ?? '',
    );
    const [unitId, setUnitId] = useState<number | null>(null);
    const [roleUnitId, setRoleUnitId] = useState<number | null>(null);
    const canScopeRole = roles.access.site_manager || roles.access.actions.includes('roles');
    const busy = invite.isPending || add.isPending;
    const submit = async () => {
        try {
            if (mode === 'invite' && canInvite) {
                const attempt = write.capture({ mode, email, role }, () => ({
                    id: organisation.id,
                    body: { expected_revision: revision, email: email.trim(), role_key: role },
                }));
                const result = await invite.mutateAsync({
                    ...attempt.input,
                    idempotencyKey: attempt.idempotencyKey,
                });
                write.complete(attempt);
                setDirty(false);
                notify(result.outcome === 'already_member' ? strings.alreadyMember : strings.inviteQueued);
                close();
            } else if (account !== null && roles.access.site_manager) {
                const attempt = write.capture({ mode, user: account.id, role, unitId, roleUnitId }, () => ({
                    id: organisation.id,
                    body: {
                        expected_revision: revision,
                        user_id: account.id,
                        role_key: role,
                        org_unit_id: unitId,
                        role_unit_id: roleUnitId,
                    },
                }));
                await add.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey });
                write.complete(attempt);
                setDirty(false);
                notify(strings.memberAdded);
                close();
            }
        } catch {
            /* Preserve the attempted body and revision for an unchanged retry. */
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) navigation.requestLeave(close);
            }}
        >
            <DialogContent>
                <DialogTitle>{canInvite ? strings.inviteTitle : strings.addExisting}</DialogTitle>
                <DialogDescription>{strings.inviteIntro}</DialogDescription>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <fieldset disabled={busy} className="grid gap-4">
                        {roles.access.site_manager && canInvite && (
                            <SelectField
                                label={strings.addPerson}
                                value={mode}
                                onChange={(event) => {
                                    setMode(event.target.value as 'invite' | 'existing');
                                    setDirty(true);
                                }}
                            >
                                <option value="invite">{strings.invite}</option>
                                <option value="existing">{strings.addExisting}</option>
                            </SelectField>
                        )}
                        {mode === 'invite' ? (
                            <TextField
                                label={strings.inviteEmail}
                                type="email"
                                required
                                maxLength={254}
                                autoComplete="off"
                                value={email}
                                onChange={(event) => {
                                    setEmail(event.target.value);
                                    setDirty(true);
                                }}
                            />
                        ) : (
                            <UserPicker
                                label={strings.existingAccount}
                                placeholder={strings.accountSearch}
                                hint={strings.existingHint}
                                value={account}
                                onChange={(value) => {
                                    setAccount(value);
                                    setDirty(true);
                                }}
                            />
                        )}
                        <SelectField
                            label={strings.role}
                            value={role}
                            onChange={(event) => {
                                setRole(event.target.value);
                                if (['org_owner', 'org_member'].includes(event.target.value))
                                    setRoleUnitId(null);
                                setDirty(true);
                            }}
                        >
                            {choices.map((item) => (
                                <option key={item.key} value={item.key}>
                                    {item.label}
                                </option>
                            ))}
                        </SelectField>
                        {mode === 'existing' && (
                            <>
                                <OrganisationUnitPicker
                                    organisationId={organisation.id}
                                    label={unitStrings.membership}
                                    hint={unitStrings.membershipHint}
                                    value={unitId}
                                    onChange={(id) => {
                                        setUnitId(id);
                                        setDirty(true);
                                    }}
                                />
                                {canScopeRole && !['org_owner', 'org_member'].includes(role) && (
                                    <OrganisationUnitPicker
                                        organisationId={organisation.id}
                                        label={unitStrings.roleScope}
                                        hint={unitStrings.roleScopeHint}
                                        nullLabel={unitStrings.organizationWide}
                                        value={roleUnitId}
                                        onChange={(id) => {
                                            setRoleUnitId(id);
                                            setDirty(true);
                                        }}
                                    />
                                )}
                            </>
                        )}
                    </fieldset>
                    {(invite.isError || add.isError) && <ErrorState error={invite.error ?? add.error} />}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => navigation.requestLeave(close)}
                        >
                            {strings.cancel}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                busy ||
                                role === '' ||
                                (mode === 'invite' ? email.trim() === '' : account === null)
                            }
                        >
                            {busy
                                ? strings.saving
                                : mode === 'invite'
                                  ? strings.inviteAction
                                  : strings.addExisting}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditMember({
    organisation,
    member,
    roles,
    close,
    notify,
}: {
    organisation: Organisation;
    member: OrganisationMember;
    roles: OrganisationRolePage;
    close: () => void;
    notify: (text: string) => void;
}) {
    const update = useUpdateOrganisationMember();
    const write = useWriteAttempt();
    const navigation = useOrganisationNavigation();
    const setDirty = useOrganisationDraft(`org-member-${member.id}`);
    const [revision] = useState(organisation.revision);
    const [state, setState] = useState(member.state);
    const [role, setRole] = useState(member.role_keys[0] ?? '');
    const originalUnitId = member.org_unit_id ?? null;
    const originalRoleUnitId = member.role_unit_id ?? null;
    const [unitId, setUnitId] = useState(originalUnitId);
    const [roleUnitId, setRoleUnitId] = useState(originalRoleUnitId);
    const canScopeRole = roles.access.site_manager || roles.access.actions.includes('roles');
    const dirty =
        state !== member.state ||
        role !== member.role_keys[0] ||
        unitId !== originalUnitId ||
        roleUnitId !== originalRoleUnitId;
    const choices = roles.items.filter((item) => item.assignable);
    const submit = async () => {
        const body = {
            expected_revision: revision,
            ...(state !== member.state ? { state } : {}),
            ...(unitId !== originalUnitId ? { org_unit_id: unitId } : {}),
            ...(role !== member.role_keys[0] || roleUnitId !== originalRoleUnitId
                ? { role_key: role, role_unit_id: roleUnitId }
                : {}),
        };
        const attempt = write.capture({ memberId: member.id, state, role, unitId, roleUnitId }, () => ({
            id: organisation.id,
            memberId: member.id,
            body,
        }));
        try {
            await update.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey });
            write.complete(attempt);
            setDirty(false);
            notify(strings.memberSaved);
            close();
        } catch {
            /* Keep both form and retry identity. */
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !update.isPending) navigation.requestLeave(close);
            }}
        >
            <DialogContent>
                <DialogTitle>{strings.editMember}</DialogTitle>
                <DialogDescription>
                    {member.user_name ?? strings.userId(member.user_id)}. {strings.memberHelp}
                </DialogDescription>
                {member.directory_managed === 1 && (
                    <Badge variant="neutral">{strings.directoryManaged}</Badge>
                )}
                <fieldset
                    disabled={update.isPending || member.directory_managed === 1}
                    className="my-4 grid gap-4"
                >
                    <SelectField
                        label={strings.memberState}
                        value={state}
                        onChange={(event) => {
                            setState(event.target.value as OrganisationMember['state']);
                            setDirty(true);
                        }}
                    >
                        {Object.entries(strings.stateLabels).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField
                        label={strings.role}
                        value={role}
                        onChange={(event) => {
                            setRole(event.target.value);
                            if (['org_owner', 'org_member'].includes(event.target.value)) setRoleUnitId(null);
                            setDirty(true);
                        }}
                    >
                        {!choices.some((item) => item.key === role) && (
                            <option value={role} disabled>
                                {roles.items.find((item) => item.key === role)?.label ?? role}
                            </option>
                        )}
                        {choices.map((item) => (
                            <option key={item.key} value={item.key}>
                                {item.label}
                            </option>
                        ))}
                    </SelectField>
                    <OrganisationUnitPicker
                        organisationId={organisation.id}
                        label={unitStrings.membership}
                        hint={unitStrings.membershipHint}
                        value={unitId}
                        allowNull={roles.access.unit_id === null}
                        onChange={(id) => {
                            setUnitId(id);
                            setDirty(true);
                        }}
                    />
                    {canScopeRole && !['org_owner', 'org_member'].includes(role) ? (
                        <OrganisationUnitPicker
                            organisationId={organisation.id}
                            label={unitStrings.roleScope}
                            hint={unitStrings.roleScopeHint}
                            nullLabel={unitStrings.organizationWide}
                            value={roleUnitId}
                            onChange={(id) => {
                                setRoleUnitId(id);
                                setDirty(true);
                            }}
                        />
                    ) : (
                        (roleUnitId !== null || role === 'org_owner') && (
                            <div
                                role="group"
                                aria-label={unitStrings.roleScope}
                                className="grid gap-1 text-xs"
                            >
                                <span className="font-semibold text-fg">{unitStrings.roleScope}</span>
                                {roleUnitId === null ? (
                                    <span className="text-fg-2">{unitStrings.organizationWide}</span>
                                ) : (
                                    <OrganisationUnitLabel
                                        organisationId={organisation.id}
                                        unitId={roleUnitId}
                                    />
                                )}
                            </div>
                        )
                    )}
                </fieldset>
                {update.isError && <ErrorState error={update.error} />}
                <DialogFooter>
                    <Button
                        variant="ghost"
                        disabled={update.isPending}
                        onClick={() => navigation.requestLeave(close)}
                    >
                        {strings.cancel}
                    </Button>
                    <Button
                        variant={state === 'active' ? 'primary' : 'destructive'}
                        disabled={update.isPending || member.directory_managed === 1 || !dirty}
                        onClick={() => {
                            void submit();
                        }}
                    >
                        {strings.save}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Invitations({ organisation, roles, location, change, notify }: PeopleProps) {
    const filter = Object.keys(strings.inviteStatus).includes(location.state)
        ? (location.state as OrganisationInvitation['state'])
        : undefined;
    const query = useOrganisationInvitations(organisation.id, location.page, filter);
    const revoke = useRevokeOrganisationInvitation();
    const write = useWriteAttempt();
    const [target, setTarget] = useState<{ invitation: OrganisationInvitation; revision: number } | null>(
        null,
    );
    const columns: DataTableColumn<OrganisationInvitation>[] = [
        {
            key: 'email',
            header: strings.email,
            weight: 2,
            minWidth: 220,
            cell: (row) => <span className="font-semibold text-fg">{row.email}</span>,
        },
        {
            key: 'role',
            header: strings.roleColumn,
            weight: 1,
            minWidth: 140,
            cell: (row) => roles.items.find((role) => role.key === row.role_key)?.label ?? row.role_key,
        },
        {
            key: 'state',
            header: strings.state,
            width: '120px',
            cell: (row) => (
                <Badge variant={row.state === 'pending' ? 'warn' : 'neutral'}>
                    {strings.inviteStatus[row.state]}
                </Badge>
            ),
        },
        {
            key: 'expires',
            header: strings.expires,
            width: '165px',
            cell: (row) => <span className="text-xs text-fg-2">{row.expires_at} {uiText("vidyalms.ui.de92d152d78269b7", " UTC")}</span>,
        },
        {
            key: 'actions',
            header: strings.actions,
            width: '170px',
            cell: (row) =>
                roles.access.actions.includes('people') && row.state === 'pending' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setTarget({ invitation: row, revision: organisation.revision })}
                    >
                        {strings.removeInvite}
                    </Button>
                ) : null,
        },
    ];
    return (
        <>
            <ListFrame
                title={strings.invitations}
                page={location.page}
                perPage={20}
                total={query.data?.total ?? 0}
                shownCount={query.data?.items.length ?? 0}
                onPageChange={(page) => change({ page })}
                chips={
                    <FilterSelect
                        label={strings.state}
                        value={filter ?? ''}
                        onValueChange={(state) => change({ state, page: 1 })}
                        options={[
                            { value: '', label: strings.allStates },
                            ...Object.entries(strings.inviteStatus).map(([value, label]) => ({
                                value,
                                label,
                            })),
                        ]}
                    />
                }
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
            >
                <DataTable
                    caption={strings.invitations}
                    rows={query.data?.items ?? []}
                    rowKey={(row) => row.id}
                    columns={columns}
                    loading={query.isPending}
                    empty={<EmptyState title={strings.invitationEmpty} />}
                />
            </ListFrame>
            <Dialog
                open={target !== null}
                onOpenChange={(open) => {
                    if (!open && !revoke.isPending) setTarget(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{strings.revokeTitle}</DialogTitle>
                    <DialogDescription>
                        {target?.invitation.email}. {strings.revokeBody}
                    </DialogDescription>
                    {revoke.isError && <ErrorState error={revoke.error} />}
                    <DialogFooter>
                        <Button variant="ghost" disabled={revoke.isPending} onClick={() => setTarget(null)}>
                            {strings.cancel}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={revoke.isPending}
                            onClick={() => {
                                if (target === null) return;
                                const attempt = write.capture(
                                    { invitationId: target.invitation.id, action: 'revoke' },
                                    () => ({
                                        id: organisation.id,
                                        invitationId: target.invitation.id,
                                        revision: target.revision,
                                    }),
                                );
                                void revoke
                                    .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
                                    .then(() => {
                                        write.complete(attempt);
                                        notify(strings.revoked);
                                        setTarget(null);
                                    })
                                    .catch(() => undefined);
                            }}
                        >
                            {strings.removeInvite}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
