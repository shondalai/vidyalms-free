import { useIsMutating } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Building2, Database, Power, Save, ShieldCheck } from 'lucide-react';
import type { Organisation, OrganisationRolePage, OrganisationUpdateRequest } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    EmptyState,
    ErrorState,
    Icon,
} from '@/shared/components';
import { SelectField, TextField } from '../components/field';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import { useStudioDraft, useStudioNavigation } from '../credentials/studio-drafts';
import { organisationKeys, useUpdateOrganisation } from './api';
import { OrganisationCleanup, OrganisationPrivacy } from './governance';
import { OrgPanel, orgType } from './workspace';
import { organisationStrings as strings } from './strings';
import { orgWorkspaceStrings as s } from './workspace-strings';
import type { OrganisationLocation } from './routes';
import { displayValue } from '../learners/workspace';

type Props = {
    organisation: Organisation;
    roles: OrganisationRolePage;
    notify: (message: string) => void;
    location: OrganisationLocation;
    change: (changes: Partial<OrganisationLocation>) => void;
};
const sections = [
    { id: 'general', label: s.general, hint: s.generalHint, icon: Building2 },
    { id: 'permissions', label: s.permissions, hint: s.permissionsHint, icon: ShieldCheck },
    { id: 'privacy', label: s.privacy, hint: s.privacyHint, icon: Database },
    { id: 'lifecycle', label: s.lifecycle, hint: s.lifecycleHint, icon: Power },
];
export function Settings({ organisation, roles, notify, location, change }: Props) {
    const navigation = useStudioNavigation();
    const saving = useIsMutating({ mutationKey: organisationKeys.root }) > 0;
    const section = sections.some((item) => item.id === location.state) ? location.state : 'general';
    return (
        <div className="vy-org-settings-layout">
            <nav className="vy-org-settings-nav" aria-label={s.settingsNav}>
                {sections.map((item) => (
                    <button
                        type="button"
                        key={item.id}
                        disabled={saving}
                        aria-current={item.id === section ? 'page' : undefined}
                        onClick={() => navigation.requestLeave(() => change({ state: item.id, page: 1 }))}
                    >
                        <Icon icon={item.icon} size={19} />
                        <span>
                            <strong>{item.label}</strong>
                            <small>{item.hint}</small>
                        </span>
                    </button>
                ))}
            </nav>
            <div className="vy-org-section-stack">
                {section === 'general' ? (
                    <General organisation={organisation} notify={notify} />
                ) : section === 'permissions' ? (
                    <Permissions
                        roles={roles}
                        manage={() => change({ tab: 'people', state: '', page: 1, people: 'members' })}
                    />
                ) : section === 'privacy' ? (
                    <OrganisationPrivacy id={organisation.id} />
                ) : (
                    <>
                        <Lifecycle
                            organisation={organisation}
                            siteManager={roles.access.site_manager}
                            notify={notify}
                        />
                        <OrganisationCleanup organisation={organisation} notify={notify} />
                    </>
                )}
            </div>
        </div>
    );
}

