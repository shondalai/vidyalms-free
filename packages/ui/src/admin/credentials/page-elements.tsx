import { certificateWorkspaceStrings as ws } from './workspace-strings';
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, FilePlus2, Trash2 } from 'lucide-react';
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
import { useAnnounce } from '@/shared/a11y';
import { useCertificateDesigner } from '@/certificate-designer';
import type {
    CertificateElementType,
    CertificateTemplateDetail,
    CertificateTemplateElement,
    CertificateTemplatePage,
    TokenSource,
} from '@vidyalms/api-sdk';
import { SelectField, TextField } from '../components/field';
import { useAdminConfig } from '../config';
import { credentialStrings } from '../credential-strings';
import {
    useAddTemplateElement,
    useAddTemplatePage,
    useDeleteTemplateElement,
    useDeleteTemplatePage,
    useReorderTemplateElements,
    useReorderTemplatePages,
    useCertificateWriteAttempt,
    useUpdateTemplateElement,
    useUpdateTemplatePage,
} from './api';
import { ElementSettingsForm, seedElementPayload, type ElementPayload } from './element-settings';
import { useStudioDraft, useStudioNavigation } from './studio-drafts';
import { certificateStudioStrings as studioStrings } from './studio-strings';
import { useContainerWidth } from '@/instructor/lib/layout';

/*
 * The two lower levels of the template aggregate: the page, which owns
 * paper, and the element, which owns one drawn thing.
 *
 * The split is mod_customcert 5.2.0's correction of its own 4.x schema,
 * taken for free: layout and ordering are host columns because the layout
 * service owns them, and font, size, colour and every element-specific
 * setting live inside the versioned payload because they belong to the
 * element provider. So this file draws the layout fields itself and hands
 * the payload to the schema-driven form beside it.
 *
 * The canvas is a registration. With a designer registered the author gets a
 * tab that moves the same elements with a pointer; with none, every element
 * is still addable, positionable, editable and removable here. The form is
 * the fallback rather than an afterthought, which is the direction the
 * core's registry-miss table publishes for an authoring surface.
 */

const strings = credentialStrings;

function moved<T>(items: readonly T[], from: number, to: number): T[] {
    const next = [...items];
    const [row] = next.splice(from, 1);
    if (row === undefined) {
        return next;
    }
    next.splice(to, 0, row);
    return next;
}

export interface PagesPanelProps {
    template: CertificateTemplateDetail;
    selectedPageId: number | null;
    onSelectPage: (pageId: number) => void;
    readOnly: boolean;
    onDone: (message: string) => void;
}

