import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { Organisation, OrganisationErasureReceipt, OrganisationMember } from '@vidyalms/api-sdk';
import { Badge, Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle, ErrorState } from '@/shared/components';
import { ProblemError } from '@/shared/api';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { useCertificateWriteAttempt as useWriteAttempt } from '../credentials/api';
import { organisationKeys } from './api';

export const relationshipErasureStrings = {
    action: uiText("vidyalms.ui.f9ac1e959985aaf0", "Remove relationship"), title: uiText("vidyalms.ui.39645be044573491", "Remove this organization relationship?"),
    intro: uiText("vidyalms.ui.ff9bc858bd6e81c0", "This closes all of this person’s memberships in this organization and removes its role, invitation proof and identity links. This action cannot be undone."),
    preserved: uiText("vidyalms.ui.5d110c2797476cf4", "The CMS account, personal learning and other organizations are preserved. Revoked grants and append-only ledgers retain recorded identities; this is not complete personal-data erasure."),
    acknowledgement: uiText("vidyalms.ui.e6ac1b95b465ba16", "I understand the relationship removal and the records that will remain."),
    cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"), close: uiText("vidyalms.ui.7d9eb7acb13e2462", "Close"), busy: uiText("vidyalms.ui.d4b09919ec929f15", "Removing…"), queued: uiText("vidyalms.ui.2cfeaa6c027b9cfd", "Access disabled; cleanup queued"), removed: uiText("vidyalms.ui.a4925fe36307f99c", "Organization relationship removed"),
    incomplete: uiText("vidyalms.ui.2f027a83b3d95707", "Complete personal-data erasure is not claimed."), cleanup: uiText("vidyalms.ui.4b6670e1fbb7aafc", "View access cleanup"),
    resume: uiText("vidyalms.ui.8ed11e8454458201", "Reload and review continuation"), revision: uiText("vidyalms.ui.b4c1404c215dba54", "Reload revision and review"),
    continuation: uiText("vidyalms.ui.a27f62022ac9d852", "After cleanup finishes, review a fresh organization revision and confirm again to finish removing the identity links. A running cleanup cannot be bypassed."),
    refreshed: uiText("vidyalms.ui.4331d68e9400401b", "The latest organization revision is loaded. Review and acknowledge before continuing."),
    receipts: uiText("vidyalms.ui.8bacf845cb6269eb", "Record receipt"), erased: uiText("vidyalms.ui.4118fb4fed0ecec9", "Removed"), pseudonymised: uiText("vidyalms.ui.6fbf91238440517d", "Identity links removed"), retained: uiText("vidyalms.ui.2a64df82cdaad551", "Retained"),
    field: uiText("vidyalms.ui.4eae684dddd33fad", "Record class"), count: uiText("vidyalms.ui.47a84e92d5b225ee", "Records"), warnings: uiText("vidyalms.ui.faec1fcdb37ad36a", "Retention notes"),
    job: (id: number) => uiText("vidyalms.ui.1123d5c9a8a50f51", "Cleanup job #{p0}", { p0: id }), member: (id: number) => uiText("vidyalms.ui.18c16c9936a808da", "Account #{p0}", { p0: id }),
};
const strings = relationshipErasureStrings;

