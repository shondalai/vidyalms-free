import { translate as uiText } from '@/shared/i18n';
import { Award, Copy, Eye, LayoutGrid, List, Palette, Plus } from 'lucide-react';
import { CredentialIntro } from './workspace';
import { CertificateArtwork } from './certificate-artwork';
import { certificateWorkspaceStrings as ws } from './workspace-strings';
import { useState } from 'react';
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
    Skeleton,
    ErrorState,
    type DataTableColumn,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import type { CertificateTemplate } from '@vidyalms/api-sdk';
import { screenUrl, useAdminConfig } from '../config';
import { useDebounced } from '../hooks';
import { adminStrings } from '../strings';
import { credentialStrings } from '../credential-strings';
import { FilterChips } from '../components/filter-chips';
import { ListFrame } from '../components/list-frame';
import { AdminNotice, ScreenError } from '../components/states';
import { SelectField, TextField } from '../components/field';
import {
    useArchiveCertificateTemplate,
    useCertificateTemplates,
    useCertificateWriteAttempt,
    useCreateCertificateTemplate,
} from './api';
import { TemplateEditor, PreviewPanel } from './template-editor';
import { certificateLibraryHash, certificateLibraryState, parseCertificateRoute } from './studio-routes';
import { certificateStudioStrings as studioStrings } from './studio-strings';
import { CertificateBlueprintGallery, CertificateCloneDialog } from './blueprint-gallery';
import { useCertificateBlueprints } from './studio-api';
import { FilterSearch, FilterSelect, FilterToolbar } from '../components/filter-toolbar';

/*
 * The template list, and the editor it opens.
 *
 * The seeded free template is an ordinary row here rather than a special
 * case: the design's single free template is "Standard (site logo, colours
 * from tokens)", and it is editable exactly as one an author creates. What
 * makes it recognisable to a package after somebody renames it is its
 * template key, not its position in this list.
 */

const PER_PAGE = 20;
const strings = credentialStrings;

type TemplateFilter = 'all' | 'draft' | 'published' | 'archived';

