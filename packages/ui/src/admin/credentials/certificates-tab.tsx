import { Download } from 'lucide-react';
import { csvContent, downloadFile } from '../learners/workspace';
import { certificateWorkspaceStrings as ws } from './workspace-strings';
import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, type DataTableColumn } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useAdminCertificates, type CertificateListParams, type CertificateRecord } from '../api';
import { screenUrl, useAdminConfig } from '../config';
import { useDebounced, useListState } from '../hooks';
import { formatDate } from '../lib';
import { adminStrings } from '../strings';
import { credentialStrings } from '../credential-strings';
import { FilterChips } from '../components/filter-chips';
import { ListFrame } from '../components/list-frame';
import { RevokeDialog } from '../components/revoke-dialog';
import { AdminNotice, ScreenError } from '../components/states';
import { CertificateDetailDialog } from './certificate-detail';
import { ReinstateDialog, ReissueDialog, SuspendDialog } from './lifecycle-dialogs';

/*
 * Artboard 2d, upper half, with the ceremony the design's own rules demand.
 *
 * The status enumeration is single valued under a published precedence
 * (revoked, then superseded, then suspended, then not yet valid, then
 * expired, then active), so which actions a row offers follows from the
 * status alone. Revocation is permanent, so a revoked row offers nothing but
 * its record; supersession is history, so a superseded row does the same.
 * Suspension is the reversible hold that a boolean revoked flag cannot
 * express, and it sits beside Revoke rather than inside it.
 *
 * CertificatePage carries no facet counts, so the status chips carry none
 * either. A chip that invented its number would be worse than a chip
 * without one.
 */

const PER_PAGE = 20;
const strings = adminStrings.certificates;
const credentials = credentialStrings;

type CertificateFilter = NonNullable<CertificateListParams['status']> | 'all';

const CHIPS: { value: CertificateFilter; label: string }[] = [
    { value: 'all', label: adminStrings.list.all },
    { value: 'valid', label: credentials.certificates.chips.valid },
    { value: 'not_yet_valid', label: credentials.certificates.chips.not_yet_valid },
    { value: 'suspended', label: credentials.certificates.chips.suspended },
    { value: 'expired', label: credentials.certificates.chips.expired },
    { value: 'revoked', label: credentials.certificates.chips.revoked },
    { value: 'superseded', label: credentials.certificates.chips.superseded },
];

/** Which lifecycle dialog one row action opens. */
export type Ceremony = 'revoke' | 'suspend' | 'reinstate' | 'reissue' | 'detail';

function statusVariant(status: string): 'danger' | 'warn' | 'neutral' {
    if (status === 'revoked') {
        return 'danger';
    }
    if (status === 'suspended' || status === 'expired') {
        return 'warn';
    }
    return 'neutral';
}

export function CertificatesTab({ onOpenRecord }: { onOpenRecord?: (id: number) => void } = {}) {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const list = useListState(
        'all',
        { key: 'issued_at', order: 'desc' },
        {
            persistence: 'hash-query',
            allowedFilters: CHIPS.map((chip) => chip.value),
            allowedSortKeys: ['issued_at'],
        },
    );
    const search = useDebounced(list.search).trim();
    const [message, setMessage] = useState<string | null>(null);
    const [acting, setActing] = useState<{ row: CertificateRecord; ceremony: Ceremony } | null>(null);

    const params: CertificateListParams = {
        status: list.filter === 'all' ? undefined : (list.filter as CertificateListParams['status']),
        q: search === '' ? undefined : search,
        /* Every sortable column below is declared with a contract sort value. */
        sort: list.sort.key as CertificateListParams['sort'],
        order: list.sort.order,
        page: list.page,
        per_page: PER_PAGE,
    };
    const certificates = useAdminCertificates(params);
    const items = certificates.data?.items ?? [];
    const total = certificates.data?.total ?? 0;

    const done = (text: string) => {
        setMessage(text);
        announce(text);
    };

    const columns: DataTableColumn<CertificateRecord>[] = [
        {
            key: 'code',
            header: strings.columns.code,
            width: '170px',
            cell: (row) => (
                <span className="vy-cert-code-cell">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="vy-cert-record-link"
                        onClick={() =>
                            onOpenRecord ? onOpenRecord(row.id) : setActing({ row, ceremony: 'detail' })
                        }
                    >
                        {row.code}
                    </Button>
                    <Badge variant={row.status === 'active' ? 'ok' : statusVariant(row.status)}>
                        {strings.statuses[row.status] ?? row.status}
                    </Badge>
                </span>
            ),
        },
        {
            key: 'learner',
            header: strings.columns.learner,
            weight: 1,
            cell: (row) => row.learner.name ?? adminStrings.enrolments.unknownLearner,
        },
        {
            key: 'course',
            header: strings.columns.course,
            weight: 1,
            cell: (row) => <span className="text-fg-2">{row.course.title}</span>,
        },
        {
            key: 'issued',
            header: strings.columns.issued,
            sortKey: 'issued_at',
            width: '110px',
            cell: (row) => <span className="text-fg-2">{formatDate(row.issued_at, config.locale)}</span>,
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '110px',
            align: 'end',
            cell: (row) =>
                onOpenRecord === undefined ? (
                    <CertificateRowActions row={row} onAct={(ceremony) => setActing({ row, ceremony })} />
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={credentials.certificates.detailsNamed(row.code)}
                        onClick={() =>
                            onOpenRecord ? onOpenRecord(row.id) : setActing({ row, ceremony: 'detail' })
                        }
                    >
                        {credentials.certificates.details}
                    </Button>
                ),
        },
    ];

    const closeCeremony = (next: boolean) => {
        if (!next) {
            setActing(null);
        }
    };

    return (
        <>
            <ListFrame
                title={ws.issued}
                action={
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!items.length || certificates.isFetching || certificates.isError}
                        onClick={() =>
                            downloadFile(
                                csvContent([
                                    ['Certificate ID', 'Learner', 'Course', 'Issued', 'Status'],
                                    ...items.map((row) => [
                                        row.code,
                                        row.learner.name,
                                        row.course.title,
                                        row.issued_at,
                                        row.status,
                                    ]),
                                ]),
                                `certificates-page-${list.page}.csv`,
                            )
                        }
                    >
                        <Download size={15} aria-hidden="true" />
                        {ws.exportPage}
                    </Button>
                }
                meta={certificates.isSuccess ? strings.issued(total) : undefined}
                search={{
                    label: adminStrings.list.search,
                    placeholder: strings.searchPlaceholder,
                    value: list.search,
                    onChange: list.setSearch,
                }}
                chips={
                    <FilterChips
                        label={adminStrings.list.filters}
                        value={list.filter}
                        onChange={list.setFilter}
                        chips={CHIPS}
                    />
                }
                banner={
                    message === null ? undefined : (
                        <AdminNotice
                            glyph="✓"
                            title={message}
                            action={
                                <Button variant="ghost" size="sm" onClick={() => setMessage(null)}>
                                    {adminStrings.courses.dismiss}
                                </Button>
                            }
                        />
                    )
                }
                page={list.page}
                perPage={PER_PAGE}
                total={total}
                shownCount={items.length}
                onPageChange={list.setPage}
            >
                {certificates.isError ? (
                    <div className="p-4">
                        <ScreenError
                            error={certificates.error}
                            onRetry={() => void certificates.refetch()}
                            coursesUrl={screenUrl(config, 'courses')}
                        />
                    </div>
                ) : (
                    <DataTable
                        caption={strings.table}
                        columns={columns}
                        rows={items}
                        rowKey={(row) => row.id}
                        sort={list.sort}
                        onSortChange={list.setSort}
                        loading={certificates.isPending}
                        empty={<EmptyState title={strings.empty} />}
                    />
                )}
            </ListFrame>
            <CertificateCeremonies acting={acting} onClose={closeCeremony} onDone={done} />
        </>
    );
}

