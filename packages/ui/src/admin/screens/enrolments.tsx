import { translate as uiText } from '@/shared/i18n';
import { WorkspacePage } from '../components/workspace-page';
import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, ProgressBar, type DataTableColumn } from '@/shared/components';
import { CalendarClock, ShieldCheck, UserRoundCheck } from 'lucide-react';
import { useAnnounce } from '@/shared/a11y';
import { useAdminEnrolments, type EnrolmentListParams, type EnrolmentRecord } from '../api';
import { screenUrl, useAdminConfig } from '../config';
import { useDebounced, useHashRoute, useListState } from '../hooks';
import { expiryText, formatNumber, roundPercent, sourceLabel } from '../lib';
import { enrolmentExperienceStrings as experience } from '../people-strings';
import { adminStrings } from '../strings';
import { ExtendDialog } from '../components/extend-dialog';
import { CompactStats } from '../components/compact-stats';
import { FilterChips } from '../components/filter-chips';
import { GrantAccessDialog } from '../components/grant-access-dialog';
import { ListFrame } from '../components/list-frame';
import { AdminNotice, ScreenError } from '../components/states';

/*
 * Artboard 2b. Entitlement source and expiry are columns, not footnotes,
 * and suspended is a filter. The chips carry the facet counts the contract
 * ships with every page, so "Expiring within 14 days (23)" is the server's
 * number and not a count of the rows on screen.
 */

const PER_PAGE = 20;
const EXPIRING_DAYS = 14;
const strings = adminStrings.enrolments;

function paramsFor(filter: string): Pick<EnrolmentListParams, 'status' | 'expiring_within_days'> {
    switch (filter) {
        case 'active':
            return { status: 'active' };
        case 'expiring':
            return { status: 'active', expiring_within_days: EXPIRING_DAYS };
        case 'suspended':
            return { status: 'suspended' };
        default:
            return {};
    }
}