export function TemplatesTab({
    hash,
    navigate,
    backHash,
    onDirtyChange,
}: {
    hash: string;
    navigate: (hash: string) => void;
    backHash: string;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const list = certificateLibraryState(hash);
    const commitList = (patch: Partial<typeof list>) =>
        navigate(certificateLibraryHash({ ...list, page: 1, ...patch }));
    const route = parseCertificateRoute(hash);
    const editing = route.kind === 'editor' ? route.templateId : null;
    const setEditing = (id: number) => navigate(`/templates/${id}/edit`);
    const search = useDebounced(list.search);
    const [creating, setCreating] = useState(false);
    const [browsing, setBrowsing] = useState(false);
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const [previewing, setPreviewing] = useState<CertificateTemplate | null>(null);
    const [archiving, setArchiving] = useState<CertificateTemplate | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [cloning, setCloning] = useState<CertificateTemplate | null>(null);
    const catalogue = useCertificateBlueprints();

    const params = {
        state: list.filter === 'all' ? undefined : (list.filter as Exclude<TemplateFilter, 'all'>),
        page: list.page,
        per_page: PER_PAGE,
        search: search || undefined,
        category: list.category || undefined,
    };
    const templates = useCertificateTemplates(params, { enabled: editing === null });
    const items = templates.data?.items ?? [];
    const total = templates.data?.total ?? 0;

    const done = (text: string) => {
        setMessage(text);
        announce(text);
    };

    if (editing !== null) {
        return (
            <TemplateEditor
                key={editing}
                templateId={editing}
                onBack={() => navigate(backHash)}
                onDirtyChange={onDirtyChange}
            />
        );
    }

    const columns: DataTableColumn<CertificateTemplate>[] = [
        {
            key: 'name',
            header: strings.templates.columns.name,
            weight: 1,
            cell: (row) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    <span className="text-fg">{row.name}</span>
                    {row.has_unpublished_changes && <Badge variant="warn">{studioStrings.unpublished}</Badge>}
                    {row.is_default && <Badge variant="accent">{strings.templates.defaultBadge}</Badge>}
                    {row.locked_at !== null && <Badge variant="warn">{strings.templates.lockedBadge}</Badge>}
                </span>
            ),
        },
        {
            key: 'format',
            header: strings.templates.columns.format,
            width: '150px',
            cell: (row) => (
                <span className="text-fg-2">{strings.templates.formats[row.format] ?? row.format}</span>
            ),
        },
        {
            key: 'state',
            header: strings.templates.columns.state,
            width: '110px',
            cell: (row) => (
                <span className="text-fg-2">{strings.templates.states[row.state] ?? row.state}</span>
            ),
        },
        {
            key: 'revision',
            header: strings.templates.columns.revision,
            width: '90px',
            cell: (row) => <span className="text-fg-2">{row.revision}</span>,
        },
        {
            key: 'pages',
            header: strings.templates.columns.pages,
            width: '150px',
            cell: (row) => (
                <span className="text-fg-2">
                    {strings.templates.pageCount(row.page_count)},{' '}
                    {strings.templates.elementCount(row.element_count)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '170px',
            align: 'end',
            cell: (row) => (
                <span className="flex flex-wrap justify-end gap-1.5">
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={strings.templates.editNamed(row.name)}
                        onClick={() => setEditing(row.id)}
                    >
                        {strings.templates.edit}
                    </Button>
                    {(row.published_revision ?? null) !== null && (
                        <Button variant="ghost" size="sm" onClick={() => setCloning(row)}>
                            {studioStrings.clone}
                        </Button>
                    )}
                    {row.state !== 'archived' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: strings.templates.archive, p1: row.name })}
                            onClick={() => setArchiving(row)}
                        >
                            {strings.badges.archive}
                        </Button>
                    )}
                </span>
            ),
        },
    ];

    return (
        <>
            <CredentialIntro title={ws.libraryTitle} description={ws.libraryHint} />
            <div className="vy-cert-library-actions">
                <Button variant="primary" onClick={() => setBrowsing(true)}>
                    <Palette size={16} aria-hidden="true" />
                    {ws.browse}
                </Button>
                <Button
                    variant="outline"
                    aria-label={strings.templates.create}
                    onClick={() => setCreating(true)}
                >
                    <Plus size={16} aria-hidden="true" />
                    {ws.blank}
                </Button>
                <div className="vy-cert-view-switch" aria-label={uiText("vidyalms.ui.52eea2d1a1af6aa7", "Template display")}>
                    <Button
                        variant={view === 'cards' ? 'primary' : 'ghost'}
                        size="sm"
                        aria-label={uiText("vidyalms.ui.82084aae43aa4ad3", "Template cards")}
                        aria-pressed={view === 'cards'}
                        onClick={() => setView('cards')}
                    >
                        <LayoutGrid size={16} aria-hidden="true" />
                    </Button>
                    <Button
                        variant={view === 'table' ? 'primary' : 'ghost'}
                        size="sm"
                        aria-label={uiText("vidyalms.ui.0283a6f0aff34acf", "Template table")}
                        aria-pressed={view === 'table'}
                        onClick={() => setView('table')}
                    >
                        <List size={16} aria-hidden="true" />
                    </Button>
                </div>
            </div>
            <ListFrame
                title={strings.templates.title}
                meta={templates.isSuccess ? strings.templates.count(total) : undefined}
                chips={
                    <FilterChips
                        label={adminStrings.list.filters}
                        value={list.filter}
                        onChange={(filter) => commitList({ filter: filter as TemplateFilter })}
                        chips={[
                            { value: 'all', label: strings.templates.chips.all },
                            { value: 'draft', label: strings.templates.chips.draft },
                            { value: 'published', label: strings.templates.chips.published },
                            { value: 'archived', label: strings.templates.chips.archived },
                        ]}
                    />
                }
                banner={
                    message === null ? undefined : (
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
                onPageChange={(page) => commitList({ page })}
            >
                <FilterToolbar
                    label={studioStrings.search}
                    search={
                        <FilterSearch
                            label={studioStrings.search}
                            value={list.search}
                            onValueChange={(value) => commitList({ search: value.slice(0, 200) })}
                        />
                    }
                    filters={
                        <FilterSelect
                            label={studioStrings.category}
                            value={list.category}
                            onValueChange={(category) => commitList({ category })}
                            options={[
                                { value: '', label: studioStrings.allCategories },
                                ...(catalogue.data?.items ?? [])
                                    .filter(
                                        (item, index, rows) =>
                                            rows.findIndex(
                                                (row) => row.category_key === item.category_key,
                                            ) === index,
                                    )
                                    .map((item) => ({ value: item.category_key, label: item.category })),
                            ]}
                        />
                    }
                />
                {templates.isError ? (
                    <div className="p-4">
                        <ScreenError
                            error={templates.error}
                            onRetry={() => void templates.refetch()}
                            coursesUrl={screenUrl(config, 'courses')}
                        />
                    </div>
                ) : view === 'table' ? (
                    <DataTable
                        caption={strings.templates.table}
                        columns={columns}
                        rows={items}
                        rowKey={(row) => row.id}
                        loading={templates.isPending}
                        empty={<EmptyState title={strings.templates.empty} />}
                    />
                ) : templates.isPending ? (
                    <Skeleton className="m-4 h-64" />
                ) : items.length === 0 ? (
                    <EmptyState title={strings.templates.empty} />
                ) : (
                    <div className="vy-cert-template-grid" aria-label={ws.saved}>
                        {items.map((row) => (
                            <article className="vy-cert-template-card" key={row.id}>
                                <div className="vy-cert-template-cover">
                                    <div className="vy-cert-card-badges">
                                        {row.is_default && (
                                            <Badge variant="accent">{strings.templates.defaultBadge}</Badge>
                                        )}
                                        <Badge variant={row.state === 'published' ? 'ok' : 'neutral'}>
                                            {strings.templates.states[row.state] ?? row.state}
                                        </Badge>
                                    </div>
                                    {row.thumbnail ? (
                                        <button
                                            type="button"
                                            className="vy-cert-artwork-link"
                                            aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: ws.customize, p1: row.name })}
                                            onClick={() => setEditing(row.id)}
                                        >
                                            <CertificateArtwork
                                                {...row.thumbnail}
                                                title={uiText("vidyalms.ui.3e3c5fd46b6e7f26", "Sample artwork: {p0}", { p0: row.name })}
                                            />
                                        </button>
                                    ) : (
                                        <div className="vy-cert-structure" aria-label={ws.structural}>
                                            <Award size={30} aria-hidden="true" />
                                            <strong>{row.name}</strong>
                                            <span>
                                                {ws.pages(row.page_count)} · {ws.elements(row.element_count)}
                                            </span>
                                        </div>
                                    )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={ws.previewNamed(row.name)}
                                        onClick={() => setPreviewing(row)}
                                    >
                                        <Eye size={14} aria-hidden="true" />
                                        {ws.preview}
                                    </Button>
                                </div>
                                <div className="vy-cert-template-body">
                                    <h3>{row.name}</h3>
                                    <p>
                                        {strings.templates.formats[row.format] ?? row.format} ·{' '}
                                        {ws.revision(row.revision)}
                                    </p>
                                    <div className="vy-cert-card-notices">
                                        {row.has_unpublished_changes && (
                                            <Badge variant="warn">{studioStrings.unpublished}</Badge>
                                        )}
                                        {row.locked_at !== null && (
                                            <Badge variant="neutral">{strings.templates.lockedBadge}</Badge>
                                        )}
                                    </div>
                                    <div className="vy-cert-template-actions">
                                        <Button
                                            variant="primary"
                                            aria-label={strings.templates.editNamed(row.name)}
                                            onClick={() => setEditing(row.id)}
                                        >
                                            <Palette size={15} aria-hidden="true" />
                                            {ws.customize}
                                        </Button>
                                        {(row.published_revision ?? null) !== null && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: studioStrings.clone, p1: row.name })}
                                                onClick={() => setCloning(row)}
                                            >
                                                <Copy size={15} aria-hidden="true" />
                                                {studioStrings.clone}
                                            </Button>
                                        )}
                                        {row.state !== 'archived' && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: strings.templates.archive, p1: row.name })}
                                                onClick={() => setArchiving(row)}
                                            >
                                                {strings.templates.archive}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </ListFrame>
            {browsing && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) setBrowsing(false);
                    }}
                >
                    <DialogContent className="vy-cert-gallery-dialog max-w-6xl">
                        <DialogTitle>{ws.browse}</DialogTitle>
                        <DialogDescription>{ws.libraryHint}</DialogDescription>
                        <CertificateBlueprintGallery
                            onCreated={(id) => {
                                setBrowsing(false);
                                setEditing(id);
                            }}
                        />
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setBrowsing(false)}>
                                {strings.form.close}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
            {previewing !== null && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) setPreviewing(null);
                    }}
                >
                    <DialogContent className="vy-cert-preview-dialog max-w-5xl">
                        <DialogTitle>{ws.previewNamed(previewing.name)}</DialogTitle>
                        <DialogDescription>{ws.previewHint}</DialogDescription>
                        <PreviewPanel templateId={previewing.id} revision={previewing.revision} />
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setPreviewing(null)}>
                                {strings.form.close}
                            </Button>
                            <Button
                                variant="primary"
                                onClick={() => {
                                    setEditing(previewing.id);
                                    setPreviewing(null);
                                }}
                            >
                                {ws.customize}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
            {cloning !== null && (
                <CertificateCloneDialog
                    template={cloning}
                    onClose={() => setCloning(null)}
                    onCreated={(id) => {
                        setCloning(null);
                        setEditing(id);
                    }}
                />
            )}
            {creating && (
                <CreateTemplateDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setCreating(false);
                        }
                    }}
                    onCreated={(id, text) => {
                        setCreating(false);
                        done(text);
                        setEditing(id);
                    }}
                />
            )}
            {archiving !== null && (
                <ArchiveTemplateDialog
                    open
                    template={archiving}
                    onOpenChange={(next) => {
                        if (!next) {
                            setArchiving(null);
                        }
                    }}
                    onDone={done}
                />
            )}
        </>
    );
}

