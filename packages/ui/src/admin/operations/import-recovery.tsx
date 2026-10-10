import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { Button, Dialog, DialogContent, DialogTitle, DialogDescription, ErrorState } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { idempotency, must } from '@/shared/manage';
import { useAdminConfig } from '../config';
import { newIdempotencyKey } from '@/shared/save-state';
import { importTransport, importError } from './import-wizard';

export function ImportRecovery({
    job,
    onOpen,
}: {
    job: components['schemas']['ImportJob'];
    onOpen: (id: number) => void;
}) {
    const transport = useAdminConfig().importTransport;
    const client = useApiClient();
    const cache = useQueryClient();
    const [open, setOpen] = useState(false);
    const [operation] = useState(newIdempotencyKey);
    const [action, setAction] = useState<'retry' | 'abandon'>(job.source && !job.dry_run ? 'retry' : 'abandon');
    const mutation = useMutation({
        mutationFn: async () => {
            if (!transport) throw importError('Import recovery is unavailable.');
            // The host checks the current heartbeat before ending unfinished work. Repeating this preparation is safe.
            const body = new FormData();
            body.set('operation', 'abandon');
            body.set('job_id', String(job.id));
            await importTransport(transport, body);
            if (action === 'abandon') return job.id;
            if (!job.source || job.dry_run) throw importError('Prepare a new preview before importing this source.');
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
            ).id;
        },
        onSuccess: (id) => {
            void cache.invalidateQueries({ queryKey: ['vidyalms', 'import-job'] });
            void cache.invalidateQueries({ queryKey: ['vidyalms', 'imports'] });
            setOpen(false);
            onOpen(id);
        },
    });
    if (!transport || (!job.stalled && !['failed', 'completed_with_problems'].includes(job.status))) return null;
    return (
        <>
            <div className="vy-page-actions-row">
                <span className="vy-workspace-muted">{uiText("vidyalms.ui.59db1da74698d168", "Review the recorded results before recovering this import.")}</span>
                <Button variant="outline" onClick={() => setOpen(true)}>
                    {uiText("vidyalms.ui.225ca4f8b720abf2", "Review recovery")}</Button>
            </div>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!mutation.isPending) setOpen(value);
                }}
            >
                <DialogContent>
                    <DialogTitle>{uiText("vidyalms.ui.2f8c6653a4c28181", "Recover import #")}{job.id}</DialogTitle>
                    <DialogDescription>
                        {uiText("vidyalms.ui.f1854df07be3001e", "Existing records are retained. Retrying uses the source mapping to update records already imported; it does not roll back previous work.")}</DialogDescription>
                    <div className="grid gap-3 mt-4">
                        <label className="grid gap-2 text-sm">
                            {uiText("vidyalms.ui.6ebb2a4d92df81fa", "Recovery action")}<select
                                className="rounded border border-border bg-surface p-2"
                                value={action}
                                disabled={mutation.isPending}
                                onChange={(event) => setAction(event.target.value as 'retry' | 'abandon')}
                            >
                                <option value="retry" disabled={!job.source || job.dry_run}>
                                    {uiText("vidyalms.ui.b4e9f3c8fce69858", "Retry the retained source")}</option>
                                <option value="abandon">{uiText("vidyalms.ui.386ecbc7879f9412", "End this stalled run")}</option>
                            </select>
                        </label>
                        {!job.source && (
                            <p className="text-sm text-fg-2">
                                {uiText("vidyalms.ui.ec406f39f54a7d2b", "The source has been removed by retention. Upload it again to start a new import.")}</p>
                        )}
                        {mutation.isError && <ErrorState error={mutation.error} />}
                        <Button
                            disabled={
                                mutation.isPending ||
                                (action === 'retry' && (!job.source || job.dry_run)) ||
                                (action === 'abandon' && !job.stalled)
                            }
                            onClick={() => mutation.mutate()}
                        >
                            {mutation.isPending ? uiText("vidyalms.ui.5474eef8d0f179c7", "Working…") : action === 'retry' ? uiText("vidyalms.ui.0bc65fbb23dc7c54", "Retry import") : uiText("vidyalms.ui.626f64b33c9edff3", "End stalled run")}
                        </Button>
                        <Button variant="outline" disabled={mutation.isPending} onClick={() => setOpen(false)}>
                            {uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel")}</Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
