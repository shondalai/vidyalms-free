import { UserPicker } from '../components/user-picker';
import { EnrolmentCoursePicker, type CourseSelection } from '../learners/course-picker';
import type { UserAccount } from '@vidyalms/api-sdk';
import { ArrowLeft, Eye, LayoutTemplate, Settings2, Users, Braces } from 'lucide-react';
import { certificateWorkspaceStrings as ws } from './workspace-strings';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    Skeleton,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import type {
    CertificateReissueJob,
    CertificateTemplateDetail,
    CertificateTemplateFinding,
    CertificateTemplateRequest,
} from '@vidyalms/api-sdk';
import { SelectField, TextField } from '../components/field';
import { AdminNotice, ScreenError } from '../components/states';
import { credentialStrings } from '../credential-strings';
import { TextAreaField } from './fields';
import {
    useCertificateElementTypes,
    useCertificateTemplate,
    useCancelBulkReissue,
    usePreviewCertificateTemplate,
    useStartBulkReissue,
    useBulkReissueStatus,
    useUpdateCertificateTemplate,
    useCertificateWriteAttempt,
} from './api';
import { ElementsPanel, PageSettings, PagesPanel } from './page-elements';
import { StudioDraftProvider, useStudioDraft, useStudioNavigation } from './studio-drafts';
import { certificateStudioStrings as studioStrings } from './studio-strings';
import { CertificatePdfPreview } from './pdf-preview';
import { CertificatePublicationToolbar } from './publication-toolbar';
import { useCertificateBlueprints } from './studio-api';

/*
 * One template, edited as the three-level aggregate it is.
 *
 * A template that has issued a certificate is LOCKED: its criteria and its
 * expiry are frozen and a change makes a new revision, which is Moodle's
 * badge lock rule and the reason every audit question about a credential can
 * be answered. The lock is stated on the screen rather than enforced by
 * hiding controls, because an author has to know why editing behaves
 * differently.
 *
 * Nothing here can rewrite a credential somebody already holds. Every
 * certificate renders from its own frozen snapshot, so a correction reaches
 * existing holders only through a reissue, which is the panel at the bottom.
 */

const strings = credentialStrings;

export interface TemplateEditorProps {
    templateId: number;
    onBack: () => void;
    onDirtyChange?: (dirty: boolean) => void;
}

export function TemplateEditor(props: TemplateEditorProps) {
    return (
        <StudioDraftProvider onDirtyChange={props.onDirtyChange}>
            <TemplateEditorBody {...props} />
        </StudioDraftProvider>
    );
}

