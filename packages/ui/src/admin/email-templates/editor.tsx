import { translate as uiText } from '@/shared/i18n';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { useEffect, useId, useRef, useState, type ChangeEvent, type FocusEvent } from 'react';
import { ArrowLeft, ArrowUp, ArrowDown, Eye, Mail, Palette, Settings2, Plus, Send, Trash2 } from 'lucide-react';
import { ProblemError } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    Card,
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Icon,
    Skeleton,
} from '@/shared/components';
import { cn } from '@/shared/cn';
import { ScreenError } from '../components/states';
import {
    newIdempotencyKey,
    useDiscardNotificationTemplateDraft,
    useNotificationTemplateDetail,
    usePreviewNotificationTemplate,
    usePublishNotificationTemplateDraft,
    useSaveNotificationTemplateDraft,
    useTestSendNotificationTemplate,
    type NotificationTemplateDetail,
    type NotificationTemplateIdentity,
    type NotificationTemplatePreview,
    type NotificationTemplateSource,
} from './api';
import { emailTemplateStrings as strings } from './strings';
import { emailTemplateHash } from './routes';
import { languageName, templateLabel } from './workspace';
import { DeliveryDiagnostics, type BlueprintProvenance, type DeliveryState } from './delivery-diagnostics';

type TextSourceKey =
    | 'subject'
    | 'preheader'
    | 'heading'
    | 'action_label'
    | 'action_url'
    | 'footer'
    | 'logo_url'
    | 'logo_alt'
    | 'accent_color'
    | 'short_body';

type PreviewTab = 'desktop' | 'mobile' | 'plain';

export function sourceForAuthoring(detail: NotificationTemplateDetail): NotificationTemplateSource {
    return structuredClone(detail.draft_source ?? detail.published_source ?? detail.default_source);
}

function normalisedSource(source: NotificationTemplateSource): NotificationTemplateSource {
    const optional = (value: string | null | undefined): string | null => {
        const trimmed = value?.trim() ?? '';
        return trimmed === '' ? null : trimmed;
    };
    return {
        ...source,
        subject: source.subject.trim(),
        preheader: source.preheader.trim(),
        heading: source.heading.trim(),
        paragraphs: source.paragraphs.map((paragraph) => paragraph.trim()),
        action_label: optional(source.action_label),
        action_url: optional(source.action_url),
        footer: source.footer.trim(),
        logo_url: optional(source.logo_url),
        logo_alt: optional(source.logo_alt),
        accent_color: source.accent_color.trim(),
        short_body: optional(source.short_body),
    };
}

function exactKey(identity: NotificationTemplateIdentity): string {
    return `${identity.templateKey}\u0000${identity.locale}\u0000${identity.channelKey}`;
}

function fieldClass(multiline = false): string {
    return cn(
        'w-full rounded border border-border bg-bg px-3 text-[13px] text-fg placeholder:text-fg-2',
        multiline ? 'min-h-[88px] py-2.5' : 'h-10',
    );
}

function LabeledField({
    label,
    hint,
    children,
}: {
    label: string;
    hint?: string;
    children: (id: string, describedBy: string | undefined) => React.ReactNode;
}) {
    const id = useId();
    const hintId = hint === undefined ? undefined : `${id}-hint`;
    return (
        <div className="grid min-w-0 flex-1 gap-1.5 text-[12px] font-medium text-fg">
            <label htmlFor={id}>{label}</label>
            {children(id, hintId)}
            {hint !== undefined && (
                <span id={hintId} className="text-[10.5px] font-normal text-fg-2">
                    {hint}
                </span>
            )}
        </div>
    );
}

