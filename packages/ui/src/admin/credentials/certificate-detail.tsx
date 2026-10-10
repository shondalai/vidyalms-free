import { certificateWorkspaceStrings as ws } from './workspace-strings';
import type { ReactNode } from 'react';
import { Badge, Button, Dialog, DialogContent, DialogTitle, Skeleton } from '@/shared/components';
import type { CertificateDetail } from '@vidyalms/api-sdk';
import { credentialStrings } from '../credential-strings';
import { useAdminConfig } from '../config';
import { formatDate, parseApiDate } from '../lib';
import { ScreenError } from '../components/states';
import { useCertificateDetail } from './api';

/*
 * One issuance record, with everything the row froze at issue.
 *
 * The point of the screen is `snapshot_present`. A certificate renders from
 * its own frozen template snapshot and never from the live template, so a
 * correction cannot rewrite a credential somebody already holds. A row
 * issued before the snapshot existed falls back to the live template, and
 * this is where an administrator sees which rows those are rather than
 * discovering it the day a template correction rewrites one.
 *
 * Nothing here discloses a storage path. The artefact is reported as present
 * or absent with its checksum; the bytes are reached only through the
 * learner's own signed, expiring URL.
 */

const strings = credentialStrings.detail;

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-fg-2">{label}</dt>
            <dd className="min-w-0 break-words text-fg">{children}</dd>
        </>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="border-t border-border pt-3">
            <h3 className="mb-2 font-display text-[11.5px] font-bold uppercase tracking-wide text-fg-2">
                {title}
            </h3>
            {children}
        </section>
    );
}