export function PagesPanel({ template, selectedPageId, onSelectPage, readOnly, onDone }: PagesPanelProps) {
    const navigation = useStudioNavigation();
    const [removingPage, setRemovingPage] = useState<number | null>(null);
    const announce = useAnnounce();
    const addPage = useAddTemplatePage();
    const deletePage = useDeleteTemplatePage();
    const reorderPages = useReorderTemplatePages();
    const addWrite = useCertificateWriteAttempt();
    const deleteWrite = useCertificateWriteAttempt();
    const reorderWrite = useCertificateWriteAttempt();
    const pages = template.pages;

    const add = async () => {
        const attempt = addWrite.capture({ templateId: template.id, action: 'add-page' }, () => ({
            templateId: template.id,
            body: { expected_revision: template.revision },
        }));
        try {
            await addPage.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
        } catch {
            return;
        }
        addWrite.complete(attempt);
        onDone(strings.pages.added);
        announce(strings.pages.added);
    };

    const move = async (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= pages.length) {
            return;
        }
        const ids = moved(pages, index, target).map((page) => page.id);
        const attempt = reorderWrite.capture({ templateId: template.id, ids }, () => ({
            templateId: template.id,
            ids,
            expectedRevision: template.revision,
        }));
        try {
            await reorderPages.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            reorderWrite.complete(attempt);
        } catch {
            /* The banner renders the refusal from the mutation's own state. */
        }
    };

    const remove = async (pageId: number) => {
        const attempt = deleteWrite.capture({ templateId: template.id, pageId }, () => ({
            templateId: template.id,
            pageId,
            expectedRevision: template.revision,
        }));
        try {
            await deletePage.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            deleteWrite.complete(attempt);
            setRemovingPage(null);
        } catch {
            /* The refusal stays visible above the page list. */
        }
    };

    return (
        <section aria-labelledby="vy-cert-pages" className="grid gap-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <h3 id="vy-cert-pages" className="font-display text-xs font-bold text-fg">
                    {strings.pages.heading}
                </h3>
                <span className="text-[11.5px] text-fg-2">{strings.templates.pageCount(pages.length)}</span>
                {!readOnly && (
                    <Button
                        className="ml-auto"
                        variant="outline"
                        size="sm"
                        disabled={addPage.isPending || navigation.dirty}
                        onClick={() => {
                            void add();
                        }}
                    >
                        <Icon icon={FilePlus2} size={16} />
                        {strings.pages.add}
                    </Button>
                )}
            </div>
            {(addPage.isError || deletePage.isError || reorderPages.isError) && (
                <ErrorState error={addPage.error ?? deletePage.error ?? reorderPages.error} />
            )}
            {pages.length === 0 ? (
                <EmptyState title={strings.pages.empty} />
            ) : (
                <ul className="grid gap-1.5">
                    {pages.map((page, index) => (
                        <li key={page.id} className="flex flex-wrap items-center gap-1.5">
                            <Button
                                variant={page.id === selectedPageId ? 'primary' : 'outline'}
                                size="sm"
                                aria-pressed={page.id === selectedPageId}
                                onClick={() => onSelectPage(page.id)}
                            >
                                {strings.pages.name(index + 1)}
                            </Button>
                            <span className="text-[11.5px] text-fg-2">
                                {strings.templates.elementCount(page.elements.length)}
                            </span>
                            {!readOnly && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={index === 0 || reorderPages.isPending || navigation.dirty}
                                        aria-label={strings.pages.moveUpNamed(index + 1)}
                                        title={strings.pages.moveUpNamed(index + 1)}
                                        className="vy-cert-icon-action"
                                        onClick={() => {
                                            void move(index, -1);
                                        }}
                                    >
                                        <Icon icon={ArrowUp} size={16} />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={
                                            index === pages.length - 1 ||
                                            reorderPages.isPending ||
                                            navigation.dirty
                                        }
                                        aria-label={strings.pages.moveDownNamed(index + 1)}
                                        title={strings.pages.moveDownNamed(index + 1)}
                                        className="vy-cert-icon-action"
                                        onClick={() => {
                                            void move(index, 1);
                                        }}
                                    >
                                        <Icon icon={ArrowDown} size={16} />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={deletePage.isPending || navigation.dirty}
                                        aria-label={strings.pages.removeNamed(index + 1)}
                                        title={strings.pages.removeNamed(index + 1)}
                                        className="vy-cert-icon-action"
                                        onClick={() => {
                                            setRemovingPage(page.id);
                                        }}
                                    >
                                        <Icon icon={Trash2} size={16} />
                                    </Button>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Dialog
                open={removingPage !== null}
                onOpenChange={(open) => {
                    if (!open && !deletePage.isPending) setRemovingPage(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{studioStrings.removePageTitle}</DialogTitle>
                    <DialogDescription>{studioStrings.removePageBody}</DialogDescription>
                    {deletePage.isError && <ErrorState error={deletePage.error} />}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            disabled={deletePage.isPending}
                            onClick={() => setRemovingPage(null)}
                        >
                            {studioStrings.cancel}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={deletePage.isPending || navigation.dirty}
                            onClick={() => {
                                if (removingPage !== null) void remove(removingPage);
                            }}
                        >
                            {studioStrings.remove}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

export interface PageSettingsProps {
    templateId: number;
    expectedRevision: number;
    page: CertificateTemplatePage;
    readOnly: boolean;
}

type PageDraftField =
    | 'width'
    | 'height'
    | 'orientation'
    | 'margin_top'
    | 'margin_right'
    | 'margin_bottom'
    | 'margin_left'
    | 'background';

export function PageSettings({ templateId, expectedRevision, page, readOnly }: PageSettingsProps) {
    const setDirty = useStudioDraft(`page-${page.id}`);
    const update = useUpdateTemplatePage();
    const write = useCertificateWriteAttempt();
    const [draft, setDraft] = useState(page);
    const dirtyFields = useRef<Set<PageDraftField>>(new Set());
    const editVersion = useRef(0);
    const observedRevision = useRef(expectedRevision);
    observedRevision.current = Math.max(observedRevision.current, expectedRevision);

    useEffect(() => {
        setDraft((current) => ({
            ...page,
            width: dirtyFields.current.has('width') ? current.width : page.width,
            height: dirtyFields.current.has('height') ? current.height : page.height,
            orientation: dirtyFields.current.has('orientation') ? current.orientation : page.orientation,
            margin_top: dirtyFields.current.has('margin_top') ? current.margin_top : page.margin_top,
            margin_right: dirtyFields.current.has('margin_right') ? current.margin_right : page.margin_right,
            margin_bottom: dirtyFields.current.has('margin_bottom')
                ? current.margin_bottom
                : page.margin_bottom,
            margin_left: dirtyFields.current.has('margin_left') ? current.margin_left : page.margin_left,
            background: dirtyFields.current.has('background') ? current.background : page.background,
        }));
    }, [page]);

    const editDraft = <K extends PageDraftField>(key: K, value: CertificateTemplatePage[K]) => {
        setDirty(true);
        write.pinRevision(expectedRevision);
        dirtyFields.current.add(key);
        editVersion.current += 1;
        setDraft((current) => ({ ...current, [key]: value }));
    };
    const paperPresets = {
        a4_landscape: { width: 297, height: 210, orientation: 'landscape' as const },
        a4_portrait: { width: 210, height: 297, orientation: 'portrait' as const },
        letter_landscape: { width: 279.4, height: 215.9, orientation: 'landscape' as const },
        letter_portrait: { width: 215.9, height: 279.4, orientation: 'portrait' as const },
    };
    const currentPaper =
        Object.entries(paperPresets).find(
            ([, paper]) => paper.width === draft.width && paper.height === draft.height,
        )?.[0] ?? 'custom';

    const numberField = (label: string, key: keyof CertificateTemplatePage) => (
        <TextField
            label={label}
            inputMode="decimal"
            type="number"
            step={key === 'width' || key === 'height' ? '0.01' : '1'}
            min={key === 'width' || key === 'height' ? 10 : 0}
            max={1000}
            disabled={readOnly}
            value={String(draft[key] ?? '')}
            onChange={(event) => editDraft(key as PageDraftField, Number(event.target.value))}
        />
    );

    const save = async () => {
        const attemptedEditVersion = editVersion.current;
        const sourceRevision = write.pinRevision(expectedRevision);
        const body = {
            width: draft.width,
            height: draft.height,
            orientation: draft.orientation,
            margin_top: draft.margin_top,
            margin_right: draft.margin_right,
            margin_bottom: draft.margin_bottom,
            margin_left: draft.margin_left,
            background: draft.background,
        };
        const attempt = write.capture(
            { pageId: page.id, body },
            () => ({
                templateId,
                pageId: page.id,
                body: { ...body, expected_revision: sourceRevision },
            }),
            { pageId: page.id, editVersion: attemptedEditVersion },
        );
        try {
            const saved = await update.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            if (
                editVersion.current === attemptedEditVersion &&
                saved.template_revision >= observedRevision.current
            ) {
                dirtyFields.current.clear();
                write.complete(attempt);
                setDirty(false);
            }
        } catch {
            /* The error remains beside the form with its local draft. */
        }
    };

    return (
        <div className="grid gap-2.5 rounded border border-border p-3">
            <SelectField
                label={studioStrings.paperPreset}
                value={currentPaper}
                disabled={readOnly}
                onChange={(event) => {
                    const paper = paperPresets[event.target.value as keyof typeof paperPresets];
                    if (paper !== undefined) {
                        editDraft('width', paper.width);
                        editDraft('height', paper.height);
                        editDraft('orientation', paper.orientation);
                    }
                }}
            >
                {Object.entries(studioStrings.paperPresets).map(([value, label]) => (
                    <option key={value} value={value}>
                        {label}
                    </option>
                ))}
            </SelectField>
            <div className="grid gap-2.5">
                {numberField(strings.pages.fields.width, 'width')}
                {numberField(strings.pages.fields.height, 'height')}
                <SelectField
                    label={strings.pages.fields.orientation}
                    disabled={readOnly}
                    value={draft.orientation}
                    onChange={(event) =>
                        editDraft('orientation', event.target.value as CertificateTemplatePage['orientation'])
                    }
                >
                    {Object.entries(strings.pages.orientations).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </SelectField>
            </div>
            <div className="grid grid-cols-2 gap-2.5">
                {numberField(strings.pages.fields.marginTop, 'margin_top')}
                {numberField(strings.pages.fields.marginRight, 'margin_right')}
                {numberField(strings.pages.fields.marginBottom, 'margin_bottom')}
                {numberField(strings.pages.fields.marginLeft, 'margin_left')}
            </div>
            <TextField
                label={strings.pages.fields.background}
                disabled={readOnly}
                value={draft.background ?? ''}
                onChange={(event) =>
                    editDraft('background', event.target.value === '' ? null : event.target.value)
                }
            />
            {update.isError && <ErrorState error={update.error} />}
            {!readOnly && (
                <div className="flex justify-end">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={update.isPending}
                        onClick={() => {
                            void save();
                        }}
                    >
                        {strings.pages.savePage}
                    </Button>
                </div>
            )}
        </div>
    );
}

export interface ElementsPanelProps {
    template: CertificateTemplateDetail;
    page: CertificateTemplatePage;
    elementTypes: readonly CertificateElementType[];
    tokens: readonly TokenSource[];
    readOnly: boolean;
    onDone: (message: string) => void;
    pageInspector?: ReactNode;
    preview?: ReactNode;
}

export function ElementsPanel({
    template,
    page,
    elementTypes,
    tokens,
    readOnly,
    onDone,
    pageInspector,
    preview,
}: ElementsPanelProps) {
    const announce = useAnnounce();
    const config = useAdminConfig();
    const idBase = useId();
    const navigation = useStudioNavigation();
    const designer = useCertificateDesigner('page_designer');
    const [surface, setSurface] = useState<'form' | 'designer'>('designer');
    const [removingElement, setRemovingElement] = useState<number | null>(null);
    const rootRef = useRef<HTMLElement>(null);
    const width = useContainerWidth(rootRef);
    const [selectedId, setSelectedId] = useState<number | null>(page.elements[0]?.id ?? null);

    const addElement = useAddTemplateElement();
    const removeElement = useDeleteTemplateElement();
    const reorderElements = useReorderTemplateElements();
    const updateElement = useUpdateTemplateElement();
    const addWrite = useCertificateWriteAttempt();
    const removeWrite = useCertificateWriteAttempt();
    const reorderWrite = useCertificateWriteAttempt();
    const placeWrite = useCertificateWriteAttempt();

    useEffect(() => {
        setSelectedId((current) =>
            current !== null && page.elements.some((element) => element.id === current)
                ? current
                : (page.elements[0]?.id ?? null),
        );
    }, [page]);

    const typeByKey = useMemo(() => new Map(elementTypes.map((type) => [type.key, type])), [elementTypes]);
    const selected = page.elements.find((element) => element.id === selectedId) ?? null;
    const busy =
        addElement.isPending ||
        removeElement.isPending ||
        reorderElements.isPending ||
        updateElement.isPending;

    const add = async (elementKey: string, at?: { posx: number; posy: number }) => {
        const label = typeByKey.get(elementKey)?.label ?? elementKey;
        const intent = { pageId: page.id, element: elementKey, ...(at ?? {}) };
        const attempt = addWrite.capture(intent, () => ({
            templateId: template.id,
            pageId: page.id,
            body: { expected_revision: template.revision, element: elementKey, ...(at ?? {}) },
        }));
        try {
            const created = await addElement.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            addWrite.complete(attempt);
            setSelectedId(created.item.id);
        } catch {
            return;
        }
        onDone(strings.elements.added(label));
        announce(strings.elements.added(label));
    };

    const reorder = async (ids: number[]) => {
        const attempt = reorderWrite.capture({ pageId: page.id, ids }, () => ({
            templateId: template.id,
            pageId: page.id,
            ids,
            expectedRevision: template.revision,
        }));
        try {
            await reorderElements.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            reorderWrite.complete(attempt);
        } catch {
            /* The banner renders the refusal from the mutation's own state. */
        }
    };

    const move = async (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= page.elements.length) {
            return;
        }
        await reorder(moved(page.elements, index, target).map((element) => element.id));
    };

    const place = (
        elementId: number,
        placement: { posx: number; posy: number; width?: number | null; height?: number | null },
    ) => {
        const attempt = placeWrite.capture({ elementId, placement }, () => ({
            templateId: template.id,
            pageId: page.id,
            elementId,
            body: { expected_revision: template.revision, ...placement },
        }));
        void updateElement
            .mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            })
            .then(() => placeWrite.complete(attempt))
            .catch(() => {
                /* The designer shares the mutation banner below. */
            });
    };

    const remove = (elementId: number) => {
        const attempt = removeWrite.capture({ pageId: page.id, elementId }, () => ({
            templateId: template.id,
            pageId: page.id,
            elementId,
            expectedRevision: template.revision,
        }));
        void removeElement
            .mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            })
            .then(() => {
                removeWrite.complete(attempt);
                setRemovingElement(null);
            })
            .catch(() => undefined);
    };

    const Designer = designer?.Designer ?? null;
    const selectElement = (id: number | null) => {
        if (id !== selectedId) navigation.requestLeave(() => setSelectedId(id));
    };

    return (
        <section ref={rootRef} aria-labelledby={`${idBase}-heading`} className="grid min-w-0 gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <h3 id={`${idBase}-heading`} className="font-display text-xs font-bold text-fg">
                    {strings.elements.heading}
                </h3>
                <span className="text-[11.5px] text-fg-2">
                    {strings.templates.elementCount(page.elements.length)}
                </span>
                {Designer !== null && (
                    <span className="ml-auto flex gap-1.5">
                        <Button
                            variant={surface === 'form' ? 'primary' : 'outline'}
                            size="sm"
                            aria-pressed={surface === 'form'}
                            onClick={() => setSurface('form')}
                        >
                            {strings.elements.editorTab}
                        </Button>
                        <Button
                            variant={surface === 'designer' ? 'primary' : 'outline'}
                            size="sm"
                            aria-pressed={surface === 'designer'}
                            onClick={() => setSurface('designer')}
                        >
                            {designer?.label ?? strings.elements.designerTab}
                        </Button>
                    </span>
                )}
            </div>

            {Designer === null && (
                <p className="text-[11.5px] text-fg-2">{strings.elements.designerMissing}</p>
            )}

            {(addElement.isError ||
                removeElement.isError ||
                reorderElements.isError ||
                updateElement.isError) && (
                <ErrorState
                    error={
                        addElement.error ??
                        removeElement.error ??
                        reorderElements.error ??
                        updateElement.error
                    }
                />
            )}

            <div
                className="vy-cert-editor-layout grid min-w-0 items-start gap-4"
                style={{
                    gridTemplateColumns:
                        width >= 1200 && surface === 'designer' && Designer !== null
                            ? '260px minmax(0, 1fr) 260px'
                            : width >= 780
                              ? '260px minmax(0, 1fr)'
                              : 'minmax(0, 1fr)',
                }}
            >
                <div className="grid min-w-0 gap-4">
                    {pageInspector}
                    <LayerList
                        elements={page.elements}
                        typeByKey={typeByKey}
                        selectedId={selectedId}
                        readOnly={readOnly}
                        busy={busy || navigation.dirty}
                        onSelect={selectElement}
                        onMove={(index, delta) => {
                            void move(index, delta);
                        }}
                        onRemove={setRemovingElement}
                    />
                    {!readOnly && (
                        <Palette
                            elementTypes={elementTypes}
                            busy={busy || navigation.dirty}
                            onAdd={(key) => {
                                void add(key);
                            }}
                        />
                    )}
                </div>
                <div className="vy-cert-canvas-stage" hidden={surface === 'form' || Designer === null}>
                    {Designer !== null && surface === 'designer' && (
                        <Designer
                            template={template}
                            page={page}
                            elementTypes={elementTypes}
                            tokens={tokens}
                            selectedElementId={selectedId}
                            onSelect={selectElement}
                            onPlace={place}
                            onReorder={(ids) => {
                                void reorder([...ids]);
                            }}
                            onAdd={(elementKey, at) => {
                                void add(elementKey, at);
                            }}
                            onRemove={(elementId) => {
                                setRemovingElement(elementId);
                            }}
                            busy={busy || navigation.dirty}
                            readOnly={readOnly || navigation.dirty}
                            locale={config.locale}
                            controlId={(local) => `${idBase}-${local}`}
                            announce={announce}
                        />
                    )}
                    {preview}
                </div>
                <div
                    className="min-w-0 rounded-lg border border-border bg-surface p-3"
                    style={
                        surface === 'form' || Designer === null
                            ? { gridColumn: width >= 780 ? '2 / -1' : undefined }
                            : width >= 780 && width < 1200
                              ? { gridColumn: '1 / -1' }
                              : undefined
                    }
                >
                    {selected === null ? (
                        <EmptyState title={strings.elements.empty} />
                    ) : (
                        <ElementEditor
                            key={selected.id}
                            templateId={template.id}
                            pageId={page.id}
                            expectedRevision={template.revision}
                            element={selected}
                            type={typeByKey.get(selected.element) ?? null}
                            readOnly={readOnly}
                            onDone={onDone}
                        />
                    )}
                </div>
            </div>
            <Dialog
                open={removingElement !== null}
                onOpenChange={(open) => {
                    if (!open && !removeElement.isPending) setRemovingElement(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{studioStrings.removeElementTitle}</DialogTitle>
                    <DialogDescription>{studioStrings.removeElementBody}</DialogDescription>
                    {removeElement.isError && <ErrorState error={removeElement.error} />}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            disabled={removeElement.isPending}
                            onClick={() => setRemovingElement(null)}
                        >
                            {studioStrings.cancel}
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={removeElement.isPending || navigation.dirty}
                            onClick={() => {
                                if (removingElement !== null) remove(removingElement);
                            }}
                        >
                            {studioStrings.remove}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function LayerList({
    elements,
    typeByKey,
    selectedId,
    readOnly,
    busy,
    onSelect,
    onMove,
    onRemove,
}: {
    elements: readonly CertificateTemplateElement[];
    typeByKey: Map<string, CertificateElementType>;
    selectedId: number | null;
    readOnly: boolean;
    busy: boolean;
    onSelect: (id: number) => void;
    onMove: (index: number, delta: number) => void;
    onRemove: (id: number) => void;
}) {
    if (elements.length === 0) {
        return <p className="text-[12px] text-fg-2">{strings.elements.empty}</p>;
    }
    return (
        <div className="grid gap-1.5">
            <p className="text-[11.5px] text-fg-2">{strings.elements.drawOrder}</p>
            <ul className="grid gap-1">
                {elements.map((element, index) => {
                    const label = element.name ?? typeByKey.get(element.element)?.label ?? element.element;
                    return (
                        <li key={element.id} className="vy-cert-layer-row">
                            <Button
                                variant={element.id === selectedId ? 'primary' : 'outline'}
                                size="sm"
                                aria-pressed={element.id === selectedId}
                                title={label}
                                className="vy-cert-layer-select"
                                onClick={() => onSelect(element.id)}
                            >
                                <span>{label}</span>
                            </Button>
                            {!element.available && <Badge variant="warn">{strings.tokens.unavailable}</Badge>}
                            {!readOnly && (
                                <span className="vy-cert-layer-actions">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={index === 0 || busy}
                                        aria-label={strings.elements.moveUpNamed(label)}
                                        title={strings.elements.moveUpNamed(label)}
                                        className="vy-cert-icon-action"
                                        onClick={() => onMove(index, -1)}
                                    >
                                        <Icon icon={ArrowUp} size={16} />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={index === elements.length - 1 || busy}
                                        aria-label={strings.elements.moveDownNamed(label)}
                                        title={strings.elements.moveDownNamed(label)}
                                        className="vy-cert-icon-action"
                                        onClick={() => onMove(index, 1)}
                                    >
                                        <Icon icon={ArrowDown} size={16} />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={busy}
                                        aria-label={strings.elements.removeNamed(label)}
                                        title={strings.elements.removeNamed(label)}
                                        className="vy-cert-icon-action"
                                        onClick={() => onRemove(element.id)}
                                    >
                                        <Icon icon={Trash2} size={16} />
                                    </Button>
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function Palette({
    elementTypes,
    busy,
    onAdd,
}: {
    elementTypes: readonly CertificateElementType[];
    busy: boolean;
    onAdd: (key: string) => void;
}) {
    const groups = useMemo(() => {
        const byGroup = new Map<string, CertificateElementType[]>();
        for (const type of [...elementTypes].sort((a, b) => a.order - b.order)) {
            const bucket = byGroup.get(type.group) ?? [];
            bucket.push(type);
            byGroup.set(type.group, bucket);
        }
        return [...byGroup.entries()];
    }, [elementTypes]);

    return (
        <div className="grid gap-2 rounded border border-border p-3">
            <p className="font-display text-[11.5px] font-bold text-fg">{strings.elements.palette}</p>
            <p className="text-[11.5px] text-fg-2">{strings.elements.paletteHint}</p>
            {groups.map(([group, types]) => (
                <div key={group} className="grid gap-1">
                    <p className="font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2">
                        {group}
                    </p>
                    <ul className="flex flex-wrap gap-1.5">
                        {types.map((type) => (
                            <li key={type.key}>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy || !type.available}
                                    aria-label={strings.elements.add(type.label)}
                                    title={
                                        type.available
                                            ? undefined
                                            : (type.unavailable_reason ?? strings.elements.unavailableGeneric)
                                    }
                                    onClick={() => onAdd(type.key)}
                                >
                                    {type.label}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

const REFPOINTS: CertificateTemplateElement['refpoint'][] = [
    'top_left',
    'top_centre',
    'top_right',
    'centre',
    'bottom_left',
    'bottom_centre',
    'bottom_right',
];

const ALIGNMENTS: CertificateTemplateElement['alignment'][] = ['left', 'centre', 'right', 'justify'];

type ElementLayout = Pick<
    CertificateTemplateElement,
    'refpoint' | 'alignment'
> & Record<'posx' | 'posy' | 'width' | 'height', string>;

type ElementDraftField = keyof ElementLayout | 'name' | 'data';

function elementLayout(element: CertificateTemplateElement): ElementLayout {
    return {
        posx: String(element.posx),
        posy: String(element.posy),
        width: element.width === null ? '' : String(element.width),
        height: element.height === null ? '' : String(element.height),
        refpoint: element.refpoint,
        alignment: element.alignment,
    };
}

function ElementEditor({
    templateId,
    pageId,
    expectedRevision,
    element,
    type,
    readOnly,
    onDone,
}: {
    templateId: number;
    pageId: number;
    expectedRevision: number;
    element: CertificateTemplateElement;
    type: CertificateElementType | null;
    readOnly: boolean;
    onDone: (message: string) => void;
}) {
    const announce = useAnnounce();
    const update = useUpdateTemplateElement();
    const write = useCertificateWriteAttempt();
    const setDirty = useStudioDraft(`element-${element.id}`);
    const groups = useMemo(() => type?.groups ?? [], [type]);
    const [name, setName] = useState(element.name ?? '');
    const [layout, setLayout] = useState<ElementLayout>(() => elementLayout(element));
    const [payload, setPayload] = useState<ElementPayload>(() =>
        seedElementPayload(groups, element.data ?? {}),
    );
    const dirtyFields = useRef<Set<ElementDraftField>>(new Set());
    const editVersion = useRef(0);
    const observedRevision = useRef(expectedRevision);
    observedRevision.current = Math.max(observedRevision.current, expectedRevision);

    useEffect(() => {
        const nextLayout = elementLayout(element);
        setName((current) => (dirtyFields.current.has('name') ? current : (element.name ?? '')));
        setLayout((current) => ({
            posx: dirtyFields.current.has('posx') ? current.posx : nextLayout.posx,
            posy: dirtyFields.current.has('posy') ? current.posy : nextLayout.posy,
            width: dirtyFields.current.has('width') ? current.width : nextLayout.width,
            height: dirtyFields.current.has('height') ? current.height : nextLayout.height,
            refpoint: dirtyFields.current.has('refpoint') ? current.refpoint : nextLayout.refpoint,
            alignment: dirtyFields.current.has('alignment') ? current.alignment : nextLayout.alignment,
        }));
        setPayload((current) =>
            dirtyFields.current.has('data') ? current : seedElementPayload(groups, element.data ?? {}),
        );
    }, [element, groups]);

    const markEdited = (field: ElementDraftField) => {
        setDirty(true);
        write.pinRevision(expectedRevision);
        dirtyFields.current.add(field);
        editVersion.current += 1;
    };

    /* A payload above the version the provider declares is a miss and is
       treated exactly as an unregistered key, because a resolvable key
       carrying an unsupported payload version is the case a plain
       availability check cannot see. */
    const futureShape = type !== null && element.data_version > type.current_version;
    const editable = element.available && type !== null && !futureShape;
    const validPosition = (value: string) => value.trim() !== '' && Number.isSafeInteger(Number(value)) && Number(value) >= 0;
    const validSize = (value: string) => value.trim() === '' || (Number.isSafeInteger(Number(value)) && Number(value) > 0);
    const validLayout = validPosition(layout.posx) && validPosition(layout.posy)
        && validSize(layout.width) && validSize(layout.height);

    const save = async () => {
        if (!validLayout) return;
        const attemptedEditVersion = editVersion.current;
        const sourceRevision = write.pinRevision(expectedRevision);
        const body = {
            name: name.trim() === '' ? null : name.trim(),
            ...layout,
            posx: Number(layout.posx),
            posy: Number(layout.posy),
            width: layout.width.trim() === '' ? null : Number(layout.width),
            height: layout.height.trim() === '' ? null : Number(layout.height),
            // Moving a draft must not validate unfinished, unchanged content.
            ...(editable && dirtyFields.current.has('data')
                ? { data: payload, data_version: type.current_version } : {}),
        };
        const attempt = write.capture(
            { elementId: element.id, body },
            () => ({
                templateId,
                pageId,
                elementId: element.id,
                body: { expected_revision: sourceRevision, ...body },
            }),
            { elementId: element.id, editVersion: attemptedEditVersion },
        );
        try {
            const saved = await update.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            if (
                editVersion.current === attemptedEditVersion &&
                saved.template_revision >= observedRevision.current
            ) {
                dirtyFields.current.clear();
                write.complete(attempt);
                setDirty(false);
            }
        } catch {
            return;
        }
        onDone(strings.elements.saved);
        announce(strings.elements.saved);
    };

    return (
        <div className="grid gap-3 rounded border border-border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-display text-xs font-bold text-fg">{type?.label ?? element.element}</p>
                <code className="font-mono text-[11px] text-fg-2">{element.element}</code>
                {type?.package !== undefined && type.package !== null && (
                    <span className="text-[11px] text-fg-2">
                        {strings.elements.packageLabel(type.package)}
                    </span>
                )}
            </div>
            {!element.available && (
                <p className="text-[11.5px] text-warn-strong">{strings.elements.missingProvider}</p>
            )}
            {futureShape && <p className="text-[11.5px] text-warn-strong">{strings.elements.futureShape}</p>}
            {type?.contributes_to !== undefined && type.contributes_to !== null && (
                <p className="text-[11.5px] text-fg-2">{strings.elements.contributes(type.contributes_to)}</p>
            )}

            <TextField
                label={strings.elements.fields.name}
                hint={strings.elements.fields.nameHint}
                disabled={readOnly}
                value={name}
                onChange={(event) => {
                    markEdited('name');
                    setName(event.target.value);
                }}
            />

            {editable && groups.length > 0 && (
                <ElementSettingsForm
                    groups={groups}
                    values={payload}
                    disabled={readOnly}
                    onChange={(values) => {
                        markEdited('data');
                        setPayload(values);
                    }}
                />
            )}

            <details className="vy-cert-element-layout">
                <summary>{ws.placement}</summary>
                <p className="text-[11.5px] text-fg-2">{strings.elements.fields.placementHint}</p>
                <div className="grid grid-cols-2 gap-2.5">
                    <TextField
                        label={strings.elements.fields.posx}
                        inputMode="numeric"
                        error={validPosition(layout.posx) ? undefined : strings.elements.fields.positionInvalid}
                        disabled={readOnly}
                        value={layout.posx}
                        onChange={(event) => {
                            markEdited('posx');
                            setLayout((current) => ({ ...current, posx: event.target.value }));
                        }}
                    />
                    <TextField
                        label={strings.elements.fields.posy}
                        inputMode="numeric"
                        error={validPosition(layout.posy) ? undefined : strings.elements.fields.positionInvalid}
                        disabled={readOnly}
                        value={layout.posy}
                        onChange={(event) => {
                            markEdited('posy');
                            setLayout((current) => ({ ...current, posy: event.target.value }));
                        }}
                    />
                    <TextField
                        label={strings.elements.fields.width}
                        inputMode="numeric"
                        error={validSize(layout.width) ? undefined : strings.elements.fields.sizeInvalid}
                        disabled={readOnly}
                        value={layout.width}
                        onChange={(event) => {
                            markEdited('width');
                            setLayout((current) => ({
                                ...current,
                                width: event.target.value,
                            }));
                        }}
                    />
                    <TextField
                        label={strings.elements.fields.height}
                        inputMode="numeric"
                        error={validSize(layout.height) ? undefined : strings.elements.fields.sizeInvalid}
                        disabled={readOnly}
                        value={layout.height}
                        onChange={(event) => {
                            markEdited('height');
                            setLayout((current) => ({
                                ...current,
                                height: event.target.value,
                            }));
                        }}
                    />
                </div>

                <div className="grid gap-2.5">
                    <SelectField
                        label={strings.elements.fields.refpoint}
                        disabled={readOnly}
                        value={layout.refpoint}
                        onChange={(event) => {
                            markEdited('refpoint');
                            setLayout((current) => ({
                                ...current,
                                refpoint: event.target.value as CertificateTemplateElement['refpoint'],
                            }));
                        }}
                    >
                        {REFPOINTS.map((point) => (
                            <option key={point} value={point}>
                                {strings.elements.refpoints[point]}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField
                        label={strings.elements.fields.alignment}
                        disabled={readOnly}
                        value={layout.alignment}
                        onChange={(event) => {
                            markEdited('alignment');
                            setLayout((current) => ({
                                ...current,
                                alignment: event.target.value as CertificateTemplateElement['alignment'],
                            }));
                        }}
                    >
                        {ALIGNMENTS.map((alignment) => (
                            <option key={alignment} value={alignment}>
                                {strings.elements.alignments[alignment]}
                            </option>
                        ))}
                    </SelectField>
                </div>
            </details>

            {update.isError && <ErrorState error={update.error} />}

            {!readOnly && (
                <div className="flex justify-end">
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={update.isPending || !validLayout}
                        onClick={() => {
                            void save();
                        }}
                    >
                        {strings.elements.save}
                    </Button>
                </div>
            )}
        </div>
    );
}
