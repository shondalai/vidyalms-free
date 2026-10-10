import { translate as uiText } from '@/shared/i18n';
import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { ProblemError } from '@vidyalms/api-sdk';
import { ArrowRight, Database, FileUp, ScanLine, CheckCheck } from 'lucide-react';
import { Button, ErrorState, EmptyState, Skeleton, Badge } from '@/shared/components';
import { SelectField, TextField } from '../components/field';
import { useApiClient } from '@/shared/context';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { must, idempotency } from '@/shared/manage';
import { useAdminConfig, type AdminConfig } from '../config';
import { WorkspaceCallout } from '../components/workspace-page';
import { newIdempotencyKey } from '@/shared/save-state';
import { useImportJob } from './api';

const optionSchema = z.object({
    key: z.string(),
    label: z.string(),
    kind: z.enum(['text', 'number', 'boolean', 'choice']),
    help: z.string(),
    default: z.string(),
    choices: z.array(z.object({ value: z.string(), label: z.string() })),
});
const catalogueSchema = z.object({
    max_bytes: z.number().nonnegative(),
    importers: z.array(
        z.object({
            key: z.string(),
            label: z.string(),
            extensions: z.array(z.string()),
            accepts_file: z.boolean(),
            accepts_database: z.boolean(),
            source_installed: z.boolean().optional(),
            options: z.array(optionSchema),
        }),
    ),
});
type Transport = NonNullable<AdminConfig['importTransport']>;

export function importError(detail: string, status = 422) {
    return new ProblemError({ type: 'about:blank', title: uiText("vidyalms.ui.636689949ad8c827", "Import could not continue"), status, detail });
}

export async function importTransport(transport: Transport, body: FormData, signal?: AbortSignal): Promise<unknown> {
    const url = new URL(transport.url, window.location.href);
    if (url.origin !== window.location.origin) throw importError('The import endpoint must belong to this site.');
    body.set(transport.nonceName, transport.nonce);
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', body, signal });
    const payload: unknown = await response.json().catch(() => null);
    const error = z.object({ error: z.string() }).safeParse(payload);
    if (!response.ok || error.success)
        throw importError(
            error.success
                ? error.data.error
                : 'The import request could not be completed. Check import history before trying again.',
        );
    return payload;
}