function MessagePreview({
    preview,
    loading,
    tab,
    onTabChange,
}: {
    preview: NotificationTemplatePreview | null;
    loading: boolean;
    tab: PreviewTab;
    onTabChange: (tab: PreviewTab) => void;
}) {
    const editor = strings.editor;
    const diagnostics = preview as (NotificationTemplatePreview & Partial<DeliveryState>) | null;
    return (
        <Card className="vy-email-preview min-w-0 overflow-hidden">
            <div className="flex flex-wrap items-start gap-3 border-b border-border px-4 py-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <h3 className="font-display text-sm font-semibold text-fg">{editor.preview}</h3>
                        <Badge variant="accent">{editor.sample}</Badge>
                    </div>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{editor.previewIntro}</p>
                </div>
            </div>
            {diagnostics !== null && (
                <div className="border-b border-border p-3">
                    <DeliveryDiagnostics
                        ready={diagnostics.delivery_ready}
                        warnings={diagnostics.delivery_warnings ?? []}
                    />
                </div>
            )}
            <div role="tablist" aria-label={editor.preview} className="flex gap-1 border-b border-border p-2">
                {(['desktop', 'mobile', 'plain'] as const).map((value) => (
                    <button
                        key={value}
                        type="button"
                        role="tab"
                        aria-selected={tab === value}
                        onClick={() => onTabChange(value)}
                        className={cn(
                            'h-[34px] cursor-pointer rounded px-3 font-display text-xs font-semibold',
                            tab === value ? 'bg-fg text-bg' : 'text-fg-2 hover:bg-surface-2 hover:text-fg',
                        )}
                    >
                        {editor[value]}
                    </button>
                ))}
            </div>
            <div className="min-h-[360px] overflow-auto bg-surface-2 p-3 sm:p-4">
                {preview === null ? (
                    <p
                        role="status"
                        className="rounded border border-dashed border-border bg-surface p-4 text-center text-[12px] text-fg-2"
                    >
                        {loading ? strings.workspace.rendering : editor.noPreview}
                    </p>
                ) : tab === 'plain' ? (
                    <pre className="whitespace-pre-wrap break-words rounded border border-border bg-surface p-4 font-mono text-[11.5px] text-fg">
                        {preview.body_text || preview.body_short || ''}
                    </pre>
                ) : (
                    <div
                        className={cn(
                            'mx-auto transition-[max-width] duration-2',
                            tab === 'mobile' ? 'max-w-[390px]' : 'max-w-[760px]',
                        )}
                    >
                        <iframe
                            title={editor.previewFrame}
                            sandbox=""
                            referrerPolicy="no-referrer"
                            srcDoc={preview.body_html ?? ''}
                            className="h-[620px] w-full rounded border border-border bg-white"
                        />
                    </div>
                )}
            </div>
        </Card>
    );
}

function LegacyPreview({ detail }: { detail: NotificationTemplateDetail }) {
    const html = detail.live_parts?.body_html;
    const text = detail.live_parts?.body_text ?? detail.live_parts?.body_short ?? '';
    return (
        <div className="grid gap-3 rounded-lg border border-border bg-surface p-4">
            <div>
                <h3 className="font-display text-sm font-semibold text-fg">{strings.editor.legacyTitle}</h3>
                <p className="mt-1 text-[12px] text-fg-2">{strings.editor.legacyBody}</p>
            </div>
            {html !== null && html !== undefined && html !== '' ? (
                <iframe
                    title={strings.editor.legacyTitle}
                    sandbox=""
                    referrerPolicy="no-referrer"
                    srcDoc={html}
                    className="h-[420px] w-full rounded border border-border bg-white"
                />
            ) : (
                <pre className="max-h-[420px] overflow-auto whitespace-pre-wrap rounded border border-border bg-bg p-3 font-mono text-[11.5px] text-fg">
                    {text}
                </pre>
            )}
        </div>
    );
}

