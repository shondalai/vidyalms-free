import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { translate as text } from '@/shared/i18n';
import { Button, ErrorState } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { newIdempotencyKey } from '@/shared/save-state';
import { checkUpload, policyFor, uploadFile } from '@/shared/upload';
import { useAdminConfig } from '../config';
import { SelectField, TextField } from '../components/field';
import { organisationKeys } from './api';

export function OrganisationRosterImport({ organisationId }: { organisationId: number }) {
    const config = useAdminConfig();
    const client = useApiClient();
    const queries = useQueryClient();
    const [format, setFormat] = useState('org-members-csv:in');
    const [file, setFile] = useState<File | null>(null);
    const [jobId, setJobId] = useState<number | null>(null);
    const preview = useMutation({
        mutationFn: async () => {
            if (!file || !config.urls.upload) throw new Error(text('vidyalms.ui.roster.choose_file', 'Choose a roster file.'));
            const policy = policyFor({
                purpose: 'org_import', maxBytes: 64 * 1024 * 1024,
                extensions: format === 'org-members-csv:in' ? ['csv'] : ['zip'],
            }, config.uploadPurposes);
            const problem = checkUpload(file, { ...policy, extensions: format === 'org-members-csv:in' ? ['csv'] : ['zip'] });
            if (problem) throw new Error(problem);
            const stored = await uploadFile({
                endpoint: config.urls.upload, headers: config.api.headers, file, purpose: 'org_import', organisationId,
            });
            const response = await client.POST('/manage/organisations/{id}/imports', {
                params: { path: { id: organisationId } }, headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: { importer: format, source: stored.path, dry_run: true, options: {} },
            });
            return { job: must(response.data), source: stored.path, importer: format, key: newIdempotencyKey() };
        },
    });
    const start = useMutation({
        mutationFn: async () => {
            if (!preview.data || preview.data.job.status !== 'previewed') throw new Error(text('vidyalms.ui.roster.preview_first', 'Preview this file first.'));
            const response = await client.POST('/manage/organisations/{id}/imports', {
                params: { path: { id: organisationId } }, headers: { 'Idempotency-Key': preview.data.key },
                body: { importer: preview.data.importer, source: preview.data.source, dry_run: false, options: {} },
            });
            return must(response.data);
        },
        onSuccess: (job) => {
            setJobId(job.id);
            void queries.invalidateQueries({ queryKey: organisationKeys.root });
        },
    });
    const progress = useQuery({
        queryKey: [...organisationKeys.root, organisationId, 'roster', jobId], enabled: jobId !== null,
        queryFn: async ({ signal }) => must((await client.GET('/manage/organisations/{id}/imports/{job_id}', {
            params: { path: { id: organisationId, job_id: jobId! } }, signal,
        })).data),
        refetchInterval: (query) => ['pending', 'running'].includes(query.state.data?.status ?? 'pending') ? 2000 : false,
    });
    if (!config.urls.upload) return null;
    const busy = preview.isPending || start.isPending;
    const job = progress.data ?? start.data ?? preview.data?.job;
    const statuses = {
        pending: text('vidyalms.ui.roster.queued', 'Import queued'),
        running: text('vidyalms.ui.roster.running', 'Import in progress'),
        completed: text('vidyalms.ui.roster.completed', 'Import completed'),
        completed_with_problems: text('vidyalms.ui.roster.completed_problems', 'Import completed with row problems'),
        previewed: text('vidyalms.ui.roster.previewed', 'Preview ready'),
        failed: text('vidyalms.ui.roster.failed', 'Import failed'),
    };
    return (
        <section className="grid gap-3 rounded border border-border p-4" aria-label={text('vidyalms.ui.roster.title', 'Import people')}>
            <h3>{text('vidyalms.ui.roster.title', 'Import people')}</h3>
            <p>{text('vidyalms.ui.roster.intro', 'Upload a member CSV or OneRoster ZIP for this organization. Review the preview before starting the import.')}</p>
            <SelectField label={text('vidyalms.ui.roster.format', 'Roster format')} value={format} disabled={busy} onChange={(event) => {
                setFormat(event.target.value);
                preview.reset();
                start.reset();
                setJobId(null);
            }}>
                <option value="org-members-csv:in">{text('vidyalms.ui.roster.csv', 'Member CSV')}</option>
                <option value="oneroster-csv-1.2:in">{text('vidyalms.ui.roster.oneroster', 'OneRoster 1.2 ZIP')}</option>
            </SelectField>
            <TextField label={text('vidyalms.ui.roster.file', 'Roster file')} type="file" accept={format === 'org-members-csv:in' ? '.csv' : '.zip'} disabled={busy} onChange={(event) => {
                setFile(event.target.files?.[0] ?? null);
                preview.reset();
                start.reset();
                setJobId(null);
            }} />
            <div className="flex gap-2">
                <Button disabled={!file || busy} onClick={() => preview.mutate()}>{text('vidyalms.ui.roster.preview', 'Preview roster')}</Button>
                {preview.data?.job.status === 'previewed' && jobId === null && <Button disabled={busy} onClick={() => start.mutate()}>{text('vidyalms.ui.roster.start', 'Start import')}</Button>}
            </div>
            {job && <div role="status"><p>{statuses[job.status]}</p><p>
                {text('vidyalms.ui.roster.count_created', 'Created')}: {job.totals.created ?? 0};{' '}
                {text('vidyalms.ui.roster.count_updated', 'Updated')}: {job.totals.updated ?? 0};{' '}
                {text('vidyalms.ui.roster.count_skipped', 'Skipped')}: {job.totals.skipped ?? 0};{' '}
                {text('vidyalms.ui.roster.count_failed', 'Failed')}: {job.totals.failed ?? 0}
            </p>{job.log && <pre className="whitespace-pre-wrap text-xs">{job.log}</pre>}</div>}
            {preview.isError && <ErrorState error={preview.error} />}
            {start.isError && <ErrorState error={start.error} />}
            {progress.isError && <ErrorState error={progress.error} />}
        </section>
    );
}