export interface CertificateDetailDialogProps {
    certificateId: number;
    code: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function CertificateDetailDialog({
    certificateId,
    code,
    open,
    onOpenChange,
}: CertificateDetailDialogProps) {
    const detail = useCertificateDetail(open ? certificateId : null);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <DialogTitle>{strings.title(code)}</DialogTitle>
                {detail.isPending && <Skeleton className="mt-3 h-40 w-full" />}
                {detail.isError && (
                    <div className="mt-3">
                        <ScreenError error={detail.error} onRetry={() => void detail.refetch()} />
                    </div>
                )}
                {detail.isSuccess && <CertificateDetailBody record={detail.data} />}
                <div className="mt-3.5 flex justify-end">
                    <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
                        {strings.close}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function CertificateDetailBody({
    record,
    expanded = false,
    onOpenRecord,
}: {
    record: CertificateDetail;
    expanded?: boolean;
    onOpenRecord?: (id: number) => void;
}) {
    const config = useAdminConfig();
    const date = (value: string | null | undefined, fallback: string = ws.notRecorded) => {
        if (!expanded) return formatDate(value, config.locale) ?? strings.never;
        const parsed = parseApiDate(value);
        return parsed
            ? new Intl.DateTimeFormat(config.locale, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
                  timeZone: 'UTC',
              }).format(parsed) + ' UTC'
            : fallback;
    };
    const dl = 'grid grid-cols-[minmax(0,140px)_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[12.5px]';

    return (
        <div
            className={expanded ? 'vy-cert-record-grid' : 'mt-3 grid max-h-[60vh] gap-3 overflow-y-auto pr-1'}
        >
            <Section title={strings.sections.provenance}>
                <dl className={dl}>
                    <Row label={strings.labels.uuid}>
                        <code className="font-mono text-[11px]">{record.uuid}</code>
                    </Row>
                    <Row label={strings.labels.template}>
                        {record.template_id === null ? strings.never : `#${record.template_id}`}
                    </Row>
                    <Row label={strings.labels.revision}>{record.template_revision ?? strings.never}</Row>
                    <Row label={strings.labels.snapshot}>
                        {record.snapshot_present ? (
                            strings.snapshotFrozen
                        ) : (
                            <span className="flex flex-wrap items-center gap-1.5">
                                <Badge variant="warn">{strings.labels.snapshot}</Badge>
                                <span>{strings.snapshotLive}</span>
                            </span>
                        )}
                    </Row>
                    <Row label={strings.labels.renderer}>
                        <code className="font-mono text-[11px]">{record.renderer_key}</code>
                    </Row>
                    <Row label={strings.labels.engine}>
                        {record.engine_version ?? (expanded ? ws.notRecorded : strings.never)}
                    </Row>
                </dl>
            </Section>

            <Section title={strings.sections.lifecycle}>
                <dl className={dl}>
                    <Row label={strings.labels.issued}>{date(record.issued_at)}</Row>
                    <Row label={strings.labels.completed}>{date(record.completed_at)}</Row>
                    <Row label={strings.labels.validFrom}>{date(record.valid_from, ws.notSpecified)}</Row>
                    <Row label={strings.labels.expires}>{date(record.expires_at, ws.noExpiry)}</Row>
                    <Row label={strings.labels.revoked}>{date(record.revoked_at, ws.notRevoked)}</Row>
                    {record.revocation_reason !== null && (
                        <Row label={strings.labels.reason}>{record.revocation_reason}</Row>
                    )}
                    <Row label={strings.labels.published}>{record.published ? strings.yes : strings.no}</Row>
                </dl>
            </Section>

            <Section title={strings.sections.artefact}>
                {record.artefact_available ? (
                    <dl className={dl}>
                        <Row label={strings.labels.checksum}>
                            <code className="font-mono text-[11px]">
                                {record.artefact_checksum ?? strings.never}
                            </code>
                        </Row>
                        <Row label={strings.labels.renderedAt}>{date(record.rendered_at)}</Row>
                    </dl>
                ) : (
                    <p className="text-[12.5px] text-fg-2">{strings.artefactMissing}</p>
                )}
            </Section>

            <Section title={strings.sections.credential}>
                {record.credential_format === null ? (
                    <p className="text-[12.5px] text-fg-2">{strings.credentialNone}</p>
                ) : (
                    <dl className={dl}>
                        <Row label={strings.labels.format}>
                            <code className="font-mono text-[11px]">{record.credential_format}</code>
                        </Row>
                        <Row label={strings.labels.proof}>{record.proof_type ?? strings.proofNone}</Row>
                        {record.issuer_earner_id !== undefined && record.issuer_earner_id !== null && (
                            <Row label={strings.labels.earnerId}>
                                <code className="font-mono text-[11px]">{record.issuer_earner_id}</code>
                            </Row>
                        )}
                    </dl>
                )}
            </Section>

            <Section title={strings.sections.credits}>
                {record.credits.length === 0 ? (
                    <p className="text-[12.5px] text-fg-2">{strings.creditsNone}</p>
                ) : (
                    <ul className="grid gap-1.5 text-[12.5px]">
                        {record.credits.map((credit) => (
                            <li key={`${credit.scheme}-${credit.unit}`} className="flex flex-wrap gap-2">
                                <span className="font-semibold text-fg">
                                    {credit.scheme_label ?? credit.scheme}
                                </span>
                                <span className="text-fg">
                                    {credit.amount} {credit.unit}
                                </span>
                                {credit.field_of_study !== undefined && credit.field_of_study !== null && (
                                    <span className="text-fg-2">{credit.field_of_study}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title={strings.sections.statusEntries}>
                {record.status_entries.length === 0 ? (
                    <p className="text-[12.5px] text-fg-2">{strings.statusEntriesNone}</p>
                ) : (
                    <ul className="grid gap-1.5 text-[12.5px]">
                        {record.status_entries.map((entry) => (
                            <li key={`${entry.purpose}-${entry.list_id}-${entry.list_index}`}>
                                <span className="font-semibold text-fg">{entry.purpose}</span>{' '}
                                <span className="text-fg-2">
                                    {strings.labels.list}{' '}
                                    <code className="font-mono text-[11px]">{entry.list_id}</code>,{' '}
                                    {strings.labels.index} {entry.list_index}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section title={strings.sections.chain}>
                {record.supersedes_id === null && record.superseded_by_id === null ? (
                    <p className="text-[12.5px] text-fg-2">{strings.chainNone}</p>
                ) : (
                    <dl className={dl}>
                        {record.supersedes_id !== null && (
                            <Row label={strings.labels.supersedes}>
                                {onOpenRecord ? (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onOpenRecord(record.supersedes_id!)}
                                    >
                                        {ws.linkedRecord(record.supersedes_id)}
                                    </Button>
                                ) : (
                                    `#${record.supersedes_id}`
                                )}
                            </Row>
                        )}
                        {record.superseded_by_id !== null && (
                            <Row label={strings.labels.supersededBy}>
                                {onOpenRecord ? (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onOpenRecord(record.superseded_by_id!)}
                                    >
                                        {ws.linkedRecord(record.superseded_by_id)}
                                    </Button>
                                ) : (
                                    `#${record.superseded_by_id}`
                                )}
                            </Row>
                        )}
                    </dl>
                )}
            </Section>
        </div>
    );
}