function Confirmation({
    open,
    onOpenChange,
    title,
    body,
    confirm,
    destructive = false,
    pending,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    body: string;
    confirm: string;
    destructive?: boolean;
    pending: boolean;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="vy-email-dialog">
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{body}</DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">{strings.workspace.cancel}</Button>
                    </DialogClose>
                    <Button variant={destructive ? 'destructive' : 'primary'} disabled={pending} onClick={onConfirm}>
                        {confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function EmailTemplateEditor({
    identity,
    navigate,
    onDirtyChange,
}: {
    identity: NotificationTemplateIdentity;
    navigate: (hash: string) => void;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    const detailQuery = useNotificationTemplateDetail(identity);
    const save = useSaveNotificationTemplateDraft();
    const discard = useDiscardNotificationTemplateDraft();
    const publish = usePublishNotificationTemplateDraft();
    const preview = usePreviewNotificationTemplate();
    const testSend = useTestSendNotificationTemplate();
    const [source, setSource] = useState<NotificationTemplateSource | null>(null);
    const [dirty, setDirty] = useState(false);
    const [translationLocale, setTranslationLocale] = useState(identity.locale);
    const [expectedRevision, setExpectedRevision] = useState(0);
    const [professionalStarted, setProfessionalStarted] = useState(false);
    const [panel, setPanel] = useState('message');
    const [tokenSearch, setTokenSearch] = useState('');
    const [previewTab, setPreviewTab] = useState<PreviewTab>('desktop');
    const [notice, setNotice] = useState<string | null>(null);
    const [discardOpen, setDiscardOpen] = useState(false);
    const [publishOpen, setPublishOpen] = useState(false);
    const initializedIdentity = useRef<string | null>(null);
    const lastFocused = useRef<HTMLInputElement | HTMLTextAreaElement | null>(null);
    const heading = useRef<HTMLHeadingElement | null>(null);
    const mutationKeys = useRef<Record<'save' | 'discard' | 'publish' | 'test', string | null>>({
        save: null,
        discard: null,
        publish: null,
        test: null,
    });
    const identityKey = exactKey(identity);
    const mutationFingerprints = useRef<Record<string, string>>({});
    const renderPreview = preview.mutate;
    const renderedSource = preview.variables?.source;
    const detail = detailQuery.data;
    const editor = strings.editor;

    const applyServer = (next: NotificationTemplateDetail) => {
        setSource(sourceForAuthoring(next));
        setExpectedRevision(next.draft_revision ?? 0);
        setDirty(false);
        setProfessionalStarted(
            !(next.legacy_published && next.draft_source === null && next.published_source === null),
        );
        setNotice(null);
        save.reset();
        discard.reset();
        publish.reset();
        preview.reset();
        testSend.reset();
        mutationKeys.current = { save: null, discard: null, publish: null, test: null };
    };

    useEffect(() => {
        initializedIdentity.current = null;
        setSource(null);
        setDirty(false);
        heading.current?.focus();
        setTranslationLocale(identity.locale);
    }, [identityKey, identity.locale]);

    useEffect(() => {
        if (detail !== undefined && initializedIdentity.current !== identityKey) {
            initializedIdentity.current = identityKey;
            applyServer(detail);
            requestAnimationFrame(() => heading.current?.focus());
        }
        // applyServer intentionally acts only once per exact route identity.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [detail, identityKey]);

    useEffect(() => {
        if (!dirty) {
            return undefined;
        }
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    useEffect(() => {
        if (!source || !detail || detail.available === false || (detail.legacy_published && !professionalStarted))
            return;
        if (JSON.stringify(renderedSource) === JSON.stringify(normalisedSource(source))) return;
        const timer = window.setTimeout(() => renderPreview({ identity, source: normalisedSource(source) }), 700);
        return () => window.clearTimeout(timer);
    }, [source, detail, identity, professionalStarted, renderPreview, renderedSource]);

    useNavigationGuard(dirty);
    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

    if (detailQuery.isPending || detail === undefined || source === null) {
        if (detailQuery.isError) {
            const status = detailQuery.error instanceof ProblemError ? detailQuery.error.status : 0;
            return (
                <div className="grid gap-3">
                    <Button className="w-fit" variant="ghost" size="sm" onClick={() => navigate('/')}>
                        <Icon icon={ArrowLeft} size={14} />
                        {editor.back}
                    </Button>
                    {status === 404 ? (
                        <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                            <h2 className="font-display text-lg font-semibold text-fg">{editor.missingTitle}</h2>
                            <p className="mt-1 text-[12.5px] text-fg-2">{editor.missingBody}</p>
                        </section>
                    ) : (
                        <ScreenError error={detailQuery.error} onRetry={() => void detailQuery.refetch()} />
                    )}
                </div>
            );
        }
        return <Skeleton className="h-[620px] w-full" aria-label={editor.loading} />;
    }

    const isLegacyOnly = detail.legacy_published && detail.draft_source === null && detail.published_source === null;
    const studioDetail = detail as NotificationTemplateDetail & Partial<DeliveryState & BlueprintProvenance>;
    const editable = detail.available !== false;
    // A background cache refresh must not silently adopt another author's
    // revision while our local source still contains the older document.
    const isConflict = [save.error, discard.error, publish.error].some(
        (error) => error instanceof ProblemError && error.status === 409,
    );
    const pending = save.isPending || discard.isPending || publish.isPending;

    const updateField = (key: TextSourceKey, value: string) => {
        setSource((current) => (current === null ? current : { ...current, [key]: value }));
        setDirty(true);
        setNotice(null);
        mutationKeys.current.save = null;
    };
    const updateParagraph = (index: number, value: string) => {
        setSource((current) => {
            if (current === null) return current;
            const paragraphs = [...current.paragraphs];
            paragraphs[index] = value;
            return { ...current, paragraphs };
        });
        setDirty(true);
        setNotice(null);
        mutationKeys.current.save = null;
    };
    const focusField = (event: FocusEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        lastFocused.current = event.currentTarget;
    };
    const insertToken = (key: string) => {
        const target = lastFocused.current;
        const token = `{${key}}`;
        if (target === null || !target.isConnected) {
            updateField('subject', `${source.subject}${token}`);
            return;
        }
        const start = target.selectionStart ?? target.value.length;
        const end = target.selectionEnd ?? start;
        const next = `${target.value.slice(0, start)}${token}${target.value.slice(end)}`;
        const name = target.dataset.sourceField;
        if (name?.startsWith('paragraph:')) {
            updateParagraph(Number(name.slice('paragraph:'.length)), next);
        } else if (name !== undefined) {
            updateField(name as TextSourceKey, next);
        }
        requestAnimationFrame(() => {
            target.focus();
            target.setSelectionRange(start + token.length, start + token.length);
        });
    };
    const handleInput = (key: TextSourceKey) => (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        updateField(key, event.target.value);
    };
    const operationKey = (operation: keyof typeof mutationKeys.current): string => {
        const fingerprint = JSON.stringify([
            identityKey,
            expectedRevision,
            operation === 'test' || operation === 'save' ? normalisedSource(source) : null,
        ]);
        const existing = mutationKeys.current[operation];
        if (existing !== null && mutationFingerprints.current[operation] === fingerprint) return existing;
        const key = newIdempotencyKey();
        mutationKeys.current[operation] = key;
        mutationFingerprints.current[operation] = fingerprint;
        return key;
    };
    const settledError = (operation: keyof typeof mutationKeys.current, error: unknown) => {
        /* A problem response is definitive. A network failure is uncertain,
           so the next explicit retry reuses the same key. */
        if (error instanceof ProblemError) mutationKeys.current[operation] = null;
    };
    const saveDraft = () =>
        save.mutate(
            {
                identity,
                source: normalisedSource(source),
                expectedRevision,
                idempotencyKey: operationKey('save'),
            },
            {
                onSuccess: (next) => {
                    applyServer(next);
                    setNotice(editor.saved);
                },
                onError: (error) => settledError('save', error),
            },
        );
    const reload = async () => {
        const result = await detailQuery.refetch();
        if (result.data !== undefined) {
            applyServer(result.data);
        }
    };

    const status = detail.status ?? (detail.has_draft ? 'draft_changes' : detail.state);
    const fallback = detail.using_fallback && detail.resolved_locale !== null;

    const field = (key: TextSourceKey, label: string, multiline = false) => (
        <LabeledField label={label}>
            {(id, describedBy) =>
                multiline ? (
                    <textarea
                        id={id}
                        aria-describedby={describedBy}
                        disabled={!editable || pending}
                        data-source-field={key}
                        value={source[key] ?? ''}
                        onFocus={focusField}
                        onChange={handleInput(key)}
                        className={fieldClass(true)}
                    />
                ) : (
                    <input
                        id={id}
                        aria-describedby={describedBy}
                        disabled={!editable || pending}
                        data-source-field={key}
                        value={source[key] ?? ''}
                        onFocus={focusField}
                        onChange={handleInput(key)}
                        className={fieldClass()}
                    />
                )
            }
        </LabeledField>
    );
    const translationControls = (
        <div className="vy-email-panel">
            <LabeledField label={editor.translationLocale} hint={editor.translationHint}>
                {(id, hint) => (
                    <input
                        id={id}
                        aria-describedby={hint}
                        disabled={pending}
                        value={translationLocale}
                        maxLength={20}
                        onChange={(e) => setTranslationLocale(e.target.value)}
                        className={fieldClass()}
                    />
                )}
            </LabeledField>
            <Button
                className="justify-self-start"
                variant="outline"
                disabled={
                    pending ||
                    !/^[a-z]{2,3}(?:-[a-z0-9]{1,8})*$/i.test(translationLocale.trim()) ||
                    translationLocale.trim().toLowerCase() === identity.locale.toLowerCase()
                }
                onClick={() =>
                    navigate(
                        emailTemplateHash({
                            ...identity,
                            locale: translationLocale.trim(),
                        }),
                    )
                }
            >
                {editor.openTranslation}
            </Button>
        </div>
    );
    const moveParagraph = (index: number, direction: number) => {
        const paragraphs = [...source.paragraphs];
        const target = index + direction;
        if (target < 0 || target >= paragraphs.length) return;
        [paragraphs[index], paragraphs[target]] = [paragraphs[target]!, paragraphs[index]!];
        setSource({ ...source, paragraphs });
        setDirty(true);
        setNotice(null);
        mutationKeys.current.save = null;
        lastFocused.current = null;
    };
    return (
        <div className="vy-email-editor">
            <Button className="vy-email-back" variant="ghost" size="sm" onClick={() => navigate('/')}>
                <Icon icon={ArrowLeft} size={14} />
                {editor.back}
            </Button>
            <header className="vy-email-editor-heading">
                <span className="vy-email-heading-icon">
                    <Mail size={24} />
                </span>
                <div>
                    <h1 ref={heading} tabIndex={-1}>
                        {templateLabel(detail.label?.trim() || editor.title)}
                    </h1>
                    <p>{strings.workspace.editorIntro}</p>
                    <div className="vy-email-meta">
                        <Badge variant={status === 'published' ? 'ok' : status === 'draft_changes' ? 'warn' : 'accent'}>
                            {status === 'published'
                                ? editor.published
                                : status === 'draft_changes'
                                  ? editor.draftChanges
                                  : editor.draftOnly}
                        </Badge>
                        <span>{languageName(identity.locale)}</span>
                        <span>{templateLabel(identity.channelKey)}</span>
                    </div>
                </div>
            </header>
            <div className="vy-email-savebar">
                <span className={dirty ? 'vy-email-unsaved' : ''}>
                    {dirty
                        ? strings.workspace.unsaved
                        : detail.has_draft
                          ? strings.workspace.draftState
                          : fallback
                            ? strings.workspace.fallbackState
                            : detail.published_source || isLegacyOnly
                              ? strings.workspace.publishedState
                              : strings.workspace.defaultState}
                </span>
                <div>
                    <Button
                        variant="ghost"
                        disabled={pending || !detail.has_draft}
                        onClick={() => setDiscardOpen(true)}
                    >
                        {editor.discard}
                    </Button>
                    <Button
                        variant="outline"
                        disabled={!editable || pending || !dirty || (isLegacyOnly && !professionalStarted)}
                        onClick={saveDraft}
                    >
                        {editor.saveDraft}
                    </Button>
                    <Button
                        disabled={!editable || pending || !detail.has_draft || dirty}
                        title={dirty ? editor.publishHint : undefined}
                        onClick={() => setPublishOpen(true)}
                    >
                        {editor.publish}
                    </Button>
                </div>
            </div>
            {notice !== null && (
                <div role="status" className="vy-email-success">
                    {notice}
                </div>
            )}
            {fallback && (
                <div role="status" className="vy-email-notice">
                    <strong>{editor.fallbackTitle(detail.resolved_locale as string)}</strong> {editor.fallbackBody}
                </div>
            )}
            {!fallback && !detail.published_source && !isLegacyOnly && !detail.draft_source && (
                <div role="status" className="vy-email-notice">
                    {editor.defaultBody}
                </div>
            )}
            {!editable && (
                <div role="status" className="vy-email-notice">
                    <strong>{editor.unavailableTitle}</strong>{' '}
                    {studioDetail.unavailable_reason ?? editor.unavailableBody}
                </div>
            )}
            {isConflict && (
                <div role="alert" className="vy-email-notice">
                    <strong>{editor.conflictTitle}</strong>
                    <p>{editor.conflictBody}</p>
                    <Button variant="outline" onClick={() => void reload()}>
                        {editor.reload}
                    </Button>
                </div>
            )}
            {save.isError && !isConflict && <ErrorState error={save.error} />}
            {discard.isError && !isConflict && <ErrorState error={discard.error} />}
            {publish.isError && !isConflict && <ErrorState error={publish.error} />}
            {isLegacyOnly && !professionalStarted ? (
                <>
                    <Card className="vy-email-section">{translationControls}</Card>
                    <LegacyPreview detail={detail} />
                    <Button
                        className="justify-self-start"
                        disabled={!editable}
                        onClick={() => {
                            setSource(structuredClone(detail.default_source));
                            setProfessionalStarted(true);
                            setDirty(true);
                            mutationKeys.current.save = null;
                        }}
                    >
                        {editor.startProfessional}
                    </Button>
                </>
            ) : (
                <>
                    <nav className="vy-email-tabs" aria-label={editor.title}>
                        {[
                            { key: 'message', label: strings.workspace.message, Icon: Mail },
                            { key: 'branding', label: strings.workspace.branding, Icon: Palette },
                            { key: 'delivery', label: strings.workspace.delivery, Icon: Settings2 },
                        ].map(({ key, label, Icon: TabIcon }) => (
                            <button
                                key={key}
                                aria-current={panel === key ? 'page' : undefined}
                                onClick={() => setPanel(key)}
                            >
                                <TabIcon size={16} />
                                {label}
                            </button>
                        ))}
                    </nav>
                    <div className="vy-email-studio-grid">
                        <div className="vy-email-studio-fields">
                            <Card className="vy-email-section">
                                <fieldset className="vy-email-fields" disabled={pending}>
                                    <section hidden={panel !== 'message'} className="vy-email-panel">
                                        <div className="vy-email-section-title">
                                            <h2>{strings.workspace.messageTitle}</h2>
                                            <p>{strings.workspace.messageHint}</p>
                                        </div>
                                        {field('subject', editor.fields.subject)}
                                        {field('preheader', editor.fields.preheader)}
                                        {field('heading', editor.fields.heading)}
                                        <fieldset className="vy-email-paragraphs" disabled={!editable}>
                                            <legend>{editor.fields.paragraphs}</legend>
                                            {source.paragraphs.map((paragraph, index) => (
                                                <div className="vy-email-paragraph" key={index}>
                                                    <LabeledField label={editor.fields.paragraph(index)}>
                                                        {(id) => (
                                                            <textarea
                                                                id={id}
                                                                data-source-field={`paragraph:${index}`}
                                                                value={paragraph}
                                                                onFocus={focusField}
                                                                onChange={(e) => updateParagraph(index, e.target.value)}
                                                                className={fieldClass(true)}
                                                            />
                                                        )}
                                                    </LabeledField>
                                                    <div className="vy-email-paragraph-tools">
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            aria-label={strings.workspace.moveUp(index)}
                                                            disabled={index === 0}
                                                            onClick={() => moveParagraph(index, -1)}
                                                        >
                                                            <ArrowUp size={14} />
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            aria-label={strings.workspace.moveDown(index)}
                                                            disabled={index === source.paragraphs.length - 1}
                                                            onClick={() => moveParagraph(index, 1)}
                                                        >
                                                            <ArrowDown size={14} />
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            aria-label={editor.fields.removeParagraph(index)}
                                                            disabled={source.paragraphs.length === 1}
                                                            onClick={() => {
                                                                setSource({
                                                                    ...source,
                                                                    paragraphs: source.paragraphs.filter(
                                                                        (_, at) => at !== index,
                                                                    ),
                                                                });
                                                                setDirty(true);
                                                                mutationKeys.current.save = null;
                                                            }}
                                                        >
                                                            <Trash2 size={14} />
                                                        </Button>
                                                    </div>
                                                </div>
                                            ))}
                                            <Button
                                                className="justify-self-start"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setSource({ ...source, paragraphs: [...source.paragraphs, ''] });
                                                    setDirty(true);
                                                    mutationKeys.current.save = null;
                                                }}
                                            >
                                                <Plus size={14} />
                                                {editor.fields.addParagraph}
                                            </Button>
                                        </fieldset>
                                        <h3>{strings.workspace.callToAction}</h3>
                                        <div className="vy-email-two-fields">
                                            {field('action_label', editor.fields.actionLabel)}
                                            {field('action_url', editor.fields.actionUrl)}
                                        </div>
                                    </section>
                                    <section hidden={panel !== 'branding'} className="vy-email-panel">
                                        <div className="vy-email-section-title">
                                            <h2>{strings.workspace.brandingTitle}</h2>
                                            <p>{strings.workspace.brandingHint}</p>
                                        </div>
                                        {field('logo_url', editor.fields.logoUrl)}
                                        {field('logo_alt', editor.fields.logoAlt)}
                                        <LabeledField label={editor.fields.accent}>
                                            {(id) => (
                                                <div className="vy-email-color">
                                                    <input
                                                        disabled={!editable}
                                                        aria-label={editor.fields.accentPicker}
                                                        type="color"
                                                        value={
                                                            /^#[0-9a-f]{6}$/i.test(source.accent_color)
                                                                ? source.accent_color
                                                                : '#335cff'
                                                        }
                                                        onChange={handleInput('accent_color')}
                                                    />
                                                    <input
                                                        id={id}
                                                        disabled={!editable}
                                                        data-source-field="accent_color"
                                                        value={source.accent_color}
                                                        onFocus={focusField}
                                                        onChange={handleInput('accent_color')}
                                                        className={fieldClass()}
                                                    />
                                                </div>
                                            )}
                                        </LabeledField>
                                        {field('footer', editor.fields.footer, true)}
                                    </section>
                                    <section hidden={panel !== 'delivery'} className="vy-email-panel">
                                        <div className="vy-email-section-title">
                                            <h2>{strings.workspace.deliveryTitle}</h2>
                                            <p>{strings.workspace.deliveryHint}</p>
                                        </div>
                                        {translationControls}
                                        <dl className="vy-email-identity">
                                            {[
                                                { label: editor.exactIdentity, value: identity.templateKey },
                                                {
                                                    label: strings.workspace.category,
                                                    value: templateLabel(detail.category_key ?? '—'),
                                                },
                                                {
                                                    label: strings.workspace.classification,
                                                    value: templateLabel(detail.class ?? '—'),
                                                },
                                                {
                                                    label: strings.workspace.channel,
                                                    value: templateLabel(identity.channelKey),
                                                },
                                            ].map(({ label, value }) => (
                                                <div key={label}>
                                                    <dt>{label}</dt>
                                                    <dd>{value}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                        {field('short_body', editor.fields.shortBody, true)}
                                        <DeliveryDiagnostics
                                            ready={studioDetail.delivery_ready}
                                            warnings={studioDetail.delivery_warnings ?? []}
                                        />
                                        <div className="vy-email-provenance">
                                            <h3>{strings.workspace.customizations}</h3>
                                            <p>
                                                {editor.blueprint(
                                                    studioDetail.published_blueprint_version ?? null,
                                                    studioDetail.current_blueprint_version ??
                                                        detail.blueprint_version ??
                                                        null,
                                                )}
                                            </p>
                                            {studioDetail.update_available === true && (
                                                <>
                                                    <p>{editor.updateAvailable}</p>
                                                    {!detail.has_draft && editable && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => {
                                                                setSource(structuredClone(detail.default_source));
                                                                setProfessionalStarted(true);
                                                                setDirty(true);
                                                                mutationKeys.current.save = null;
                                                            }}
                                                        >
                                                            {editor.startUpdate}
                                                        </Button>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </section>
                                </fieldset>
                            </Card>
                            <Card className="vy-email-section">
                                <fieldset disabled={!editable || pending} className="vy-email-token-fields">
                                    <legend>{editor.tokens}</legend>
                                    <p>{editor.tokensHint}</p>
                                    <input
                                        type="search"
                                        aria-label={strings.workspace.tokensSearch}
                                        placeholder={strings.workspace.tokensSearch}
                                        value={tokenSearch}
                                        onChange={(e) => setTokenSearch(e.target.value)}
                                    />
                                    <div className="vy-email-tokens">
                                        {detail.tokens
                                            .filter((token) =>
                                                token.key.toLowerCase().includes(tokenSearch.toLowerCase()),
                                            )
                                            .map((token) => (
                                                <button
                                                    type="button"
                                                    key={token.key}
                                                    aria-label={editor.insert(token.key)}
                                                    onMouseDown={(e) => e.preventDefault()}
                                                    onClick={() => insertToken(token.key)}
                                                >
                                                    <code>{uiText("vidyalms.ui.1314f2df80e6bb83", "{{p0}}", { p0: token.key })}</code>
                                                    <span>
                                                        {token.type}
                                                        {token.required ? uiText("vidyalms.ui.04420b9dfe3f0a87", " · {p0}", { p0: editor.required }) : ''}
                                                    </span>
                                                </button>
                                            ))}
                                    </div>
                                    {!detail.tokens.some((token) =>
                                        token.key.toLowerCase().includes(tokenSearch.toLowerCase()),
                                    ) && <p>{strings.workspace.noTokens}</p>}
                                </fieldset>
                            </Card>
                        </div>
                        <aside className="vy-email-preview-column">
                            <div className="vy-email-toolbar">
                                <span>{preview.isPending ? editor.preview : strings.workspace.sample}</span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={preview.isPending}
                                    onClick={() => preview.mutate({ identity, source: normalisedSource(source) })}
                                >
                                    <Eye size={15} />
                                    {editor.refreshPreview}
                                </Button>
                            </div>
                            {preview.isError && <ErrorState error={preview.error} />}
                            <MessagePreview
                                loading={editable && !preview.isError}
                                preview={
                                    JSON.stringify(preview.variables?.source) ===
                                    JSON.stringify(normalisedSource(source))
                                        ? (preview.data ?? null)
                                        : null
                                }
                                tab={previewTab}
                                onTabChange={setPreviewTab}
                            />
                            <Card className="vy-email-section vy-email-test">
                                <h3>
                                    <Send size={16} />
                                    {editor.selfTest}
                                </h3>
                                <p>{editor.selfTestHint}</p>
                                {testSend.data !== undefined && (
                                    <p
                                        role="status"
                                        className={
                                            testSend.data.status === 'sent' ? 'vy-email-success' : 'vy-email-notice'
                                        }
                                    >
                                        {testSend.data.status === 'sent'
                                            ? editor.selfTestSent(testSend.data.recipient_email)
                                            : editor.selfTestFailed(testSend.data.recipient_email)}
                                        {testSend.data.error !== null && <span>{testSend.data.error}</span>}
                                    </p>
                                )}
                                {testSend.data !== undefined && (
                                    <DeliveryDiagnostics
                                        ready={
                                            (testSend.data as typeof testSend.data & Partial<DeliveryState>)
                                                .delivery_ready
                                        }
                                        warnings={
                                            (testSend.data as typeof testSend.data & Partial<DeliveryState>)
                                                .delivery_warnings ?? []
                                        }
                                    />
                                )}
                                {testSend.isError && <ErrorState error={testSend.error} />}
                                <Button
                                    variant="outline"
                                    disabled={!editable || pending || testSend.isPending}
                                    onClick={() =>
                                        testSend.mutate(
                                            {
                                                identity,
                                                source: normalisedSource(source),
                                                idempotencyKey: operationKey('test'),
                                            },
                                            {
                                                onSuccess: () => {
                                                    mutationKeys.current.test = null;
                                                },
                                                onError: (error) => settledError('test', error),
                                            },
                                        )
                                    }
                                >
                                    {editor.selfTest}
                                </Button>
                            </Card>
                        </aside>
                    </div>
                </>
            )}
            <Confirmation
                open={discardOpen}
                onOpenChange={setDiscardOpen}
                title={editor.discardTitle}
                body={editor.discardBody}
                confirm={editor.confirmDiscard}
                destructive
                pending={discard.isPending}
                onConfirm={() =>
                    discard.mutate(
                        { identity, expectedRevision, idempotencyKey: operationKey('discard') },
                        {
                            onSuccess: (next) => {
                                setDiscardOpen(false);
                                applyServer(next);
                                setNotice(editor.discarded);
                            },
                            onError: (error) => settledError('discard', error),
                        },
                    )
                }
            />
            <Confirmation
                open={publishOpen}
                onOpenChange={setPublishOpen}
                title={editor.publishTitle}
                body={editor.publishBody}
                confirm={editor.confirmPublish}
                pending={publish.isPending}
                onConfirm={() =>
                    publish.mutate(
                        { identity, expectedRevision, idempotencyKey: operationKey('publish') },
                        {
                            onSuccess: (next) => {
                                setPublishOpen(false);
                                applyServer(next);
                                setNotice(editor.publishedNow);
                            },
                            onError: (error) => settledError('publish', error),
                        },
                    )
                }
            />
        </div>
    );
}
