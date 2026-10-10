import { useNavigationGuard } from '@/shared/navigation-guard';
import { useEffect, useState } from 'react';
import { Award, Check } from 'lucide-react';
import type { CourseCertificatePolicy, CourseCertificatePolicyRequest } from '@vidyalms/api-sdk';
import { Badge, Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle, ErrorState, Icon, Pagination } from '@/shared/components';
import { useDebounced } from '@/admin/hooks';
import { useCertificateWriteAttempt } from '@/admin/credentials/api';
import { useCourseCertificateChoices, useCourseCertificatePolicy, useSaveCourseCertificatePolicy } from '@/admin/credentials/studio-api';
import { Field, Select, TextInput } from '../components/field';
import { useInstructorConfig } from '../config';
import { courseCertificateStrings as strings } from './course-certificate-strings';

type Draft = CourseCertificatePolicyRequest;
function draftFrom(policy: CourseCertificatePolicy): Draft {
    return { expected_revision: policy.revision, enabled: policy.enabled, template_id: policy.template_id,
        issue_rule: policy.issue_rule, expiry: { ...policy.expiry }, co_branding: policy.co_branding };
}

export function CourseCertificatePolicyPanel({ courseId, onDirtyChange }: {
    courseId: number; onDirtyChange?: (dirty: boolean) => void;
}) {
    const query = useCourseCertificatePolicy(courseId);
    if (query.data === undefined) return query.isError ? <ErrorState error={query.error} onRetry={() => { void query.refetch(); }} />
        : <p role="status" className="p-4 text-sm text-fg-2">{strings.loading}</p>;
    return <PolicyForm key={courseId} courseId={courseId} policy={query.data} onDirtyChange={onDirtyChange}
        readError={query.isError ? query.error : null} reload={async () => {
            const result = await query.refetch();
            if (result.isError || result.data === undefined) throw result.error;
            return result.data;
        }} />;
}