export function CertificateRowActions({
    row,
    onAct,
    showRecord = true,
}: {
    row: CertificateRecord;
    onAct: (ceremony: Ceremony) => void;
    showRecord?: boolean;
}) {
    /* Revocation is permanent and supersession is history: neither offers a
       lifecycle action, because stepping back from one would rewrite an
       evidentiary record and reissuing from the other would fork the chain. */
    const closed = row.status === 'revoked' || row.status === 'superseded';

    return (
        <span className="flex flex-wrap justify-end gap-1.5">
            {showRecord && (
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={credentials.certificates.detailsNamed(row.code)}
                    onClick={() => onAct('detail')}
                >
                    {credentials.certificates.details}
                </Button>
            )}
            {!closed && row.status === 'suspended' && (
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={credentials.certificates.reinstateNamed(row.code)}
                    onClick={() => onAct('reinstate')}
                >
                    {credentials.certificates.reinstate}
                </Button>
            )}
            {!closed && row.status !== 'suspended' && (
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={credentials.certificates.suspendNamed(row.code)}
                    onClick={() => onAct('suspend')}
                >
                    {credentials.certificates.suspend}
                </Button>
            )}
            {!closed && (
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={credentials.certificates.reissueNamed(row.code)}
                    onClick={() => onAct('reissue')}
                >
                    {credentials.certificates.reissue}
                </Button>
            )}
            {!closed && (
                <Button
                    variant="outline"
                    size="sm"
                    aria-label={strings.revokeNamed(row.code)}
                    onClick={() => onAct('revoke')}
                >
                    {strings.revoke}
                </Button>
            )}
        </span>
    );
}

export function CertificateCeremonies({
    acting,
    onClose: closeCeremony,
    onDone: done,
}: {
    acting: { row: CertificateRecord; ceremony: Ceremony } | null;
    onClose: (open: boolean) => void;
    onDone: (message: string) => void;
}) {
    return (
        <>
            {acting?.ceremony === 'revoke' && (
                <RevokeDialog
                    open
                    onOpenChange={closeCeremony}
                    certificateId={acting.row.id}
                    code={acting.row.code}
                    learner={acting.row.learner.name ?? adminStrings.enrolments.unknownLearner}
                    course={acting.row.course.title}
                    onDone={done}
                />
            )}
            {acting?.ceremony === 'suspend' && (
                <SuspendDialog open onOpenChange={closeCeremony} certificate={acting.row} onDone={done} />
            )}
            {acting?.ceremony === 'reinstate' && (
                <ReinstateDialog open onOpenChange={closeCeremony} certificate={acting.row} onDone={done} />
            )}
            {acting?.ceremony === 'reissue' && (
                <ReissueDialog open onOpenChange={closeCeremony} certificate={acting.row} onDone={done} />
            )}
            {acting?.ceremony === 'detail' && (
                <CertificateDetailDialog
                    open
                    onOpenChange={closeCeremony}
                    certificateId={acting.row.id}
                    code={acting.row.code}
                />
            )}
        </>
    );
}