function General({ organisation: org, notify }: Pick<Props, 'organisation' | 'notify'>) {
    const update = useUpdateOrganisation();
    const write = useWriteAttempt();
    const setDirty = useStudioDraft(`org-settings-${org.id}`);
    const [name, setName] = useState(org.name);
    const [type, setType] = useState(org.type);
    const [base, setBase] = useState(org.revision);
    const [dirty, setLocalDirty] = useState(false);
    useEffect(() => {
        if (!dirty) {
            setName(org.name);
            setType(org.type);
            setBase(org.revision);
        }
    }, [org.name, org.type, org.revision, dirty]);
    const changed = () => {
        if (!dirty) setBase(org.revision);
        setDirty(true);
        setLocalDirty(true);
    };
    const discard = () => {
        setName(org.name);
        setType(org.type);
        setBase(org.revision);
        setDirty(false);
        setLocalDirty(false);
        update.reset();
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const body: OrganisationUpdateRequest = {
            expected_revision: base,
            name: name.trim(),
            ...(type !== org.type ? { type } : {}),
        };
        const attempt = write.capture({ name, type }, () => ({ id: org.id, body }));
        void update
            .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
            .then((result) => {
                write.complete(attempt);
                setBase(result.revision);
                setName(result.name);
                setType(result.type);
                setDirty(false);
                setLocalDirty(false);
                notify(strings.saved);
            })
            .catch(() => undefined);
    };
    return (
        <form onSubmit={submit} className="vy-org-section-stack">
            <OrgPanel title={s.editName} description={s.editNameHint}>
                <fieldset disabled={update.isPending} className="vy-org-form-grid">
                    <TextField
                        label={strings.name}
                        value={name}
                        required
                        maxLength={255}
                        onChange={(event) => {
                            changed();
                            setName(event.target.value);
                        }}
                    />
                    <TextField
                        label={strings.slugReadOnly}
                        value={org.slug}
                        readOnly
                        hint={strings.slugHint}
                    />
                    {org.available_types?.length ? (
                        <SelectField
                            label={s.type}
                            value={type}
                            onChange={(event) => {
                                changed();
                                setType(event.target.value);
                            }}
                        >
                            {[...new Set([org.type, ...(org.available_types ?? [])])].map((value) => (
                                <option key={value} value={value}>
                                    {orgType(value, value === org.type && org.type_unresolved)}
                                </option>
                            ))}
                        </SelectField>
                    ) : (
                        <TextField label={s.type} value={orgType(org.type, org.type_unresolved)} readOnly />
                    )}
                </fieldset>
            </OrgPanel>
            <OrgPanel title={s.identity} description={s.contactHint}>
                <dl className="vy-org-facts">
                    {[
                        [s.contact, org.contact_email || s.unset],
                        [s.timezone, org.timezone || s.inherited],
                        [s.locale, org.locale || s.inherited],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt>{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>
            </OrgPanel>
            {update.isError && <ErrorState error={update.error} />}
            <div className="vy-org-savebar">
                <p role="status">{dirty ? s.saveHint : s.savedHint}</p>
                <Button type="button" variant="ghost" disabled={!dirty || update.isPending} onClick={discard}>
                    {s.discard}
                </Button>
                <Button type="submit" disabled={!dirty || !name.trim() || update.isPending}>
                    <Icon icon={Save} size={16} />
                    {update.isPending ? strings.saving : strings.save}
                </Button>
            </div>
        </form>
    );
}

function Permissions({ roles, manage }: { roles: OrganisationRolePage; manage: () => void }) {
    return (
        <>
            <OrgPanel
                title={s.roles}
                description={s.rolesIntro}
                action={
                    roles.access.actions.includes('people') ? (
                        <Button variant="outline" onClick={manage}>
                            {s.viewMembers}
                        </Button>
                    ) : undefined
                }
            >
                {!!roles.missing_providers.length && (
                    <p role="status" className="vy-org-warning">
                        {s.missingRoles}
                    </p>
                )}
                {!roles.items.length ? (
                    <EmptyState title={s.noRoles} />
                ) : (
                    <div className="vy-org-role-grid">
                        {roles.items.map((role) => (
                            <article key={role.key}>
                                <div>
                                    <Icon icon={ShieldCheck} size={21} />
                                    <h3>{role.label}</h3>
                                    <Badge variant="neutral">
                                        {role.assignable ? s.assignable : s.managed}
                                    </Badge>
                                </div>
                                <div className="vy-org-permission-chips">
                                    {role.actions.map((action) => (
                                        <Badge key={action}>{displayValue(action)}</Badge>
                                    ))}
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </OrgPanel>
            <OrgPanel title={s.permissionsScope}>
                <div className="vy-org-permission-chips">
                    {roles.access.actions.map((action) => (
                        <Badge key={action} variant="accent">
                            {displayValue(action)}
                        </Badge>
                    ))}
                </div>
                <p className="vy-org-note">{roles.access.unit_id === null ? s.fullScope : s.unitScope}</p>
            </OrgPanel>
        </>
    );
}

function Lifecycle({
    organisation: org,
    siteManager,
    notify,
}: {
    organisation: Organisation;
    siteManager: boolean;
    notify: (message: string) => void;
}) {
    const update = useUpdateOrganisation();
    const write = useWriteAttempt();
    const [target, setTarget] = useState<{ revision: number; state: Organisation['state'] } | null>(null);
    const confirm = () => {
        if (!target) return;
        const attempt = write.capture(target, () => ({
            id: org.id,
            body: { expected_revision: target.revision, state: target.state },
        }));
        void update
            .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
            .then(() => {
                write.complete(attempt);
                setTarget(null);
                notify(strings.saved);
            })
            .catch(() => undefined);
    };
    return (
        <OrgPanel title={s.lifecycleTitle} description={s.lifecycleIntro}>
            <Badge variant={org.state === 'active' ? 'accent' : 'warn'}>
                {strings.stateLabels[org.state]}
            </Badge>
            <p className="vy-org-note">{org.state === 'active' ? strings.suspendBody : strings.resumeBody}</p>
            {siteManager && (
                <Button
                    className="justify-self-start"
                    variant={org.state === 'active' ? 'destructive' : 'outline'}
                    onClick={() =>
                        setTarget({
                            revision: org.revision,
                            state: org.state === 'active' ? 'suspended' : 'active',
                        })
                    }
                >
                    {org.state === 'active' ? strings.suspend : strings.resume}
                </Button>
            )}
            <Dialog
                open={target !== null}
                onOpenChange={(open) => {
                    if (!open && !update.isPending) setTarget(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>
                        {target?.state === 'suspended' ? strings.suspendTitle : strings.resumeTitle}
                    </DialogTitle>
                    <DialogDescription>
                        {target?.state === 'suspended' ? strings.suspendBody : strings.resumeBody}
                    </DialogDescription>
                    {update.isError && <ErrorState error={update.error} />}
                    <DialogFooter>
                        <Button variant="ghost" disabled={update.isPending} onClick={() => setTarget(null)}>
                            {strings.cancel}
                        </Button>
                        <Button
                            variant={target?.state === 'suspended' ? 'destructive' : 'primary'}
                            disabled={update.isPending}
                            onClick={confirm}
                        >
                            {target?.state === 'suspended' ? strings.suspend : strings.resume}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </OrgPanel>
    );
}