function PolicyForm({ courseId, policy, reload, readError, onDirtyChange }: {
    courseId: number; policy: CourseCertificatePolicy; reload: () => Promise<CourseCertificatePolicy>;
    readError: unknown; onDirtyChange?: (dirty: boolean) => void;
}) {
    const config = useInstructorConfig();
    const mutation = useSaveCourseCertificatePolicy();
    const attempt = useCertificateWriteAttempt();
    const [baseline, setBaseline] = useState(policy);
    const [draft, setDraft] = useState<Draft>(() => draftFrom(policy));
    const [selectedName, setSelectedName] = useState(policy.resolved_template.name);
    const [dirty, setDirty] = useState(false);
    useNavigationGuard(dirty);
    const [notice, setNotice] = useState('');
    const [reloading, setReloading] = useState(false);
    const [reloadError, setReloadError] = useState<unknown>(null);
    const [askingReload, setAskingReload] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [generation, setGeneration] = useState(0);
    const debouncedSearch = useDebounced(search);
    const choices = useCourseCertificateChoices(courseId, debouncedSearch, page);
    const pending = mutation.isPending || reloading;
    const changed = (changes: Partial<Draft>) => { setDraft((old) => ({ ...old, ...changes })); setDirty(true); setNotice(''); };
    useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);
    useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);
    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);
    const save = async () => {
        const captured = attempt.capture({ courseId, draft, generation }, () => ({ courseId, body: structuredClone(draft) }));
        try {
            const result = await mutation.mutateAsync({ ...captured.input, idempotencyKey: captured.idempotencyKey });
            attempt.complete(captured);
            setBaseline(result); setDraft(draftFrom(result)); setDirty(false); setNotice(strings.saved);
        } catch { /* Keep the original revision and idempotency key for an unchanged retry. */ }
    };
    const reloadSaved = async () => {
        setReloading(true); setReloadError(null);
        try {
            const result = await reload();
            setBaseline(result); setDraft(draftFrom(result)); setSelectedName(result.resolved_template.name);
            setDirty(false); setNotice(''); setAskingReload(false); setGeneration((old) => old + 1);
        } catch (error) { setReloadError(error); }
        finally { setReloading(false); }
    };
    const expiryMode = draft.expiry.mode;
    return <section className="grid gap-4 rounded-lg border border-border bg-surface p-5">
        <div className="flex flex-wrap items-start gap-3">
            <span className="rounded-lg bg-accent-soft p-2.5 text-accent"><Icon icon={Award} size={20} /></span>
            <div className="min-w-0 flex-1"><h3 className="font-display text-base font-semibold text-fg">{strings.title}</h3>
                <p className="mt-1 text-sm text-fg-2">{strings.intro}</p></div>
            {dirty && <Badge variant="warn">{strings.dirty}</Badge>}
            {config.urls.certificateTemplates && <a className="text-sm font-semibold text-accent underline underline-offset-4"
                href={`${config.urls.certificateTemplates}#/templates`} target="_blank" rel="noopener noreferrer">{strings.library}</a>}
        </div>
        {readError !== null && <ErrorState error={readError} />}
        <form className="grid gap-4" onSubmit={(event) => { event.preventDefault(); void save(); }}>
            <fieldset disabled={pending} className="grid min-w-0 gap-4">
                <label className="flex items-center gap-2 text-sm font-medium text-fg"><input type="checkbox" checked={draft.enabled}
                    onChange={(event) => changed({ enabled: event.target.checked })} />{strings.enabled}</label>
                <p className="text-xs text-fg-2">{strings.disabledHint}</p>
                <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))' }}>
                    <Field label={strings.template}>{({ id }) => <Select id={id} value={draft.template_id === null ? 'default' : 'specific'}
                        onChange={(event) => {
                            if (event.target.value === 'default') { changed({ template_id: null }); setSelectedName(null); }
                            else { const first = choices.data?.items.find((item) => item.available);
                                if (first) { changed({ template_id: first.id }); setSelectedName(first.name); } }
                        }}>
                        <option value="default">{strings.siteDefault}</option>
                        <option value="specific" disabled={!choices.data?.items.some((item) => item.available) && draft.template_id === null}>{strings.choose}</option>
                    </Select>}</Field>
                    <Field label={strings.expiry}>{({ id }) => <Select id={id} value={expiryMode}
                        onChange={(event) => changed({ expiry: { mode: event.target.value, days: null, at: null } })}>
                        {baseline.capabilities.expiry_modes.map((mode) => <option key={mode} value={mode}>{strings.expiryLabels[mode] ?? mode}</option>)}
                    </Select>}</Field>
                </div>
                {['days_after_issue', 'anniversary'].includes(expiryMode) && <Field label={expiryMode === 'anniversary' ? strings.years : strings.days}>
                    {({ id }) => <TextInput id={id} type="number" min="1" step="1" required className="max-w-xs"
                        value={draft.expiry.days === null || draft.expiry.days === undefined ? '' : expiryMode === 'anniversary' ? Math.floor(draft.expiry.days / 365) : draft.expiry.days}
                        onChange={(event) => changed({ expiry: { ...draft.expiry,
                            days: event.target.value === '' ? null : Number(event.target.value) * (expiryMode === 'anniversary' ? 365 : 1) } })} />}
                </Field>}
                {expiryMode === 'fixed' && <Field label={strings.at}>{({ id }) => <TextInput id={id} type="datetime-local" required className="max-w-xs"
                    value={(draft.expiry.at ?? '').replace(' ', 'T').slice(0, 16)} onChange={(event) => changed({ expiry: {
                        ...draft.expiry, at: event.target.value === '' ? null : `${event.target.value.replace('T', ' ')}:00`,
                    } })} />}</Field>}
                <div className="rounded border border-border bg-surface-2 p-3 text-sm">
                    <p className="text-xs font-medium text-fg-2">{draft.template_id === null ? strings.resolved : strings.selected}</p>
                    <p className="mt-1 font-semibold text-fg">{draft.template_id === null
                        ? baseline.template_id === null ? baseline.resolved_template.name ?? strings.missing : strings.siteDefault
                        : selectedName ?? strings.stored(draft.template_id)}</p>
                    {!dirty && draft.enabled && !baseline.resolved_template.available && <p className="mt-2 text-danger">{baseline.resolved_template.diagnostic ?? strings.missing}</p>}
                </div>
                <Field label={strings.search}>{({ id }) => <TextInput id={id} type="search" maxLength={100} value={search}
                    onChange={(event) => { setSearch(event.target.value); setPage(1); }} />}</Field>
                {choices.isError && <ErrorState error={choices.error} onRetry={() => { void choices.refetch(); }} />}
                {choices.isPending && <p role="status" className="text-sm text-fg-2">{strings.loading}</p>}
                {choices.data && <div className="grid gap-2" aria-busy={choices.isFetching}>
                    {choices.data.items.length === 0 && <p className="text-sm text-fg-2">{strings.empty}</p>}
                    {choices.data.items.map((item) => <button key={item.id} type="button" disabled={!item.available || search !== debouncedSearch || choices.isFetching}
                        aria-pressed={draft.template_id === item.id} onClick={() => { changed({ template_id: item.id }); setSelectedName(item.name); }}
                        className={`flex items-center gap-3 rounded border p-3 text-left transition-colors disabled:opacity-50 ${draft.template_id === item.id ? 'border-accent bg-accent-soft' : 'border-border hover:bg-surface-2'}`}>
                        <Icon icon={draft.template_id === item.id ? Check : Award} size={18} />
                        <span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-fg">{item.name}</span>
                            <span className="text-xs text-fg-2">{strings.revision(item.published_revision)}</span></span>
                        {item.is_default && <Badge variant="neutral">{strings.defaultBadge}</Badge>}
                        {!item.available && <Badge variant="warn">{strings.unavailable}</Badge>}
                    </button>)}
                    <Pagination page={page} perPage={choices.data.per_page} total={choices.data.total} onPageChange={setPage} label={strings.pages} />
                </div>}
            </fieldset>
            {mutation.isError && <ErrorState error={mutation.error} />}
            {notice && <p role="status" className="text-sm text-success">{notice}</p>}
            <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                <Button type="button" variant="ghost" disabled={pending} onClick={() => { if (dirty) setAskingReload(true); else void reloadSaved(); }}>{strings.reload}</Button>
                <Button type="submit" disabled={pending || !dirty}>{pending ? strings.saving : strings.save}</Button>
            </div>
        </form>
        <Dialog open={askingReload} onOpenChange={(open) => { if (!reloading) setAskingReload(open); }}><DialogContent>
            <DialogTitle>{strings.reloadTitle}</DialogTitle><DialogDescription>{strings.reloadBody}</DialogDescription>
            {reloadError !== null && <ErrorState error={reloadError} />}
            <DialogFooter><Button variant="ghost" disabled={reloading} onClick={() => setAskingReload(false)}>{strings.cancel}</Button>
                <Button variant="destructive" disabled={reloading} onClick={() => { void reloadSaved(); }}>{strings.confirmReload}</Button></DialogFooter>
        </DialogContent></Dialog>
        {!askingReload && reloadError !== null && <ErrorState error={reloadError} />}
    </section>;
}
