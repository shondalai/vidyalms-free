import { useRef, useState } from 'react';
import { CheckCircle2, Clock3, Download, RefreshCw } from 'lucide-react';
import { useApiClient } from '@/shared/context';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    ErrorState,
    Skeleton,
} from '@/shared/components';
import { exportReport, useReportRun } from './api';
import { formatValue, type Catalogue, type Parameters, type ReportDefinition } from './model';
import { reportWorkspaceStrings as s } from './strings';

export function ExportDialog({
    report,
    catalogue,
    parameters,
    organisation,
    onClose,
    onAccepted,
}: {
    report: ReportDefinition;
    catalogue: Catalogue;
    parameters: Parameters;
    organisation: number | null;
    onClose: () => void;
    onAccepted: (id: number) => void;
}) {
    const client = useApiClient();
    const formats = (catalogue.formats ?? []).filter((format) => format.available !== false);
    const [format, setFormat] = useState(formats[0]?.key ?? '');
    const [delivery, setDelivery] = useState('');
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const replay = useRef({ fingerprint: '', key: '' });
    const start = async () => {
        const fingerprint = JSON.stringify({ report: report.key, parameters, organisation, format, delivery });
        if (replay.current.fingerprint !== fingerprint) replay.current = { fingerprint, key: crypto.randomUUID() };
        setPending(true);
        setError(null);
        try {
            const run = await exportReport(
                client,
                report.key,
                parameters,
                organisation,
                format,
                delivery,
                replay.current.key,
            );
            onAccepted(run.id);
        } catch (failure) {
            setError(failure);
        } finally {
            setPending(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !pending) onClose();
            }}
        >
            <DialogContent className="vy-report-dialog">
                <DialogTitle>{s.export}</DialogTitle>
                <DialogDescription>
                    {report.label}. {s.exportHint}
                </DialogDescription>
                {!formats.length ? (
                    <p>{s.noFormats}</p>
                ) : (
                    <form
                        className="vy-report-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void start();
                        }}
                    >
                        <label className="vy-report-field">
                            <span>{s.format}</span>
                            <select
                                value={format}
                                disabled={pending}
                                onChange={(event) => setFormat(event.target.value)}
                            >
                                {formats.map((option) => (
                                    <option key={option.key} value={option.key}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="vy-report-field">
                            <span>{s.delivery}</span>
                            <select
                                value={delivery}
                                disabled={pending}
                                onChange={(event) => setDelivery(event.target.value)}
                            >
                                <option value="">{s.downloadOnly}</option>
                                {(catalogue.deliveries ?? []).map((option) => (
                                    <option key={option.key} value={option.key}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {catalogue.deliveries?.find((item) => item.key === delivery)?.attaches && (
                            <p className="vy-report-notice">{s.attachment}</p>
                        )}
                        {error !== null && <ErrorState error={error} title={s.exportFailed} />}
                        <div className="vy-report-actions">
                            <Button type="button" variant="outline" disabled={pending} onClick={onClose}>
                                {s.cancel}
                            </Button>
                            <Button type="submit" disabled={pending || !format}>
                                <Download size={15} />
                                {pending ? s.exportStarting : s.exportStart}
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
export function ExportRun({ id }: { id: number }) {
    const query = useReportRun(id);
    const run = query.data;
    let download: string | undefined;
    if (run?.download_url) {
        try {
            const url = new URL(run.download_url, window.location.href);
            if (['https:', 'http:'].includes(url.protocol)) download = url.href;
        } catch {
            /* No usable download. */
        }
    }
    return (
        <section className="vy-report-panel vy-report-run">
            {query.isPending ? (
                <Skeleton className="h-40 w-full" />
            ) : query.isError ? (
                <ErrorState error={query.error} onRetry={() => void query.refetch()} />
            ) : (
                run && (
                    <>
                        <span className="vy-report-run-icon">
                            {run.state === 'done' ? <CheckCircle2 size={32} /> : <Clock3 size={32} />}
                        </span>
                        <h2>
                            {run.state === 'done'
                                ? s.runReady
                                : run.state === 'empty'
                                  ? s.runEmpty
                                  : run.state === 'failed'
                                    ? s.runFailed
                                    : run.state === 'expired'
                                      ? s.runExpired
                                      : s.runPending}
                        </h2>
                        <p>
                            {s.records(run.row_count)} · {run.format.toUpperCase()} · #{run.id}
                        </p>
                        {run.error && <p role="alert">{run.error}</p>}
                        {run.truncated && <p className="vy-report-notice">{s.truncated}</p>}
                        {run.state === 'done' && download && (
                            <a className="vy-report-primary-link" href={download} target="_blank" rel="noreferrer">
                                <Download size={16} />
                                {s.download}
                            </a>
                        )}
                        <p>
                            {s.expires}: {formatValue(run.expires_at, 'expires_at')}
                        </p>
                        <Button variant="outline" onClick={() => void query.refetch()} disabled={query.isFetching}>
                            <RefreshCw size={15} />
                            {s.refresh}
                        </Button>
                    </>
                )
            )}
        </section>
    );
}