export function ImportWizard({ onOpen }: { onOpen: (id: number) => void }) {
    const transport = useAdminConfig().importTransport;
    const [selected, setSelected] = useState('');
    const [showPicker, setShowPicker] = useState(true);
    const [search, setSearch] = useState('');
    const [mode, setMode] = useState('file');
    const [file, setFile] = useState<File | null>(null);
    const [selector, setSelector] = useState('');
    const [options, setOptions] = useState<Record<string, string>>({});
    const [previewId, setPreviewId] = useState<number | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const query = useQuery({
        queryKey: ['vidyalms', 'import-catalogue', transport?.url],
        enabled: !!transport,
        queryFn: async ({ signal }) => {
            if (!transport) throw importError('Import preparation is unavailable.');
            const body = new FormData();
            body.set('operation', 'catalogue');
            return catalogueSchema.parse(await importTransport(transport, body, signal));
        },
    });
    const importer = query.data?.importers.find((item) => item.key === selected);
    const preview = useMutation({
        mutationFn: async () => {
            if (!transport || !importer) throw importError('Choose an import source.');
            if (mode === 'file' && (!file || file.size === 0)) throw importError('Choose a non-empty file to preview.');
            if (file && mode === 'file' && query.data && file.size > query.data.max_bytes)
                throw importError('This file exceeds the upload limit.');
            const body = new FormData();
            body.set('operation', 'preview');
            body.set('importer', importer.key);
            body.set('source_mode', mode);
            body.set('selector', selector);
            if (file && mode === 'file') body.set('import_file', file);
            importer.options.forEach((option) =>
                body.set(`options[${option.key}]`, options[option.key] ?? option.default),
            );
            return z.object({ id: z.number().int().positive() }).parse(await importTransport(transport, body));
        },
        onSuccess: (result) => setPreviewId(result.id),
    });
    useNavigationGuard(file !== null || selector !== '' || preview.isPending);
    if (!transport)
        return (
            <EmptyState
                title={uiText("vidyalms.ui.099dce15e1e881cb", "Source preparation is not available in this workspace")}
                description={uiText("vidyalms.ui.e55f8f0767560e52", "Open the host import form to upload a source and configure its options.")}
            />
        );
    if (query.isPending) return <Skeleton className="h-48 w-full" />;
    if (query.isError) return <ErrorState error={query.error} onRetry={() => void query.refetch()} />;
    if (previewId !== null)
        return (
            <ImportReview
                id={previewId}
                onBack={() => setPreviewId(null)}
                onOpen={(id) => {
                    setFile(null);
                    setSelector('');
                    setPreviewId(null);
                    setSelected('');
                    setOptions({});
                    setShowPicker(true);
                    onOpen(id);
                }}
            />
        );
    return (
        <>
            <ol className="vy-import-steps" aria-label={uiText("vidyalms.ui.d3d5b27efc6d23af", "Import progress")}>
                <li aria-current="step">
                    <span>1</span>{uiText("vidyalms.ui.de8133e1a512bf81", "Choose a source")}</li>
                <li>
                    <span>2</span>{uiText("vidyalms.ui.256ec8e0df14bcd2", "Preview & review")}</li>
                <li>
                    <span>3</span>{uiText("vidyalms.ui.4cb1619585d77256", "Import & track")}</li>
            </ol>
            <div className="vy-service-grid">
                <div className="vy-workspace-panel">
                    <header>
                        <span className="vy-workspace-glyph">
                            <FileUp size={22} />
                        </span>
                        <div>
                            <h2>{uiText("vidyalms.ui.b39a5c8b68e38004", "Bring your learning content together")}</h2>
                            <p>{uiText("vidyalms.ui.6d87380a43745978", "Select an installed importer, then prepare a preview.")}</p>
                        </div>
                    </header>
                    {showPicker ? (
                        <TextField
                            className="mb-4"
                            label={uiText("vidyalms.ui.2cb9bf2957c68cc6", "Find an importer")}
                            type="search"
                            placeholder={uiText("vidyalms.ui.abfb13a4a8c62602", "Search formats and source platforms…")}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    ) : (
                        <Button
                            variant="outline"
                            className="mb-4"
                            onClick={() => setShowPicker(true)}
                            disabled={preview.isPending}
                        >
                            {uiText("vidyalms.ui.34a6a9b07b12ebb9", "Change source")}</Button>
                    )}
                    {query.data.importers.length === 0 ? (
                        <EmptyState
                            title={uiText("vidyalms.ui.a6d6ccfcb02ca999", "No importers are installed")}
                            description={uiText("vidyalms.ui.46dff65c29da50a7", "Install an importer package to bring content into VidyaLMS.")}
                        />
                    ) : (
                        <div className="vy-import-sources" role="group" aria-label={uiText("vidyalms.ui.dbff5bdee4bda1d2", "Import source")}>
                            {query.data.importers
                                .filter((item) =>
                                    showPicker
                                        ? `${item.label} ${item.extensions.join(' ')}`
                                              .toLowerCase()
                                              .includes(search.toLowerCase())
                                        : item.key === selected,
                                )
                                .map((item) => (
                                    <button
                                        type="button"
                                        key={item.key}
                                        disabled={preview.isPending}
                                        aria-pressed={selected === item.key}
                                        onClick={() => {
                                            setShowPicker(false);
                                            if (selected === item.key) return;
                                            setSelected(item.key);
                                            setOptions({});
                                            setFile(null);
                                            if (fileInput.current) fileInput.current.value = '';
                                            setMode(item.accepts_file ? 'file' : 'database');
                                        }}
                                    >
                                        <span className="vy-workspace-glyph">
                                            {item.accepts_file ? <FileUp size={20} /> : <Database size={20} />}
                                        </span>
                                        <strong>{item.label}</strong>
                                        <small>
                                            {item.accepts_file
                                                ? item.extensions.map((extension) => `.${extension}`).join(' · ')
                                                : uiText("vidyalms.ui.bf8b4b12b94336d5", "Connected CMS database")}
                                        </small>
                                    </button>
                                ))}
                        </div>
                    )}
                    {importer && (
                        <form
                            className="vy-import-form"
                            hidden={showPicker}
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (!preview.isPending) preview.mutate();
                            }}
                        >
                            <fieldset disabled={preview.isPending}>
                                <legend className="font-semibold">{uiText("vidyalms.ui.23c778bec4760c6e", "Configure ")}{importer.label}</legend>
                                {importer.accepts_file && importer.accepts_database && (
                                    <SelectField
                                        label={uiText("vidyalms.ui.8c973c600eb51d3f", "Read content from")}
                                        value={mode}
                                        onChange={(event) => setMode(event.target.value)}
                                    >
                                        <option value="file">{uiText("vidyalms.ui.e44f3a85acf5fb04", "Upload a file")}</option>
                                        <option value="database">{uiText("vidyalms.ui.01fabd5a8786f503", "This CMS database")}</option>
                                    </SelectField>
                                )}
                                {mode === 'file' ? (
                                    <label className="vy-import-upload">
                                        <FileUp size={30} />
                                        <strong>{file?.name ?? uiText("vidyalms.ui.4e2d26e58ba8173d", "Choose your source file")}</strong>
                                        <span>
                                            {uiText("vidyalms.ui.8f70d60e3ca2e425", "Maximum ")}{(query.data.max_bytes / 1024 / 1024).toFixed(1)} {uiText("vidyalms.ui.48f03e2d369f56f2", " MB ·")}{' '}
                                            {importer.extensions.join(', ')}
                                        </span>
                                        <input
                                            ref={fileInput}
                                            type="file"
                                            aria-label={uiText("vidyalms.ui.6a9e73cf0d421411", "Import file")}
                                            accept={importer.extensions.map((extension) => `.${extension}`).join(',')}
                                            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                                            required
                                        />
                                    </label>
                                ) : (
                                    <TextField
                                        label={uiText("vidyalms.ui.45bebb8ecb276351", "Source selection")}
                                        value={selector}
                                        placeholder={uiText("vidyalms.ui.19195b93d701f11d", "e.g. categories=3,7")}
                                        hint={uiText("vidyalms.ui.aecd9807b943604e", "Leave empty to include all content supported by this importer.")}
                                        onChange={(event) => setSelector(event.target.value)}
                                    />
                                )}
                                {mode === 'database' && importer.source_installed === false && (
                                    <p role="status" className="vy-workspace-muted">
                                        {uiText("vidyalms.ui.15f0bd5925ce52e2", "The source extension is not installed on this site.")}</p>
                                )}
                                {importer.options.map((option) =>
                                    option.kind === 'choice' || option.kind === 'boolean' ? (
                                        <SelectField
                                            key={option.key}
                                            label={option.label}
                                            hint={option.help}
                                            value={options[option.key] ?? option.default}
                                            onChange={(event) =>
                                                setOptions((current) => ({
                                                    ...current,
                                                    [option.key]: event.target.value,
                                                }))
                                            }
                                        >
                                            {(option.kind === 'boolean'
                                                ? [
                                                      { value: '0', label: uiText("vidyalms.ui.1ea442a134b2a184", "No") },
                                                      { value: '1', label: uiText("vidyalms.ui.85a39ab345d672ff", "Yes") },
                                                  ]
                                                : option.choices
                                            ).map((choice) => (
                                                <option key={choice.value} value={choice.value}>
                                                    {choice.label}
                                                </option>
                                            ))}
                                        </SelectField>
                                    ) : (
                                        <TextField
                                            key={option.key}
                                            label={option.label}
                                            hint={option.help}
                                            type={option.kind === 'number' ? 'number' : 'text'}
                                            value={options[option.key] ?? option.default}
                                            onChange={(event) =>
                                                setOptions((current) => ({
                                                    ...current,
                                                    [option.key]: event.target.value,
                                                }))
                                            }
                                        />
                                    ),
                                )}
                            </fieldset>
                            {preview.isError && <ErrorState error={preview.error} />}
                            <Button
                                type="submit"
                                disabled={
                                    preview.isPending || (mode === 'database' && importer.source_installed === false)
                                }
                            >
                                <ScanLine size={16} />
                                {preview.isPending ? uiText("vidyalms.ui.11e66e1bb9b76293", "Preparing preview…") : uiText("vidyalms.ui.ab5c8e55ce2c0b2f", "Preview import")}
                            </Button>
                            {preview.isPending && (
                                <p role="status" className="vy-workspace-muted">
                                    {uiText("vidyalms.ui.86a90c30292afae1", "Large sources can take a few minutes. Keep this page open while the preview is prepared.")}</p>
                            )}
                        </form>
                    )}
                </div>
                <aside className="vy-workspace-panel">
                    <h2>{uiText("vidyalms.ui.cdeb199c357cc2fa", "A confident start")}</h2>
                    <ol className="vy-import-guide">
                        <li>
                            <strong>{uiText("vidyalms.ui.7320e93157007fbc", "1. Prepare your source")}</strong>
                            <p>{uiText("vidyalms.ui.8f05aad6f6df593b", "Choose the format and options that match your content.")}</p>
                        </li>
                        <li>
                            <strong>{uiText("vidyalms.ui.a89dece0e12102e7", "2. Review the preview")}</strong>
                            <p>{uiText("vidyalms.ui.eb41daa2fd9d81b0", "Check the counts and warnings before applying any changes.")}</p>
                        </li>
                        <li>
                            <strong>{uiText("vidyalms.ui.2b62e73443617766", "3. Follow progress")}</strong>
                            <p>
                                {uiText("vidyalms.ui.8cdede3dcca2f566", "Imports run in the background. Open history to inspect results and recover interrupted work.")}</p>
                        </li>
                    </ol>
                </aside>
            </div>
        </>
    );
}