function TemplateEditorBody({ templateId, onBack }: TemplateEditorProps) {
    const navigation = useStudioNavigation();
    const template = useCertificateTemplate(templateId);
    const elementTypes = useCertificateElementTypes(templateId);
    const [message, setMessage] = useState<string | null>(null);
    const [pageId, setPageId] = useState<number | null>(null);
    const [generation, setGeneration] = useState(0);
    const [section, setSection] = useState('design');

    const detail = template.data ?? null;
    const pages = detail?.pages ?? [];
    const selectedPage = pages.find((page) => page.id === pageId) ?? pages[0] ?? null;

    useEffect(() => {
        if (selectedPage !== null && selectedPage.id !== pageId) {
            setPageId(selectedPage.id);
        }
    }, [selectedPage, pageId]);

    if (template.isPending) {
        return <Skeleton className="h-64 w-full" />;
    }

    if (detail === null) {
        return <ScreenError error={template.error} onRetry={() => void template.refetch()} />;
    }

    const locked = detail.locked_at !== null;

    return (
        <div className="vy-cert-studio">
            <div className="vy-cert-studio-header">
                <Button variant="ghost" size="sm" onClick={() => navigation.requestLeave(onBack)}>
                    <ArrowLeft size={16} aria-hidden="true" />
                    {strings.templates.back}
                </Button>
                <h2 className="vy-cert-studio-title">{detail.name}</h2>
                {detail.is_default && <Badge variant="accent">{strings.templates.defaultBadge}</Badge>}
                {locked && <Badge variant="warn">{strings.templates.lockedBadge}</Badge>}
                {navigation.dirty && <Badge variant="warn">{studioStrings.unsaved}</Badge>}
                <span className="text-[11.5px] text-fg-2">
                    {strings.templates.columns.revision} {detail.revision}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    className="ml-auto"
                    disabled={template.isFetching}
                    title={studioStrings.reloadHint}
                    onClick={() =>
                        navigation.requestLeave(() => {
                            void template.refetch().then((result) => {
                                if (result.isSuccess) setGeneration((current) => current + 1);
                            });
                        })
                    }
                >
                    {studioStrings.reload}
                </Button>
            </div>

            {template.isError && (
                <ErrorState error={template.error} onRetry={() => void template.refetch()} />
            )}
            <CertificatePublicationToolbar
                key={`publication-${detail.id}-${generation}`}
                template={detail}
                onDone={setMessage}
            />

            {locked && <p className="text-[11.5px] text-fg-2">{strings.templates.lockedHint}</p>}

            {message !== null && (
                <AdminNotice
                    glyph="✓"
                    role="status"
                    title={message}
                    action={
                        <Button variant="ghost" size="sm" onClick={() => setMessage(null)}>
                            {strings.form.close}
                        </Button>
                    }
                />
            )}

            <div className="vy-cert-studio-tabs" role="tablist" aria-label={ws.studio}>
                {[
                    {
                        id: 'design',
                        label: detail.format === 'html' ? ws.content : ws.design,
                        icon: LayoutTemplate,
                    },
                    { id: 'settings', label: ws.settings, icon: Settings2 },
                    { id: 'preview', label: ws.preview, icon: Eye },
                    { id: 'fields', label: ws.fields, icon: Braces },
                    { id: 'holders', label: ws.holders, icon: Users },
                ].map(({ id, label, icon: Icon }) => (
                    <button
                        key={id}
                        type="button"
                        role="tab"
                        id={`vy-cert-studio-${id}`}
                        aria-controls={`vy-cert-studio-panel-${id}`}
                        aria-selected={section === id}
                        tabIndex={section === id ? 0 : -1}
                        onClick={() => setSection(id)}
                        onKeyDown={(event) => {
                            const buttons = Array.from(
                                event.currentTarget.parentElement!.querySelectorAll<HTMLButtonElement>(
                                    '[role="tab"]',
                                ),
                            );
                            const index = buttons.indexOf(event.currentTarget);
                            const next =
                                event.key === 'ArrowRight'
                                    ? (index + 1) % buttons.length
                                    : event.key === 'ArrowLeft'
                                      ? (index + buttons.length - 1) % buttons.length
                                      : event.key === 'Home'
                                        ? 0
                                        : event.key === 'End'
                                          ? buttons.length - 1
                                          : null;
                            if (next !== null) {
                                event.preventDefault();
                                buttons[next]?.focus();
                                buttons[next]?.click();
                            }
                        }}
                    >
                        <Icon size={16} aria-hidden="true" />
                        {label}
                    </button>
                ))}
            </div>
            <div
                hidden={section !== 'settings' && !(detail.format === 'html' && section === 'design')}
                role="tabpanel"
                id={
                    detail.format === 'html' && section === 'design'
                        ? 'vy-cert-studio-panel-design'
                        : 'vy-cert-studio-panel-settings'
                }
                aria-labelledby={
                    detail.format === 'html' && section === 'design'
                        ? 'vy-cert-studio-design'
                        : 'vy-cert-studio-settings'
                }
                className="vy-cert-settings-panel"
            >
                <div className="vy-cert-intro">
                    <h2>{section === 'design' ? ws.content : ws.settings}</h2>
                    <p>{section === 'design' ? ws.htmlHint : ws.settingsHint}</p>
                </div>
                <TemplateSettings
                    key={`${detail.id}-${generation}`}
                    template={detail}
                    onDone={setMessage}
                    bodyOnly={detail.format === 'html' && section === 'design'}
                />
            </div>
            <div
                hidden={section !== 'design' || detail.format === 'html'}
                role="tabpanel"
                id={detail.format === 'html' ? undefined : 'vy-cert-studio-panel-design'}
                aria-labelledby="vy-cert-studio-design"
            >
                <p className="vy-cert-section-hint">{ws.designHint}</p>
                <div className="grid gap-4 rounded-lg border border-border bg-surface p-4">
                    <PagesPanel
                        template={detail}
                        selectedPageId={selectedPage?.id ?? null}
                        onSelectPage={(id) => {
                            if (id !== pageId) navigation.requestLeave(() => setPageId(id));
                        }}
                        readOnly={false}
                        onDone={setMessage}
                    />
                    {selectedPage !== null && (
                        <>
                            {elementTypes.isError && (
                                <ErrorState
                                    error={elementTypes.error}
                                    onRetry={() => void elementTypes.refetch()}
                                />
                            )}
                            {(elementTypes.isSuccess || elementTypes.data !== undefined) && (
                                <ElementsPanel
                                    key={`elements-${selectedPage.id}-${generation}`}
                                    template={detail}
                                    page={selectedPage}
                                    elementTypes={elementTypes.data?.items ?? []}
                                    tokens={elementTypes.data?.tokens ?? []}
                                    readOnly={false}
                                    onDone={setMessage}
                                    pageInspector={
                                        <details className="rounded border border-border p-3">
                                            <summary className="cursor-pointer font-display text-xs font-semibold text-fg">
                                                {studioStrings.pageSettings}
                                            </summary>
                                            <PageSettings
                                                key={`paper-${selectedPage.id}-${generation}`}
                                                templateId={detail.id}
                                                expectedRevision={detail.revision}
                                                page={selectedPage}
                                                readOnly={false}
                                            />
                                        </details>
                                    }
                                />
                            )}
                        </>
                    )}
                </div>

                <details className="vy-cert-checks">
                    <summary>
                        {ws.checkDesign} · {detail.findings.length}
                    </summary>
                    <Findings findings={detail.findings} />
                </details>
            </div>
            <div
                hidden={section !== 'fields'}
                role="tabpanel"
                id="vy-cert-studio-panel-fields"
                aria-labelledby="vy-cert-studio-fields"
            >
                <TokenReference tokens={elementTypes.data?.tokens ?? []} />
            </div>
            <div
                hidden={section !== 'preview'}
                role="tabpanel"
                id="vy-cert-studio-panel-preview"
                aria-labelledby="vy-cert-studio-preview"
            >
                <p className="vy-cert-section-hint">{ws.previewHint}</p>
                {navigation.dirty && (
                    <p role="status" className="vy-cert-section-hint">
                        {ws.savedPreview}
                    </p>
                )}
                {section === 'preview' && (
                    <PreviewPanel
                        key={`preview-${detail.id}-${generation}`}
                        templateId={templateId}
                        revision={detail.revision}
                    />
                )}
            </div>
            <div
                hidden={section !== 'holders'}
                role="tabpanel"
                id="vy-cert-studio-panel-holders"
                aria-labelledby="vy-cert-studio-holders"
                className="vy-cert-settings-panel"
            >
                <div className="vy-cert-intro">
                    <h2>{ws.holders}</h2>
                    <p>{ws.holdersHint}</p>
                </div>
                <BulkReissuePanel template={detail} onDone={setMessage} />
            </div>
        </div>
    );
}

