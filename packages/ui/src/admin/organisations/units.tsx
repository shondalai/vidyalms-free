import { useState } from 'react';
import { OrgIntro } from './workspace';
import { orgWorkspaceStrings as ws } from './workspace-strings';
import { FolderTree } from 'lucide-react';
import type { OrganisationUnit } from '@vidyalms/api-sdk';
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
    Pagination,
    Spinner,
    type DataTableColumn,
} from '@/shared/components';
import { SelectField, TextField } from '../components/field';
import { ListFrame } from '../components/list-frame';
import { useDebounced } from '../hooks';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import { useStudioDraft, useStudioNavigation } from '../credentials/studio-drafts';
import { useOrganisationUnit, useOrganisationUnits, useSaveOrganisationUnit } from './unit-api';
import { unitStrings as strings } from './unit-strings';
import type { PeopleProps } from './people';

export function OrganisationUnits({ organisation, roles, location, change, notify }: PeopleProps) {
    const [editor, setEditor] = useState<{ unit: OrganisationUnit | null } | null>(null);
    const query = useOrganisationUnits(organisation.id, location.page, useDebounced(location.search));
    const canManage = roles.access.unit_id === null && roles.access.actions.includes('people');
    const columns: DataTableColumn<OrganisationUnit>[] = [
        {
            key: 'path',
            header: strings.path,
            weight: 3,
            minWidth: 240,
            cell: (row) => (
                <span className="flex items-center gap-3">
                    <FolderTree size={18} aria-hidden className="shrink-0 text-fg-2" />
                    <span className="grid gap-0.5">
                        <span className="font-semibold text-fg">{row.name}</span>
                        <span className="text-xs text-fg-2">{row.path}</span>
                    </span>
                </span>
            ),
        },
        { key: 'depth', header: strings.depth, width: '110px', cell: (row) => strings.level(row.depth) },
        {
            key: 'state',
            header: strings.state,
            width: '130px',
            cell: (row) => (
                <Badge variant={row.state === 'active' ? 'accent' : 'neutral'}>{strings[row.state]}</Badge>
            ),
        },
        {
            key: 'actions',
            header: strings.actions,
            width: '140px',
            cell: (row) =>
                canManage ? (
                    <Button size="sm" variant="outline" onClick={() => setEditor({ unit: row })}>
                        {strings.edit}
                    </Button>
                ) : null,
        },
    ];
    return (
        <div className="grid gap-4">
            <OrgIntro title={strings.title} description={ws.unitsIntro} />
            <ListFrame
                title={strings.title}
                page={location.page}
                perPage={20}
                total={query.data?.total ?? 0}
                shownCount={query.data?.items.length ?? 0}
                onPageChange={(page) => change({ page })}
                search={{
                    label: strings.search,
                    placeholder: strings.chooseSearch,
                    value: location.search,
                    onChange: (search) => change({ search, page: 1 }),
                }}
                action={
                    canManage ? (
                        <Button onClick={() => setEditor({ unit: null })}>{strings.create}</Button>
                    ) : undefined
                }
                note={roles.access.unit_id !== null ? strings.scopeHint : strings.lifecycleHint}
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
                    caption={strings.title}
                    rows={query.data?.items ?? []}
                    rowKey={(row) => row.id}
                    columns={columns}
                    loading={query.isPending}
                    empty={<EmptyState title={strings.empty} />}
                />
            </ListFrame>
            {editor !== null && (
                <UnitEditor
                    organisation={organisation}
                    unit={editor.unit}
                    notify={notify}
                    close={() => setEditor(null)}
                />
            )}
        </div>
    );
}