function ImportReview({ id, onBack, onOpen }: { id: number; onBack: () => void; onOpen: (id: number) => void }) {
    const query = useImportJob(id);
    const client = useApiClient();
    const cache = useQueryClient();
    const [operation] = useState(newIdempotencyKey);
    const apply = useMutation({
        mutationFn: async () => {
            const job = query.data;
            if (!job || job.status !== 'previewed' || !job.source)
                throw importError('Only a completed preview with an available source can be applied.');
            return must(
                (
                    await client.POST('/import', {
                        headers: idempotency(operation),
                        body: {
                            importer: job.importer,
                            source: job.source,
                            options: { ...job.options, dry_run: false },
                            dry_run: false,
                        },
                    })
                ).data,
            );
        },
        onSuccess: (job) => {
            void cache.invalidateQueries({ queryKey: ['vidyalms', 'imports'] });
            onOpen(job.id);
        },
    });
    useNavigationGuard(apply.isPending);
    return (
        <>
            <ol className="vy-import-steps" aria-label={uiText("vidyalms.ui.d3d5b27efc6d23af", "Import progress")}>
                <li>
                    <span>
                        <CheckCheck size={16} />
                    </span>
                    {uiText("vidyalms.ui.ed3fc07a67f6ed3b", "Source ready")}</li>
                <li aria-current="step">
                    <span>2</span>{uiText("vidyalms.ui.256ec8e0df14bcd2", "Preview & review")}</li>
                <li>
                    <span>3</span>{uiText("vidyalms.ui.4cb1619585d77256", "Import & track")}</li>
            </ol>
            <WorkspaceCallout icon={ScanLine} title={uiText("vidyalms.ui.3fae57c8a90d040a", "Review before you import")}>
                {uiText("vidyalms.ui.bdee8a7e410f5ab1", "The preview has not applied content changes. Check its totals and notes, then start the import when you are ready.")}</WorkspaceCallout>
            {query.isPending && <Skeleton className="h-48 w-full" />}
            {query.isError && <ErrorState error={query.error} onRetry={() => void query.refetch()} />}
            {query.data && (
                <section className="vy-workspace-panel">
                    <header>
                        <div>
                            <h2>{uiText("vidyalms.ui.aa5df60fbec24af4", "Preview #")}{id}</h2>
                            <p>{query.data.importer}</p>
                        </div>
                        <Badge>{query.data.status}</Badge>
                    </header>
                    <dl className="vy-detail-facts">
                        {Object.entries(query.data.totals).map(([label, value]) => (
                            <div key={label}>
                                <dt>{label.replaceAll('_', ' ')}</dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <ul className="vy-import-notes">
                        {query.data.notes.map((note, index) => (
                            <li key={index}>
                                <Badge variant={note.level === 'info' ? 'neutral' : 'warn'}>{note.level}</Badge>
                                <span>{note.message}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {apply.isError && <ErrorState error={apply.error} />}
            <div className="vy-page-actions-row">
                <Button variant="outline" onClick={onBack} disabled={apply.isPending}>
                    {uiText("vidyalms.ui.67a541eacd4df206", "Back to source")}</Button>
                <Button
                    disabled={apply.isPending || query.data?.status !== 'previewed' || !query.data?.source}
                    onClick={() => apply.mutate()}
                >
                    {apply.isPending ? uiText("vidyalms.ui.d4840ced34ff37d4", "Starting import…") : uiText("vidyalms.ui.c40182c706b70eb2", "Apply this import")}
                    <ArrowRight size={16} />
                </Button>
            </div>
        </>
    );
}
