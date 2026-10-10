import { translate as uiText } from '@/shared/i18n';
import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useApiClient, queryKeys as keys } from '@/shared/api';
import { useMountConfig } from '@/shared/context';
import { Button } from '@/shared/components';
import { AttachmentGallery } from '@/shared/components/attachment-gallery';
import { checkUpload, uploadFile, type UploadedFile } from '@/shared/upload';
import { practicalStrings as s } from '@/shared/practical-strings';

export function AssignmentWork({ lessonId }: { lessonId: number }) {
    const client = useApiClient();
    const config = useMountConfig();
    const cache = useQueryClient();
    const queryKey = ['vidyalms', 'assignment', lessonId];
    const mediaUrls = useRef(new Map<string, { url: string; refreshAt: number }>());
    const query = useQuery({
        queryKey,
        queryFn: async () => {
            const data = (await client.GET('/assignments/{id}/submissions', { params: { path: { id: lessonId } } })).data!;
            // Poll grading status without re-downloading every photo whenever a fresh signature arrives.
            for (const attempt of data.submissions) for (const [index, file] of attempt.files.entries()) {
                const key = `${attempt.id}:${index}`;
                const existing = mediaUrls.current.get(key);
                if (existing && existing.refreshAt > Date.now()) file.url = existing.url;
                else mediaUrls.current.set(key, { url: file.url, refreshAt: Date.now() + 30 * 60 * 1000 });
            }
            return data;
        },
        refetchInterval: current => current.state.data?.submissions[0]?.status === 'submitted' ? 15000 : false,
    });
    const [files, setFiles] = useState<File[]>([]);
    const [notes, setNotes] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState('');
    const uploaded = useRef(new Map<File, UploadedFile>());
    const requestKey = useRef<string | null>(null);
    const abort = useRef<AbortController | null>(null);
    useEffect(() => () => abort.current?.abort(), []);
    const data = query.data;
    const decision = data?.submissions[0]?.assessment.decision;
    useEffect(() => {
        if (decision === 'approved') {
            void cache.invalidateQueries({ queryKey: keys.curriculum(config.course.id) });
            void cache.invalidateQueries({ queryKey: keys.myProgress(config.course.id) });
            void cache.invalidateQueries({ queryKey: keys.lesson(lessonId) });
        }
    }, [decision, cache, config.course.id, lessonId]);
    if (query.isPending) return <p role="status">{s.title}…</p>;
    if (!data) return <div role="alert"><p>{s.loadFailed}</p><Button onClick={() => void query.refetch()}>{s.retry}</Button></div>;
    const assignment = data.assignment;
    const latest = data.submissions[0];
    const policy = { purpose: 'assignment', maxBytes: (assignment.max_file_size_kb ?? 65536) * 1024, extensions: assignment.allowed_file_types };
    const changeFiles = (selected: FileList | null) => {
        const next = [...files, ...Array.from(selected ?? [])];
        const invalid = next.map(file => checkUpload(file, policy)).find(Boolean);
        if (next.length > assignment.max_files) { setError(s.tooMany); return; }
        if (invalid) { setError(invalid); return; }
        setError(''); setFiles(next); requestKey.current = null;
    };
    const submit = async () => {
        if (!notes.trim() && files.length === 0) { setError(s.empty); return; }
        if (files.length > 0 && !config.urls.upload) { setError(s.unavailable); return; }
        setBusy(true); setError('');
        abort.current = new AbortController();
        try {
            const entries = [];
            for (const [index, file] of files.entries()) {
                setStatus(`${s.uploading} ${index + 1}/${files.length}`);
                let stored = uploaded.current.get(file);
                if (!stored) {
                    stored = await uploadFile({ endpoint: config.urls.upload!, file, purpose: 'assignment', headers: config.api.headers, signal: abort.current.signal });
                    uploaded.current.set(file, stored);
                }
                entries.push({ path: stored.path, filename: stored.name, size_bytes: stored.size_bytes, mime: stored.mime });
            }
            setStatus(s.submitting);
            requestKey.current ??= crypto.randomUUID();
            await client.POST('/assignments/{id}/submissions', { params: { path: { id: lessonId } }, headers: { 'Idempotency-Key': requestKey.current }, body: { text_response: notes.trim() || null, files: entries } });
            setFiles([]); setNotes(''); uploaded.current.clear(); requestKey.current = null;
            await cache.invalidateQueries({ queryKey });
            setStatus(s.awaiting);
        } catch (reason) { setError(reason instanceof Error ? reason.message : s.loadFailed); }
        finally { setBusy(false); }
    };
    return <section className="grid gap-5" aria-label={s.title}>
        <dl className="grid grid-cols-2 gap-3 rounded-lg bg-surface-2 p-4 text-xs sm:grid-cols-3">
            <div><dt className="text-fg-2">{s.allowedFiles}</dt><dd className="font-semibold">{assignment.max_files}</dd></div>
            <div><dt className="text-fg-2">{s.allowedTypes}</dt><dd className="font-semibold">{assignment.allowed_file_types.join(', ') || s.allFormats}</dd></div>
            <div><dt className="text-fg-2">{s.sizeLimit}</dt><dd className="font-semibold">{Math.round(policy.maxBytes / 1024 / 1024 * 10) / 10} {uiText("vidyalms.ui.f1af7a60c4d708f3", " MB")}</dd></div>
            {assignment.pass_percentage != null && <div><dt>{s.pass}</dt><dd>{assignment.pass_percentage}%</dd></div>}
            {assignment.due_at && <div><dt>{s.due}</dt><dd>{new Date(assignment.due_at.replace(' ', 'T') + (assignment.due_at.includes('Z') ? '' : 'Z')).toLocaleString(config.locale)}</dd></div>}
        </dl>
        {latest && <p className="rounded-lg border border-border p-4 text-sm">{decision === 'approved' ? s.approvedNote : decision === 'resubmit' || (decision === 'failed' && data.can_submit) ? s.resubmitNote : decision === 'failed' ? s.failedNote : s.waitingNote}</p>}
        {data.can_submit ? <form className="grid gap-4" onSubmit={event => { event.preventDefault(); void submit(); }}>
            <label className="grid gap-2 text-sm font-semibold">{s.response}<textarea disabled={busy} className="rounded-lg border border-border bg-surface p-3 font-normal" rows={4} value={notes} onChange={event => { setNotes(event.target.value); requestKey.current = null; }} /></label>
            <label className="grid gap-3 rounded-lg border border-dashed border-border p-5 text-sm font-semibold">{s.addFiles}<input type="file" multiple disabled={busy} accept={assignment.allowed_file_types.map(ext => `.${ext}`).join(',') || undefined} onChange={event => { changeFiles(event.target.files); event.target.value = ''; }} /></label>
            <ul className="grid gap-2">{files.map((file, index) => <li key={`${file.name}-${index}`} className="flex items-center justify-between gap-3 text-sm"><span className="break-all">{file.name}</span><Button variant="ghost" disabled={busy} onClick={() => { setFiles(files.filter((_, i) => i !== index)); requestKey.current = null; }}>{s.remove}</Button></li>)}</ul>
            <div><Button type="submit" disabled={busy}>{busy ? s.submitting : s.submit}</Button></div>
        </form> : decision !== 'approved' && <p className="text-sm text-fg-2">{data.overdue ? s.overdue : s.disabled}</p>}
        {error && <p role="alert" className="text-sm text-danger">{error}</p>}
        <p role="status" className="text-xs text-fg-2">{status}</p>
        {data.submissions.length > 0 && <div className="grid gap-3"><h5 className="text-lg font-semibold">{s.history}</h5>{data.submissions.map((attempt, index) => <details key={attempt.id} open={index === 0} className="rounded-lg border border-border p-4">
            <summary className="cursor-pointer text-sm font-semibold">{s.attempt} {attempt.attempt_number} · {attempt.assessment.decision ? s[attempt.assessment.decision] : s.awaiting}</summary>
            <div className="mt-4 grid gap-3">
                <p className="text-xs text-fg-2">{s.sent}: {attempt.submitted_at} {attempt.graded_at && <> · {s.assessed}: {attempt.graded_at}</>}</p>
                {attempt.text_response && <p className="whitespace-pre-wrap text-sm">{attempt.text_response}</p>}
                <AttachmentGallery files={attempt.files} />
                {attempt.points_awarded !== null && <p className="font-semibold">{s.total}: {attempt.points_awarded} / {attempt.assessment.max_points ?? assignment.max_points}</p>}
                {attempt.feedback && <p className="whitespace-pre-wrap rounded-lg bg-surface-2 p-4 text-sm">{attempt.feedback}</p>}
                {attempt.assessment.criteria?.map(criterion => <div key={criterion.id} className="border-t border-border py-2 text-sm"><strong>{criterion.label}</strong> · {criterion.points}/{criterion.max_points}<p className="whitespace-pre-wrap text-fg-2">{criterion.feedback}</p></div>)}
            </div>
        </details>)}</div>}
    </section>;
}