export function EnrolmentsScreen() {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const [, navigate] = useHashRoute();
    const list = useListState(
        'all',
        { key: 'expires_at', order: 'asc' },
        {
            persistence: 'hash-query',
            allowedFilters: ['all', 'active', 'expiring', 'suspended'],
            allowedSortKeys: ['expires_at', 'enrolled_at', 'last_active', 'progress', 'status'],
        },
    );
    const search = useDebounced(list.search).trim();
    const [message, setMessage] = useState<string | null>(null);
    const [grantOpen, setGrantOpen] = useState(false);
    const [extending, setExtending] = useState<EnrolmentRecord | null>(null);

    const params: EnrolmentListParams = {
        ...paramsFor(list.filter),
        q: search === '' ? undefined : search,
        /* Every sortable column below is declared with a contract sort value. */
        sort: list.sort.key as EnrolmentListParams['sort'],
        order: list.sort.order,
        page: list.page,
        per_page: PER_PAGE,
    };
    const enrolments = useAdminEnrolments(params);

    const items = enrolments.data?.items ?? [];
    const total = enrolments.data?.total ?? 0;
    const facets = enrolments.data?.facets;

    const report = (text: string) => {
        setMessage(text);
        announce(text);
    };

    const columns: DataTableColumn<EnrolmentRecord>[] = [
        {
            key: 'learner',
            header: strings.columns.learner,
            weight: 1.2,
            cell: (row) => {
                const name = row.user.name ?? strings.unknownLearner;
                return (
                    <button
                        type="button"
                        onClick={() => navigate(`/learner/${row.user.id}`)}
                        aria-label={strings.openRecord(name)}
                        className="cursor-pointer font-medium text-accent underline-offset-2 hover:underline"
                    >
                        <span className="vy-workspace-person">
                            <span className="vy-workspace-avatar" aria-hidden="true">
                                {name
                                    .split(/\s+/)
                                    .slice(0, 2)
                                    .map((part) => part[0])
                                    .join('')}
                            </span>
                            <span>{name}</span>
                        </span>
                    </button>
                );
            },
        },
        {
            key: 'course',
            header: strings.columns.course,
            weight: 1.4,
            cell: (row) => (
                <a
                    className="font-medium text-accent"
                    href={`${screenUrl(config, 'courses')}#/course/${row.course.id}`}
                >
                    {row.course.title}
                </a>
            ),
        },
        {
            key: 'source',
            header: strings.columns.source,
            width: '100px',
            cell: (row) => <span className="text-fg-2">{sourceLabel(row.entitlement.source)}</span>,
        },
        {
            key: 'progress',
            header: experience.columns.progress,
            sortKey: 'progress',
            weight: 0.8,
            minWidth: 150,
            cell: (row) => {
                const percent = roundPercent(row.progress_percent) ?? 0;
                const learner = row.user.name ?? strings.unknownLearner;
                return (
                    <ProgressBar
                        className="max-w-[170px]"
                        value={percent}
                        label={experience.progressLabel(learner, row.course.title)}
                        showValue
                    />
                );
            },
        },
        {
            key: 'expires',
            header: strings.columns.expires,
            sortKey: 'expires_at',
            width: '120px',
            cell: (row) => {
                const expiry = expiryText(row.entitlement.expires_at, config.locale);
                return (
                    <span className={expiry.soon || expiry.expired ? 'font-semibold text-warn-strong' : 'text-fg-2'}>
                        {expiry.text}
                    </span>
                );
            },
        },
        {
            key: 'status',
            header: experience.columns.status,
            sortKey: 'status',
            width: '108px',
            cell: (row) => (
                <Badge variant={row.status === 'active' || row.status === 'completed' ? 'ok' : 'neutral'}>
                    {adminStrings.learners.record.statuses[row.status] ?? row.status}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '110px',
            align: 'end',
            cell: (row) =>
                row.entitlement.source === 'open' && row.entitlement.expires_at === null ? (
                    <span className="text-[10.5px] text-fg-2">{strings.openCourseNote}</span>
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={strings.extendNamed(row.user.name ?? strings.unknownLearner, row.course.title)}
                        onClick={() => setExtending(row)}
                    >
                        {strings.extend}
                    </Button>
                ),
        },
    ];

    return (
        <>
            <WorkspacePage
                title={strings.title}
                description={uiText("vidyalms.ui.bea94d513987cc01", "Connect every learner to their next opportunity. Manage course access, track progress, and keep learning on schedule.")}
                actions={
                    <Button onClick={() => setGrantOpen(true)}>
                        <span aria-hidden="true">+</span>
                        {strings.grant}
                    </Button>
                }
            >
                {!enrolments.isError && (
                    <CompactStats
                        label={experience.statsLabel}
                        columns={3}
                        items={[
                            {
                                id: 'active',
                                label: experience.stats.active,
                                value: facets === undefined ? '-' : formatNumber(facets.active, config.locale),
                                detail: experience.stats.current,
                                icon: UserRoundCheck,
                                tone: 'accent',
                            },
                            {
                                id: 'expiring',
                                label: experience.stats.expiring,
                                value: facets === undefined ? '-' : formatNumber(facets.expiring, config.locale),
                                detail: experience.stats.withinDays(facets?.expiring_within_days ?? EXPIRING_DAYS),
                                icon: CalendarClock,
                                tone: 'warning',
                            },
                            {
                                id: 'suspended',
                                label: experience.stats.suspended,
                                value: facets === undefined ? '-' : formatNumber(facets.suspended, config.locale),
                                detail: experience.stats.progressSafe,
                                icon: ShieldCheck,
                                tone: 'neutral',
                            },
                        ]}
                    />
                )}
                <ListFrame
                    title={strings.title}
                    meta={enrolments.isSuccess ? adminStrings.list.total(total) : undefined}
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
                            chips={[
                                { value: 'all', label: adminStrings.list.all },
                                { value: 'active', label: strings.chips.active, count: facets?.active },
                                {
                                    value: 'expiring',
                                    label: strings.chips.expiring(facets?.expiring_within_days ?? EXPIRING_DAYS),
                                    count: facets?.expiring,
                                },
                                { value: 'suspended', label: strings.chips.suspended, count: facets?.suspended },
                            ]}
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
                    note={strings.note}
                    page={list.page}
                    perPage={PER_PAGE}
                    total={total}
                    shownCount={items.length}
                    onPageChange={list.setPage}
                >
                    {enrolments.isError ? (
                        <div className="p-4">
                            <ScreenError
                                error={enrolments.error}
                                onRetry={() => void enrolments.refetch()}
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
                            loading={enrolments.isPending}
                            empty={<EmptyState title={strings.empty} />}
                        />
                    )}
                </ListFrame>
            </WorkspacePage>
            <GrantAccessDialog open={grantOpen} onOpenChange={setGrantOpen} onGranted={report} />
            {extending !== null && (
                <ExtendDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setExtending(null);
                        }
                    }}
                    entitlementId={extending.entitlement.id}
                    learnerName={extending.user.name ?? strings.unknownLearner}
                    courseTitle={extending.course.title}
                    currentExpiry={extending.entitlement.expires_at}
                    locale={config.locale}
                    onDone={report}
                />
            )}
        </>
    );
}
