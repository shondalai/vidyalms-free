import { useState } from 'react';
import { ArrowLeft, Award, ExternalLink } from 'lucide-react';
import { Badge, Button, Skeleton } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { PageHeader } from '../components/page-header';
import { AdminNotice, ScreenError } from '../components/states';
import { adminStrings } from '../strings';
import { formatDate } from '../lib';
import { useAdminConfig } from '../config';
import { useCertificateDetail } from './api';
import { CertificateDetailBody } from './certificate-detail';
import { CertificateCeremonies, CertificateRowActions, type Ceremony } from './certificates-tab';
import { certificateWorkspaceStrings as s } from './workspace-strings';

export function CertificateRecordPage({
    certificateId,
    onBack,
    navigate,
}: {
    certificateId: number;
    onBack: () => void;
    navigate: (hash: string) => void;
}) {
    const detail = useCertificateDetail(certificateId);
    const config = useAdminConfig();
    const announce = useAnnounce();
    const [ceremony, setCeremony] = useState<Ceremony | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const record = detail.data;
    return (
        <div className="vy-cert-record">
            <Button variant="ghost" className="justify-self-start" onClick={onBack}>
                <ArrowLeft size={16} aria-hidden="true" />
                {s.backRecords}
            </Button>
            {detail.isPending ? (
                <Skeleton className="h-64 w-full" />
            ) : detail.isError ? (
                <ScreenError error={detail.error} onRetry={() => void detail.refetch()} />
            ) : (
                record && (
                    <>
                        <PageHeader
                            title={record.code}
                            eyebrow={s.record}
                            description={record.course.title}
                            meta={
                                <Badge
                                    variant={
                                        record.status === 'active'
                                            ? 'ok'
                                            : record.status === 'revoked'
                                              ? 'danger'
                                              : 'neutral'
                                    }
                                >
                                    {adminStrings.certificates.statuses[record.status] ?? record.status}
                                </Badge>
                            }
                            actions={
                                record.verification_url && (
                                    <a
                                        className="vy-cert-outline-link"
                                        href={record.verification_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink size={15} aria-hidden="true" />
                                        {s.verify}
                                    </a>
                                )
                            }
                        />
                        {message && <AdminNotice glyph="✓" title={message} role="status" />}
                        <section className="vy-cert-recipient">
                            <span className="vy-cert-recipient-icon">
                                <Award size={30} aria-hidden="true" />
                            </span>
                            <div>
                                <span className="vy-cert-eyebrow">{s.frozen}</span>
                                <h2>{record.learner.name ?? adminStrings.enrolments.unknownLearner}</h2>
                                <p>{s.frozenHint}</p>
                            </div>
                            <div className="vy-cert-issued">
                                <span>{adminStrings.certificates.columns.issued}</span>
                                <strong>{formatDate(record.issued_at, config.locale)}</strong>
                            </div>
                        </section>
                        <div className="vy-cert-record-actions">
                            <CertificateRowActions row={record} showRecord={false} onAct={setCeremony} />
                        </div>
                        <CertificateDetailBody
                            record={record}
                            expanded
                            onOpenRecord={(id) => navigate(`/certificate/${id}`)}
                        />
                        <CertificateCeremonies
                            acting={ceremony ? { row: record, ceremony } : null}
                            onClose={(open) => {
                                if (!open) setCeremony(null);
                            }}
                            onDone={(text) => {
                                setMessage(text);
                                announce(text);
                            }}
                        />
                    </>
                )
            )}
        </div>
    );
}