function CreateTemplateDialog({
    open,
    onOpenChange,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (id: number, message: string) => void;
}) {
    const create = useCreateCertificateTemplate();
    const write = useCertificateWriteAttempt();
    const [name, setName] = useState('');
    const [format, setFormat] = useState<'html' | 'elements'>('elements');
    const catalogue = useCertificateBlueprints();
    const [rendererChoice, setRendererChoice] = useState('');
    const renderers = (catalogue.data?.renderer_options ?? []).filter((item) =>
        item.formats.includes(format),
    );
    const renderer =
        renderers.find((item) => item.key === rendererChoice)?.key ??
        renderers.find((item) => item.key === 'pdf')?.key ??
        renderers[0]?.key ??
        '';

    const submit = async () => {
        const body = {
            name: name.trim(),
            format,
            renderer,
            achievement_type: 'CertificateOfCompletion',
            state: 'draft' as const,
        };
        const attempt = write.capture(body, () => ({ body }));
        try {
            const created = await create.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            write.complete(attempt);
            onCreated(created.id, strings.templates.saved);
        } catch {
            /* The banner renders the refusal from the mutation's own state. */
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>{strings.templates.createTitle}</DialogTitle>
                <TextField
                    fieldClassName="mt-3"
                    label={strings.templates.fields.name}
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
                <SelectField
                    fieldClassName="mt-3"
                    label={strings.templates.fields.format}
                    hint={strings.templates.fields.formatHint}
                    value={format}
                    onChange={(event) => setFormat(event.target.value as 'html' | 'elements')}
                >
                    {Object.entries(strings.templates.formats).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </SelectField>
                <SelectField
                    fieldClassName="mt-3"
                    label={studioStrings.renderer}
                    value={renderer}
                    onChange={(event) => setRendererChoice(event.target.value)}
                    disabled={renderers.length === 0}
                >
                    {renderers.map((item) => (
                        <option key={item.key} value={item.key}>
                            {item.label}
                        </option>
                    ))}
                </SelectField>
                {renderers.length === 0 && (
                    <p className="mt-2 text-sm text-fg-2">{studioStrings.rendererMissing}</p>
                )}
                {catalogue.isError && (
                    <ErrorState
                        error={catalogue.error}
                        onRetry={() => {
                            void catalogue.refetch();
                        }}
                    />
                )}
                {create.isError && <ErrorState className="mt-3" error={create.error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
                        {strings.form.cancel}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={create.isPending || name.trim() === '' || renderer === ''}
                        onClick={() => {
                            void submit();
                        }}
                    >
                        {create.isPending ? strings.templates.saving : strings.templates.create}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ArchiveTemplateDialog({
    open,
    template,
    onOpenChange,
    onDone,
}: {
    open: boolean;
    template: CertificateTemplate;
    onOpenChange: (open: boolean) => void;
    onDone: (message: string) => void;
}) {
    const archive = useArchiveCertificateTemplate();
    const write = useCertificateWriteAttempt();

    const submit = async () => {
        const attempt = write.capture({ id: template.id }, () => ({
            id: template.id,
            expectedRevision: template.revision,
        }));
        try {
            await archive.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
        } catch {
            return;
        }
        write.complete(attempt);
        onDone(strings.templates.archived(template.name));
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>{strings.templates.archiveTitle}</DialogTitle>
                <DialogDescription>{strings.templates.archiveBody(template.name)}</DialogDescription>
                {archive.isError && <ErrorState className="mt-3" error={archive.error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
                        {strings.templates.archiveCancel}
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        disabled={archive.isPending}
                        onClick={() => {
                            void submit();
                        }}
                    >
                        {strings.templates.archiveSubmit}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
