import { translate as uiText } from '@/shared/i18n';
import { orgWorkspaceStrings as ws } from './workspace-strings';
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Organisation, OrganisationCleanupJob } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorState,
    buttonVariants,
    type DataTableColumn,
} from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import { FilterSelect } from '../components/filter-toolbar';
import { ListFrame } from '../components/list-frame';
import { organisationKeys } from './api';

const s = {
    privacy: 'Relationship data and exports',
    privacyIntro:
        'Exports contain this organization’s records only. Immutable grants and audit evidence may retain identifiers; removing membership is not a claim of complete anonymisation.',
    retentionNone: 'This organization uses the site’s own retention floors.',
    retentionSet: 'This organization keeps its administrator access log for',
    retentionFloor: 'The site keeps every administrator access log entry for at least',
    retentionDays: 'days.',
    missing: 'Some data contributors are unavailable. This export is incomplete.',
    contributor: 'Data contributor',
    recordClass: 'Record type',
    records: 'Export records',
    empty: 'No records on this page.',
    prepare: 'Prepare this page',
    download: 'Download this page (JSON)',
    exportCsv: 'Prepare this activity page (CSV)',
    downloadCsv: 'Download activity page',
    csvNote:
        'The CSV contains this page only. Download other pages using the same snapshot for a stable audit export.',
    cleanup: 'Access cleanup',
    cleanupNote:
        'Organization access is disabled immediately. These durable jobs finish relationship cleanup in bounded batches without removing unrelated learning history.',
    id: 'Job',
    state: 'Status',
    reason: 'Reason',
    processed: 'Records processed',
    action: 'Actions',
    retry: 'Retry cleanup',
    retried: 'Cleanup queued again.',
    report: 'Export completeness',
    complete: 'All installed contributors available',
    incomplete: 'Missing contributors',
};
function Download({ blob, filename, label }: { blob: Blob | null; filename: string; label: string }) {
    const [url, setUrl] = useState<string | null>(null);
    useEffect(() => {
        if (blob === null) {
            setUrl(null);
            return;
        }
        const next = URL.createObjectURL(blob);
        setUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [blob]);
    return url ? (
        <a className={buttonVariants({ variant: 'outline', size: 'sm' })} href={url} download={filename}>
            {label}
        </a>
    ) : null;
}
export function OrganisationAuditExport({ id, page }: { id: number; page: number }) {
    const client = useApiClient();
    const [snapshot, setSnapshot] = useState<number | undefined>();
    const download = useMutation({
        mutationFn: async () => {
            const result = await client.GET('/manage/organisations/{id}/events/export', {
                params: { path: { id }, query: { page, per_page: 20, snapshot_id: snapshot } },
                parseAs: 'text',
            });
            const next = Number(result.response.headers.get('X-Snapshot-Id'));
            if (Number.isSafeInteger(next) && next >= 0) setSnapshot(next);
            return { blob: new Blob([must(result.data)], { type: 'text/csv;charset=utf-8' }), page };
        },
    });
    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={download.isPending}
                    onClick={() => download.mutate()}
                >
                    {s.exportCsv}
                </Button>
                <Download
                    blob={download.data?.page === page ? download.data.blob : null}
                    filename={`organisation-${id}-activity-page-${page}.csv`}
                    label={s.downloadCsv}
                />
            </div>
            <p className="text-xs text-fg-2">{s.csvNote}</p>
            {download.isError && <ErrorState error={download.error} />}
        </div>
    );
}
export function OrganisationPrivacy({ id }: { id: number }) {
    const client = useApiClient();
    const [choice, setChoice] = useState('');
    const [page, setPage] = useState(1);
    const catalogue = useQuery({
        queryKey: [...organisationKeys.root, id, 'privacy'],
        queryFn: async ({ signal }) =>
            must(
                (await client.GET('/manage/organisations/{id}/privacy', { params: { path: { id } }, signal }))
                    .data,
            ),
    });
    const options =
        catalogue.data?.items.flatMap((item) =>
            Object.entries(item.record_classes).map(([key, label]) => ({
                value: `${item.key}/${key}`,
                label: uiText("vidyalms.ui.ae04d0e6f63d1e51", "{p0} · {p1}", { p0: item.key, p1: label }),
            })),
        ) ?? [];
    const selected = choice || options[0]?.value || '';
    const [contributor, recordClass] = selected.split('/');
    const query = useQuery({
        queryKey: [...organisationKeys.root, id, 'privacy-export', selected, page],
        enabled: selected !== '',
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/privacy/export', {
                        params: {
                            path: { id },
                            query: { contributor, record_class: recordClass, page, per_page: 20 },
                        },
                        signal,
                    })
                ).data,
            ),
    });
    const [exported, setExported] = useState<{ blob: Blob; selection: string; page: number } | null>(null);
    return (
        <section className="grid gap-3">
            <div>
                <h3 className="font-display text-lg font-semibold text-fg">{s.privacy}</h3>
                <p className="mt-2 text-sm text-fg-2">{s.privacyIntro}</p>
                <p className="mt-1 text-xs text-fg-2">
                    {catalogue.data?.retention_policy?.access_log_days
                        ? uiText("vidyalms.ui.542e783f2e450674", "{p0} {p1} {p2}", { p0: s.retentionSet, p1: catalogue.data.retention_policy.access_log_days, p2: s.retentionDays })
                        : s.retentionNone}
                    {catalogue.data?.access_log_floor_days
                        ? uiText("vidyalms.ui.5927484144f06724", " {p0} {p1} {p2}", { p0: s.retentionFloor, p1: catalogue.data.access_log_floor_days, p2: s.retentionDays })
                        : ''}
                </p>
            </div>
            {catalogue.isError && (
                <ErrorState
                    error={catalogue.error}
                    onRetry={() => {
                        void catalogue.refetch();
                    }}
                />
            )}
            {(catalogue.data?.missing_contributors.length ?? 0) > 0 && (
                <p role="status" className="text-sm text-warn-strong">
                    {s.missing}
                </p>
            )}
            <ListFrame
                title={s.records}
                chips={
                    options.length ? (
                        <FilterSelect
                            label={s.recordClass}
                            value={selected}
                            options={options}
                            onValueChange={(value) => {
                                setChoice(value);
                                setPage(1);
                            }}
                        />
                    ) : undefined
                }
                action={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={!query.data || query.isFetching}
                            onClick={() => {
                                if (query.data)
                                    setExported({
                                        blob: new Blob([JSON.stringify(query.data, null, 2)], {
                                            type: 'application/json',
                                        }),
                                        selection: selected,
                                        page,
                                    });
                            }}
                        >
                            {s.prepare}
                        </Button>
                        <Download
                            blob={
                                exported?.selection === selected && exported.page === page
                                    ? exported.blob
                                    : null
                            }
                            filename={`organisation-${id}-${recordClass}-page-${page}.json`}
                            label={s.download}
                        />
                    </div>
                }
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
            >
                {query.data && (
                    <div className="grid gap-3 p-4">
                        <p className="text-xs text-fg-2">
                            {s.report}: {query.data.complete ? s.complete : s.incomplete}
                        </p>
                        {query.data.items.length === 0 ? (
                            <EmptyState title={s.empty} />
                        ) : (
                            <details className="vy-org-export-details">
                                <summary>{ws.recordPreview}</summary>
                                <pre className="max-h-64 overflow-auto rounded border border-border bg-surface-2 p-3 font-mono text-xs text-fg">
                                    {JSON.stringify(query.data.items, null, 2)}
                                </pre>
                            </details>
                        )}
                    </div>
                )}
            </ListFrame>
        </section>
    );
}
export function OrganisationCleanup({
    organisation: org,
    notify,
}: {
    organisation: Organisation;
    notify: (text: string) => void;
}) {
    const client = useApiClient();
    const cache = useQueryClient();
    const [page, setPage] = useState(1);
    const write = useWriteAttempt();
    const query = useQuery({
        queryKey: [...organisationKeys.root, org.id, 'cleanup', page],
        refetchInterval: 10000,
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/cleanup-jobs', {
                        params: { path: { id: org.id }, query: { page, per_page: 20 } },
                        signal,
                    })
                ).data,
            ),
    });
    const retry = useMutation({
        mutationFn: async (input: { jobId: number; revision: number; key: string }) =>
            must(
                (
                    await client.POST('/manage/organisations/{id}/cleanup-jobs/{job_id}/retry', {
                        params: {
                            path: { id: org.id, job_id: input.jobId },
                            header: { 'Idempotency-Key': input.key },
                        },
                        body: { expected_revision: input.revision },
                    })
                ).data,
            ),
        onSuccess: () => cache.invalidateQueries({ queryKey: organisationKeys.root }),
    });
    const columns: DataTableColumn<OrganisationCleanupJob>[] = [
        { key: 'id', header: s.id, width: '90px', cell: (row) => row.id },
        { key: 'reason', header: s.reason, weight: 2, minWidth: 180, cell: (row) => row.reason },
        {
            key: 'state',
            header: s.state,
            width: '120px',
            cell: (row) => <Badge variant={row.state === 'failed' ? 'warn' : 'neutral'}>{row.state}</Badge>,
        },
        { key: 'processed', header: s.processed, width: '150px', cell: (row) => row.processed_count },
        {
            key: 'action',
            header: s.action,
            width: '150px',
            cell: (row) =>
                row.state === 'failed' ? (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={retry.isPending}
                        onClick={() => {
                            const attempt = write.capture({ jobId: row.id }, () => ({
                                jobId: row.id,
                                revision: org.revision,
                            }));
                            void retry
                                .mutateAsync({ ...attempt.input, key: attempt.idempotencyKey })
                                .then(() => {
                                    write.complete(attempt);
                                    notify(s.retried);
                                })
                                .catch(() => undefined);
                        }}
                    >
                        {s.retry}
                    </Button>
                ) : null,
        },
    ];
    return (
        <ListFrame
            title={s.cleanup}
            note={s.cleanupNote}
            page={page}
            perPage={20}
            total={query.data?.total ?? 0}
            shownCount={query.data?.items.length ?? 0}
            onPageChange={setPage}
            banner={
                query.isError || retry.isError ? (
                    <ErrorState
                        error={query.error ?? retry.error}
                        onRetry={() => {
                            void query.refetch();
                        }}
                    />
                ) : undefined
            }
        >
            <DataTable
                caption={s.cleanup}
                rows={query.data?.items ?? []}
                rowKey={(row) => row.id}
                columns={columns}
                loading={query.isPending}
                empty={<EmptyState title={s.empty} />}
            />
        </ListFrame>
    );
}