function TemplateSettings({
    template,
    onDone,
    bodyOnly = false,
}: {
    template: CertificateTemplateDetail;
    onDone: (message: string) => void;
    bodyOnly?: boolean;
}) {
    const announce = useAnnounce();
    const update = useUpdateCertificateTemplate();
    const write = useCertificateWriteAttempt();
    const setDirty = useStudioDraft(`template-${template.id}`);
    const catalogue = useCertificateBlueprints();
    const editVersion = useRef(0);
    const observedRevision = useRef(template.revision);
    observedRevision.current = Math.max(observedRevision.current, template.revision);
    const [draft, setDraft] = useState<CertificateTemplateRequest>(() => ({
        name: template.name,
        template_key: template.template_key,
        renderer: template.renderer,
        format: template.format,
        achievement_type: template.achievement_type,
        body: template.body,
        locale: template.locale,
        expiry_mode: template.expiry_mode,
        expiry_days: template.expiry_days,
        expiry_at: template.expiry_at,
        category: template.category,
    }));

    const editDraft = <K extends keyof CertificateTemplateRequest>(
        key: K,
        value: CertificateTemplateRequest[K],
    ) => {
        write.pinRevision(template.revision);
        setDirty(true);
        editVersion.current += 1;
        setDraft((current) => ({ ...current, [key]: value }));
    };

    const save = async () => {
        const attemptedEditVersion = editVersion.current;
        const sourceRevision = write.pinRevision(template.revision);
        const attempt = write.capture(
            draft,
            () => ({
                id: template.id,
                body: { ...draft, expected_revision: sourceRevision },
            }),
            { templateId: template.id, editVersion: attemptedEditVersion },
        );
        try {
            const saved = await update.mutateAsync({
                ...attempt.input,
                idempotencyKey: attempt.idempotencyKey,
            });
            if (editVersion.current === attemptedEditVersion && saved.revision >= observedRevision.current) {
                write.complete(attempt);
                setDirty(false);
            }
        } catch {
            return;
        }
        onDone(strings.templates.saved);
        announce(strings.templates.saved);
    };

    /*
     * The lock, read as a fact rather than derived.
     *
     * The server publishes which fields the criteria lock froze, and the
     * screen disables exactly those and says why. Discovering the lock by
     * attempting a save and reading a 409 means an author was offered a
     * control that cannot work, and a client-side copy of the field list
     * goes stale the moment a package adds an expiry policy.
     */
    const lockedFields = new Set(template.locked_fields ?? []);
    const lockHint = (field: string): string | undefined =>
        lockedFields.has(field) ? strings.templates.lockedFieldHint : undefined;

    return (
        <section className="grid gap-3 rounded-lg border border-border bg-surface p-4">
            <div hidden={bodyOnly} className="grid gap-2.5 sm:grid-cols-2">
                <TextField
                    label={strings.templates.fields.name}
                    value={draft.name ?? ''}
                    onChange={(event) => editDraft('name', event.target.value)}
                />
                <TextField
                    label={strings.templates.fields.key}
                    hint={strings.templates.fields.keyHint}
                    value={draft.template_key ?? ''}
                    onChange={(event) =>
                        editDraft('template_key', event.target.value === '' ? null : event.target.value)
                    }
                />
                <SelectField
                    label={strings.templates.fields.format}
                    hint={strings.templates.fields.formatHint}
                    value={draft.format ?? 'html'}
                    onChange={(event) =>
                        editDraft('format', event.target.value as CertificateTemplateRequest['format'])
                    }
                >
                    {Object.entries(strings.templates.formats).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </SelectField>
                <SelectField
                    label={strings.templates.fields.renderer}
                    value={draft.renderer ?? ''}
                    onChange={(event) => editDraft('renderer', event.target.value)}
                >
                    {!(catalogue.data?.renderer_options ?? []).some(
                        (option) =>
                            option.key === draft.renderer && option.formats.includes(draft.format ?? 'html'),
                    ) && (
                        <option value={draft.renderer ?? ''} disabled>
                            {draft.renderer}
                        </option>
                    )}
                    {(catalogue.data?.renderer_options ?? [])
                        .filter((option) => option.formats.includes(draft.format ?? 'html'))
                        .map((option) => (
                            <option key={option.key} value={option.key}>
                                {option.label}
                            </option>
                        ))}
                </SelectField>
                <TextField
                    label={strings.templates.fields.achievementType}
                    hint={strings.templates.fields.achievementHint}
                    value={draft.achievement_type ?? ''}
                    onChange={(event) => editDraft('achievement_type', event.target.value)}
                />
                <TextField
                    label={strings.templates.fields.locale}
                    value={draft.locale ?? ''}
                    onChange={(event) =>
                        editDraft('locale', event.target.value === '' ? null : event.target.value)
                    }
                />
                <TextField
                    label={studioStrings.category}
                    hint={studioStrings.categoryHint}
                    maxLength={80}
                    value={draft.category ?? ''}
                    onChange={(event) => editDraft('category', event.target.value)}
                />
                <SelectField
                    label={strings.templates.fields.expiryMode}
                    hint={lockHint('expiry_mode') ?? strings.templates.expiryPro}
                    disabled={lockedFields.has('expiry_mode')}
                    value={draft.expiry_mode ?? 'never'}
                    onChange={(event) =>
                        editDraft(
                            'expiry_mode',
                            event.target.value as CertificateTemplateRequest['expiry_mode'],
                        )
                    }
                >
                    {Object.entries(strings.templates.expiryModes).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </SelectField>
                {draft.expiry_mode === 'days_after_issue' && (
                    <TextField
                        label={strings.templates.fields.expiryDays}
                        hint={lockHint('expiry_days')}
                        disabled={lockedFields.has('expiry_days')}
                        inputMode="numeric"
                        value={
                            draft.expiry_days === null || draft.expiry_days === undefined
                                ? ''
                                : String(draft.expiry_days)
                        }
                        onChange={(event) =>
                            editDraft(
                                'expiry_days',
                                event.target.value === '' ? null : Number(event.target.value),
                            )
                        }
                    />
                )}
                {draft.expiry_mode === 'fixed' && (
                    <TextField
                        label={strings.templates.fields.expiryAt}
                        hint={lockHint('expiry_at')}
                        disabled={lockedFields.has('expiry_at')}
                        type="date"
                        value={(draft.expiry_at ?? '').slice(0, 10)}
                        onChange={(event) =>
                            editDraft(
                                'expiry_at',
                                event.target.value === '' ? null : `${event.target.value} 23:59:59`,
                            )
                        }
                    />
                )}
            </div>

            {draft.format === 'html' && (
                <TextAreaField
                    label={strings.templates.fields.body}
                    hint={strings.templates.fields.bodyHint}
                    value={draft.body ?? ''}
                    onChange={(event) => editDraft('body', event.target.value)}
                />
            )}

            {update.isError && <ErrorState error={update.error} />}

            <div className="flex justify-end">
                <Button
                    variant="primary"
                    size="sm"
                    disabled={update.isPending}
                    onClick={() => {
                        void save();
                    }}
                >
                    {update.isPending ? strings.templates.saving : strings.templates.save}
                </Button>
            </div>
        </section>
    );
}

function Findings({ findings }: { findings: readonly CertificateTemplateFinding[] }) {
    return (
        <section
            aria-labelledby="vy-cert-findings"
            className="grid gap-2 rounded-lg border border-border bg-surface p-4"
        >
            <h3 id="vy-cert-findings" className="font-display text-xs font-bold text-fg">
                {strings.findings.heading}
            </h3>
            {findings.length === 0 ? (
                <p className="text-[12px] text-fg-2">{strings.findings.none}</p>
            ) : (
                <ul className="grid gap-1.5">
                    {findings.map((finding) => (
                        <li
                            key={`${finding.key}-${finding.element_id ?? 'template'}`}
                            className="flex flex-wrap items-center gap-2 text-[12.5px]"
                        >
                            <Badge
                                variant={
                                    finding.severity === 'error'
                                        ? 'danger'
                                        : finding.severity === 'warning'
                                          ? 'warn'
                                          : 'neutral'
                                }
                            >
                                {strings.findings.severity[finding.severity] ?? finding.severity}
                            </Badge>
                            <span className="min-w-0 flex-1 text-fg">{finding.message}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function TokenReference({
    tokens,
}: {
    tokens: readonly {
        key: string;
        label: string;
        available: boolean;
        fields: { key: string; label: string }[];
    }[];
}) {
    if (tokens.length === 0) {
        return null;
    }
    return (
        <section
            aria-labelledby="vy-cert-tokens"
            className="grid gap-2 rounded-lg border border-border bg-surface p-4"
        >
            <h3 id="vy-cert-tokens" className="font-display text-xs font-bold text-fg">
                {strings.tokens.heading}
            </h3>
            <p className="text-[11.5px] text-fg-2">{strings.tokens.hint}</p>
            <ul className="grid gap-1.5">
                {tokens.map((source) => (
                    <li key={source.key} className="text-[12px]">
                        <span className="font-display font-semibold text-fg">{source.label}</span>
                        {!source.available && (
                            <span className="ml-1.5 text-fg-2">({strings.tokens.unavailable})</span>
                        )}
                        <span className="ml-1.5 text-fg-2">
                            {source.fields.map((field) => `{${source.key}.${field.key}}`).join(' ')}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function PreviewPanel({ templateId, revision }: { templateId: number; revision: number }) {
    const preview = usePreviewCertificateTemplate();
    const [source, setSource] = useState('sample');
    const [learner, setLearner] = useState<UserAccount | null>(null);
    const [course, setCourse] = useState<CourseSelection | null>(null);
    const previewBody = useMemo(
        () => (source === 'sample' ? {} : { user_id: learner?.id ?? null, course_id: course?.id ?? null }),
        [source, learner?.id, course?.id],
    );
    const runPreview = preview.mutateAsync;
    useEffect(() => {
        void runPreview({ templateId, body: previewBody }).catch(() => undefined);
    }, [templateId, revision, runPreview, previewBody]);
    const result = preview.data ?? null;

    return (
        <section
            aria-labelledby="vy-cert-preview"
            className="grid gap-2.5 rounded-lg border border-border bg-surface p-4"
        >
            <div className="flex flex-wrap items-center gap-2">
                <h3 id="vy-cert-preview" className="font-display text-xs font-bold text-fg">
                    {strings.preview.heading}
                </h3>
                <Button
                    className="ml-auto"
                    variant="outline"
                    size="sm"
                    disabled={preview.isPending}
                    onClick={() => {
                        void preview.mutateAsync({ templateId, body: previewBody }).catch(() => undefined);
                    }}
                >
                    {preview.isPending ? strings.preview.running : strings.preview.run}
                </Button>
            </div>
            <p className="text-[11.5px] text-fg-2">{strings.preview.note}</p>
            <details className="vy-cert-preview-data">
                <summary>{ws.previewSource}</summary>
                <SelectField
                    label={ws.previewSource}
                    value={source}
                    onChange={(event) => setSource(event.target.value)}
                >
                    <option value="sample">{ws.sampleSource}</option>
                    <option value="learner">{ws.learnerSource}</option>
                </SelectField>
                {source === 'learner' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <UserPicker
                            label={ws.learnerSource}
                            placeholder={ws.learnerSearch}
                            value={learner}
                            onChange={setLearner}
                        />
                        <EnrolmentCoursePicker
                            searchLabel={ws.coursePreviewSearch}
                            value={course}
                            onChange={setCourse}
                        />
                    </div>
                )}
            </details>
            {preview.isError && <ErrorState error={preview.error} />}
            {result === null ? (
                <p className="text-[12px] text-fg-2">{strings.preview.empty}</p>
            ) : (
                <>
                    {result.unresolved_tokens.length > 0 && (
                        <p className="text-[11.5px] text-warn-strong">
                            {strings.preview.unresolved(result.unresolved_tokens.length)}{' '}
                            {result.unresolved_tokens.join(' ')}
                        </p>
                    )}
                    {result.html !== null ? (
                        <iframe
                            title={strings.preview.frame}
                            /* The rendered document, isolated from this page:
                               a template body is authored HTML and must not
                               reach the admin DOM, its styles or its scripts. */
                            sandbox=""
                            srcDoc={result.html}
                            className="h-[420px] w-full rounded border border-border bg-white"
                        />
                    ) : result.media_type === 'application/pdf' ? (
                        <CertificatePdfPreview source={result.data_url ?? null} />
                    ) : (
                        <p className="text-[12px] text-fg-2">{strings.preview.binary(result.media_type)}</p>
                    )}
                </>
            )}
        </section>
    );
}

function BulkReissuePanel({
    template,
    onDone,
}: {
    template: CertificateTemplateDetail;
    onDone: (message: string) => void;
}) {
    const announce = useAnnounce();
    const start = useStartBulkReissue();
    const cancel = useCancelBulkReissue();
    const startWrite = useCertificateWriteAttempt();
    const cancelWrite = useCertificateWriteAttempt();
    const navigation = useStudioNavigation();
    const [confirming, setConfirming] = useState<number | null>(null);
    const [cancelled, setCancelled] = useState(false);
    const publishedRevision = template.published_revision ?? null;
    const status = useBulkReissueStatus(template.id);
    const running = status.data?.state === 'queued' || status.data?.state === 'running';
    const blocked =
        publishedRevision === null ||
        navigation.dirty ||
        template.has_unpublished_changes === true ||
        running;

    const job: CertificateReissueJob | null = start.data ?? null;
    const progress = useMemo(() => {
        if (job === null || job.selected === 0) {
            return null;
        }
        return strings.bulkReissue.progress(job.completed ?? 0, job.selected);
    }, [job]);

    return (
        <section
            aria-labelledby="vy-cert-reissue"
            className="grid gap-2.5 rounded-lg border border-border bg-surface p-4"
        >
            <h3 id="vy-cert-reissue" className="font-display text-xs font-bold text-fg">
                {strings.bulkReissue.heading}
            </h3>
            <p className="text-[11.5px] text-fg-2">{strings.bulkReissue.note}</p>
            {blocked && <p className="text-sm text-fg-2">{studioStrings.reissueUnavailable}</p>}
            {start.isError && <ErrorState error={start.error} />}
            {cancel.isError && <ErrorState error={cancel.error} />}
            {status.isError && (
                <ErrorState
                    error={status.error}
                    onRetry={() => {
                        void status.refetch();
                    }}
                />
            )}
            {status.data && status.data.state !== 'idle' && (
                <div
                    aria-label={studioStrings.reissueStatus}
                    className="grid gap-2 rounded border border-border p-3 text-xs"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge>{status.data.state}</Badge>
                        {status.data.revision !== null && (
                            <span>{studioStrings.reissueRevision(status.data.revision)}</span>
                        )}
                    </div>
                    {status.data.counts && (
                        <p className="text-fg-2">
                            {studioStrings.reissueCounts(
                                status.data.counts.complete,
                                status.data.counts.failed,
                                status.data.counts.queued,
                                status.data.counts.running,
                            )}
                        </p>
                    )}
                    {status.data.selection_complete === false && (
                        <p className="text-fg-2">{studioStrings.reissueSelecting}</p>
                    )}
                    {status.data.root_job?.diagnostic && (
                        <p className="text-danger-strong">{status.data.root_job.diagnostic}</p>
                    )}
                    <p className="text-fg-2">{studioStrings.reissueRetained(status.data.retention_days)}</p>
                </div>
            )}
            {job !== null && (!status.data || status.data.state === 'idle') && (
                <div className="grid gap-1 rounded border border-border p-2.5 text-[12px]">
                    <p className="flex flex-wrap items-center gap-2">
                        <Badge variant={job.available ? 'accent' : 'neutral'}>
                            {cancelled
                                ? strings.bulkReissue.cancelled
                                : (strings.bulkReissue.statuses[job.status] ?? job.status)}
                        </Badge>
                        <span className="text-fg">{strings.bulkReissue.queued(job.selected)}</span>
                        {progress !== null && <span className="text-fg-2">{progress}</span>}
                    </p>
                    {!job.available && <p className="text-fg-2">{strings.bulkReissue.unavailable}</p>}
                    {job.dedupe_key !== null && (
                        <p className="text-fg-2">
                            <code className="font-mono text-[11px]">
                                {strings.bulkReissue.dedupe(job.dedupe_key)}
                            </code>
                        </p>
                    )}
                </div>
            )}
            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={status.isFetching}
                    onClick={() => {
                        void status.refetch();
                    }}
                >
                    {studioStrings.reissueRefresh}
                </Button>
                {(running ||
                    (job !== null &&
                        job.available &&
                        job.revision !== null &&
                        !cancelled &&
                        job.status !== 'cancelled' &&
                        job.status !== 'completed')) && (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={cancel.isPending}
                        onClick={() => {
                            const revision = status.data?.revision ?? job?.revision;
                            if (revision == null) return;
                            const attempt = cancelWrite.capture(
                                { templateId: template.id, revision },
                                () => ({ templateId: template.id, revision }),
                            );
                            void cancel
                                .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
                                .then(() => {
                                    cancelWrite.complete(attempt);
                                    setCancelled(true);
                                    onDone(strings.bulkReissue.cancelled);
                                    announce(strings.bulkReissue.cancelled);
                                })
                                .catch(() => undefined);
                        }}
                    >
                        {cancel.isPending ? strings.bulkReissue.cancelling : strings.bulkReissue.cancel}
                    </Button>
                )}
                <Button
                    variant="primary"
                    size="sm"
                    disabled={start.isPending || blocked}
                    onClick={() => setConfirming(publishedRevision)}
                >
                    {start.isPending ? strings.bulkReissue.starting : strings.bulkReissue.start}
                </Button>
            </div>
            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => {
                    if (!open && !start.isPending) setConfirming(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{studioStrings.reissueTitle}</DialogTitle>
                    <DialogDescription>{studioStrings.reissueBody}</DialogDescription>
                    {start.isError && <ErrorState error={start.error} />}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            disabled={start.isPending}
                            onClick={() => setConfirming(null)}
                        >
                            {studioStrings.cancel}
                        </Button>
                        <Button
                            disabled={start.isPending || blocked || confirming !== publishedRevision}
                            onClick={() => {
                                if (confirming === null || confirming !== publishedRevision) return;
                                const attempt = startWrite.capture(
                                    { templateId: template.id, revision: confirming, action: 'reissue' },
                                    () => ({ templateId: template.id, body: { revision: confirming } }),
                                );
                                void start
                                    .mutateAsync({ ...attempt.input, idempotencyKey: attempt.idempotencyKey })
                                    .then(() => {
                                        startWrite.complete(attempt);
                                        setConfirming(null);
                                        setCancelled(false);
                                    })
                                    .catch(() => undefined);
                            }}
                        >
                            {studioStrings.reissueConfirm}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