export function RelationshipErasure({ organisation, member, close, openCleanup }: {
    organisation: Organisation; member: OrganisationMember; close: () => void; openCleanup: () => void;
}) {
    const client = useApiClient(); const cache = useQueryClient(); const write = useWriteAttempt();
    const [revision, setRevision] = useState(organisation.revision); const [acknowledged, setAcknowledged] = useState(false);
    const [receipt, setReceipt] = useState<OrganisationErasureReceipt | null>(null); const [reviewing, setReviewing] = useState(true);
    const erase = useMutation({ mutationFn: async (input: { revision: number; key: string }) => must((await client.POST('/manage/organisations/{id}/members/{member_id}/erase', {
        params: { path: { id: organisation.id, member_id: member.id }, header: { 'Idempotency-Key': input.key } },
        body: { expected_revision: input.revision, acknowledgement: 'remove_organization_relationship' },
    })).data), onSuccess: () => cache.invalidateQueries({ queryKey: organisationKeys.root }) });
    const reload = useMutation({ mutationFn: async () => must((await client.GET('/manage/organisations/{id}', { params: { path: { id: organisation.id } } })).data),
        onSuccess: (org) => { setRevision(org.revision); setAcknowledged(false); setReviewing(true); erase.reset(); } });
    const busy = erase.isPending || reload.isPending;
    const submit = async () => {
        if (!acknowledged || !reviewing) return;
        const attempt = write.capture({ memberId: member.id, revision }, () => ({ revision }));
        try {
            const result = await erase.mutateAsync({ ...attempt.input, key: attempt.idempotencyKey });
            write.complete(attempt); setReceipt(result); setReviewing(false); setAcknowledged(false);
        } catch { /* An unchanged retry keeps its exact revision and request key. */ }
    };
    const conflict = erase.error instanceof ProblemError && erase.error.status === 409;
    return <Dialog open onOpenChange={(open) => { if (!open && !busy) close(); }}><DialogContent className="grid max-w-lg gap-3">
        <DialogTitle>{receipt?.state === 'relationship_erased' ? strings.removed : strings.title}</DialogTitle>
        <DialogDescription>{member.user_name ?? strings.member(member.user_id)}. {strings.intro}</DialogDescription>
        <p className="rounded border border-border bg-warn-soft p-3 text-sm text-fg">{strings.preserved}</p>
        {receipt && <section aria-label={strings.receipts} className="grid gap-3">
            <Badge variant={receipt.state === 'cleanup_queued' ? 'warn' : 'neutral'}>{receipt.state === 'cleanup_queued' ? strings.queued : strings.removed}</Badge>
            <p className="text-sm font-semibold text-fg">{strings.incomplete}</p>
            {receipt.cleanup && <p className="text-sm text-fg-2">{strings.job(receipt.cleanup.job_id)}</p>}
            {receipt.state === 'cleanup_queued' && <p className="text-sm text-fg-2">{strings.continuation}</p>}
            {receipt.receipts && Object.entries(receipt.receipts).map(([provider, report]) => <section key={provider} className="grid gap-2 rounded border border-border p-3">
                <h3 className="font-semibold text-fg">{provider}</h3>
                {(['erased', 'pseudonymised', 'retained'] as const).map((kind) => <div key={kind} className="grid gap-1">
                    <h4 className="text-xs font-semibold text-fg">{strings[kind]}</h4>
                    <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 text-xs text-fg-2">
                        {Object.entries(report[kind]).map(([key, count]) => <div key={key} className="contents"><dt className="break-words">{key.replaceAll('_', ' ')}</dt><dd>{count.toLocaleString()}</dd></div>)}
                    </dl>
                </div>)}
                {report.warnings.length > 0 && <ul aria-label={strings.warnings} className="grid list-inside list-disc gap-1 text-xs text-fg-2">{report.warnings.map((warning) => <li key={warning}>{warning}</li>)}</ul>}
            </section>)}
        </section>}
        {reviewing && <label className="flex items-start gap-2 text-sm text-fg"><input type="checkbox" className="mt-1 accent-accent" disabled={busy}
            checked={acknowledged} onChange={(event) => setAcknowledged(event.target.checked)} />{strings.acknowledgement}</label>}
        {reload.isSuccess && reviewing && <p role="status" className="text-xs text-fg-2">{strings.refreshed}</p>}
        {(erase.isError || reload.isError) && <ErrorState error={erase.error ?? reload.error} />}
        <DialogFooter>
            <Button variant="ghost" disabled={busy} onClick={close}>{receipt ? strings.close : strings.cancel}</Button>
            {receipt?.state === 'cleanup_queued' && <Button variant="outline" disabled={busy} onClick={openCleanup}>{strings.cleanup}</Button>}
            {(conflict || (receipt?.state === 'cleanup_queued' && !reviewing)) && <Button variant="outline" disabled={busy} onClick={() => reload.mutate()}>{receipt ? strings.resume : strings.revision}</Button>}
            {reviewing && <Button variant="destructive" disabled={busy || !acknowledged} onClick={() => { void submit(); }}>{busy ? strings.busy : strings.action}</Button>}
        </DialogFooter>
    </DialogContent></Dialog>;
}