function UnitEditor({
    organisation,
    unit,
    close,
    notify,
}: Pick<PeopleProps, 'organisation' | 'notify'> & { unit: OrganisationUnit | null; close: () => void }) {
    const save = useSaveOrganisationUnit();
    const write = useWriteAttempt();
    const navigation = useStudioNavigation();
    const setDirty = useStudioDraft(`org-${organisation.id}-unit-${unit?.id ?? 'new'}`);
    const [revision] = useState(organisation.revision);
    const [name, setName] = useState(unit?.name ?? '');
    const [parentId, setParentId] = useState(unit?.parent_id ?? null);
    const [state, setState] = useState<OrganisationUnit['state']>(unit?.state ?? 'active');
    const dirty =
        name !== (unit?.name ?? '') ||
        parentId !== (unit?.parent_id ?? null) ||
        state !== (unit?.state ?? 'active');
    const submit = async () => {
        const attempt = write.capture({ name, parentId, state }, () => ({
            id: organisation.id,
            unitId: unit?.id ?? null,
            body: { expected_revision: revision, name: name.trim(), parent_id: parentId, state },
        }));
        try {
            await save.mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey });
            write.complete(attempt);
            setDirty(false);
            notify(strings.saved);
            close();
        } catch {
            /* Preserve revision and retry key until the input changes. */
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !save.isPending) navigation.requestLeave(close);
            }}
        >
            <DialogContent>
                <DialogTitle>{unit === null ? strings.create : strings.edit}</DialogTitle>
                <DialogDescription>{strings.editorIntro}</DialogDescription>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <fieldset disabled={save.isPending} className="grid gap-4">
                        <TextField
                            label={strings.name}
                            required
                            maxLength={190}
                            value={name}
                            onChange={(event) => {
                                setName(event.target.value);
                                setDirty(true);
                            }}
                        />
                        <OrganisationUnitPicker
                            organisationId={organisation.id}
                            label={strings.parent}
                            value={parentId}
                            nullLabel={strings.root}
                            excludeId={unit?.id}
                            onChange={(id) => {
                                setParentId(id);
                                setDirty(true);
                            }}
                        />
                        <SelectField
                            label={strings.state}
                            value={state}
                            hint={strings.lifecycleHint}
                            onChange={(event) => {
                                setState(event.target.value as OrganisationUnit['state']);
                                setDirty(true);
                            }}
                        >
                            <option value="active">{strings.active}</option>
                            <option value="inactive">{strings.inactive}</option>
                        </SelectField>
                    </fieldset>
                    {save.isError && <ErrorState error={save.error} />}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={save.isPending}
                            onClick={() => navigation.requestLeave(close)}
                        >
                            {strings.cancel}
                        </Button>
                        <Button type="submit" disabled={save.isPending || !dirty || name.trim() === ''}>
                            {save.isPending ? strings.saving : strings.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A bounded search page plus an exact selected-ID read; never downloads the tree. */
export function OrganisationUnitPicker({
    organisationId,
    label,
    hint,
    value,
    onChange,
    nullLabel = strings.noUnit,
    allowNull = true,
    excludeId,
}: {
    organisationId: number;
    label: string;
    hint?: string;
    value: number | null;
    onChange: (id: number | null) => void;
    nullLabel?: string;
    allowNull?: boolean;
    excludeId?: number;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const selected = useOrganisationUnit(organisationId, value);
    const query = useOrganisationUnits(organisationId, page, useDebounced(search), open);
    const choose = (id: number | null) => {
        onChange(id);
        setOpen(false);
    };
    return (
        <fieldset className="grid min-w-0 gap-2 rounded border border-border p-3">
            <legend className="px-1 text-xs font-semibold text-fg">{label}</legend>
            {hint && <p className="text-xs text-fg-2">{hint}</p>}
            <div className="flex items-center gap-2">
                <span className="min-w-0 flex-1 break-words text-sm text-fg" aria-live="polite">
                    {value === null
                        ? nullLabel
                        : selected.isPending
                          ? strings.loading
                          : (selected.data?.path ?? strings.unavailable)}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-expanded={open}
                    onClick={() => setOpen(!open)}
                >
                    {open ? strings.closed : value === null ? strings.choose : strings.change}
                </Button>
            </div>
            {selected.isError && (
                <ErrorState
                    error={selected.error}
                    onRetry={() => {
                        void selected.refetch();
                    }}
                />
            )}
            {open && (
                <div className="grid gap-3 border-t border-border pt-3">
                    <TextField
                        type="search"
                        label={strings.search}
                        placeholder={strings.chooseSearch}
                        maxLength={200}
                        autoComplete="off"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                    {allowNull && (
                        <Button type="button" variant="outline" size="sm" onClick={() => choose(null)}>
                            {nullLabel}
                        </Button>
                    )}
                    {query.isPending && <Spinner label={strings.loading} />}
                    {query.isError && (
                        <ErrorState
                            error={query.error}
                            onRetry={() => {
                                void query.refetch();
                            }}
                        />
                    )}
                    {query.isSuccess && (
                        <div aria-live="polite">
                            {query.data.items.length === 0 ? (
                                <p className="text-xs text-fg-2">{strings.empty}</p>
                            ) : (
                                <ul
                                    aria-label={strings.results}
                                    className="grid max-h-52 gap-1 overflow-auto"
                                >
                                    {query.data.items.map((unit) => (
                                        <li key={unit.id}>
                                            <button
                                                type="button"
                                                disabled={unit.state !== 'active' || unit.id === excludeId}
                                                className="w-full rounded border border-border bg-surface px-3 py-2 text-left text-sm text-fg hover:bg-surface-2 disabled:opacity-50"
                                                onClick={() => choose(unit.id)}
                                            >
                                                {unit.path}
                                                {unit.state === 'inactive' && (
                                                    <span className="ml-2 text-xs text-fg-2">
                                                        {strings.inactive}
                                                    </span>
                                                )}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                    <Pagination
                        label={strings.pages}
                        page={page}
                        perPage={20}
                        total={query.data?.total ?? 0}
                        onPageChange={setPage}
                    />
                </div>
            )}
        </fieldset>
    );
}

export function OrganisationUnitLabel({
    organisationId,
    unitId,
}: {
    organisationId: number;
    unitId: number | null;
}) {
    const query = useOrganisationUnit(organisationId, unitId);
    return (
        <span className="text-xs text-fg-2">
            {unitId === null ? strings.noUnit : (query.data?.path ?? strings.unitReference(unitId))}
        </span>
    );
}
