import { translate as uiText } from '@/shared/i18n';
import { useEffect, useRef, useState } from 'react';
import { Activity, ArrowUpRight, BookOpen, Download, GraduationCap, Plus, Users } from 'lucide-react';
import {
    Button,
    DataTable,
    EmptyState,
    Icon,
    Pagination,
    ProgressBar,
    type DataTableColumn,
} from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useAdminLearners, type AdminLearner } from '../api';
import { screenUrl, useAdminConfig } from '../config';
import { useDebounced, useHashRoute, learnerIdFromHash } from '../hooks';
import { formatAgo, formatNumber, pageRange, roundPercent } from '../lib';
import { learnerDirectoryStrings as directory } from '../people-strings';
import { adminStrings } from '../strings';
import { FilterSearch } from '../components/filter-toolbar';
import { GrantAccessDialog } from '../components/grant-access-dialog';
import { LearnerRecordView } from '../components/learner-record';
import { PageHeader } from '../components/page-header';
import { AdminNotice, ScreenError } from '../components/states';
import {
    LearnerAvatar,
    LearnerFilter,
    LearnerSummary,
    csvContent,
    downloadFile,
} from '../learners/workspace';
import { learnerWorkspaceStrings as s } from '../learners/strings';
import { useLearnerDirectoryState } from '../learners/directory-state';

const SORT_OPTIONS = [
    { value: 'last_active', label: directory.sortOptions.lastActive },
    { value: 'progress', label: directory.sortOptions.progress },
    { value: 'courses', label: directory.sortOptions.courses },
    { value: 'enrolled_at', label: directory.sortOptions.enrolled },
];

export function LearnersScreen() {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const [hash, navigate] = useHashRoute();
    const learnerId = learnerIdFromHash(hash);
    const listOrigin = useRef('/');
    if (learnerId === null && (hash === '' || hash === '/' || hash.startsWith('/?')))
        listOrigin.current = hash || '/';
    const list = useLearnerDirectoryState();
    const search = useDebounced(list.search).trim();
    const [grantOpen, setGrantOpen] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const learners = useAdminLearners(
        {
            q: search || undefined,
            sort: list.sort,
            order: list.order,
            page: list.page,
            per_page: list.perPage,
        },
        { enabled: learnerId === null },
    );
    const items = learners.data?.items ?? [];
    const total = learners.data?.total ?? 0;
    useEffect(() => {
        if (
            learnerId === null &&
            learners.isSuccess &&
            !learners.isFetching &&
            search === list.search.trim()
        ) {
            const last = Math.max(1, Math.ceil(total / list.perPage));
            if (list.page > last) list.update({ page: last });
        }
    }, [learnerId, learners.isSuccess, learners.isFetching, search, list, total]);

    if (learnerId !== null)
        return (
            <LearnerRecordView
                key={learnerId}
                userId={learnerId}
                onBack={() => navigate(listOrigin.current)}
            />
        );
    const report = (text: string) => {
        setMessage(text);
        announce(text);
    };
    const open = (row: AdminLearner) => navigate(`/learner/${row.user_id}`);
    const count = (value: number) => (learners.isSuccess ? formatNumber(value, config.locale) : '—');
    const columns: DataTableColumn<AdminLearner>[] = [
        {
            key: 'learner',
            header: directory.columns.learner,
            weight: 1.8,
            minWidth: 260,
            cell: (row) => (
                <div className="vy-learner-person">
                    <LearnerAvatar id={row.user_id} name={row.user?.name ?? '?'} />
                    <div>
                        {row.user ? (
                            <button
                                className="vy-learner-name"
                                aria-label={directory.openNamed(row.user.name)}
                                onClick={() => open(row)}
                            >
                                {row.user.name}
                            </button>
                        ) : (
                            <strong>{directory.accountRemoved}</strong>
                        )}
                        <small>{row.user?.email ?? directory.removedMeta(row.user_id)}</small>
                    </div>
                </div>
            ),
        },
        {
            key: 'courses',
            header: directory.columns.courses,
            sortKey: 'courses',
            width: '92px',
            cell: (row) => (
                <strong className="vy-learner-count">{formatNumber(row.course_count, config.locale)}</strong>
            ),
        },
        {
            key: 'active',
            header: directory.columns.active,
            width: '84px',
            cell: (row) => formatNumber(row.active_course_count, config.locale),
        },
        {
            key: 'completed',
            header: directory.columns.completed,
            width: '98px',
            cell: (row) => formatNumber(row.completed_course_count, config.locale),
        },
        {
            key: 'progress',
            header: directory.columns.progress,
            sortKey: 'progress',
            minWidth: 165,
            cell: (row) => (
                <ProgressBar
                    value={roundPercent(row.average_progress) ?? 0}
                    label={directory.progressLabel(row.user?.name ?? directory.accountRemoved)}
                    showValue
                />
            ),
        },
        {
            key: 'last-active',
            header: directory.columns.lastActive,
            sortKey: 'last_active',
            minWidth: 115,
            cell: (row) => (
                <span className="text-fg-2">
                    {formatAgo(row.last_activity_at, config.locale) ?? directory.noActivity}
                </span>
            ),
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '105px',
            align: 'end',
            cell: (row) =>
                row.user ? (
                    <Icon icon={ArrowUpRight} size={16} className="text-fg-2" />
                ) : (
                    <small className="text-fg-2">{directory.unavailable}</small>
                ),
        },
    ];
    const exportPage = () => {
        downloadFile(
            csvContent([
                [
                    'Learner ID',
                    'Name',
                    'Email',
                    'Courses',
                    'Active courses',
                    'Completed courses',
                    'Average progress %',
                    'Last activity',
                ],
                ...items.map((row) => [
                    row.user_id,
                    row.user?.name ?? directory.accountRemoved,
                    row.user?.email,
                    row.course_count,
                    row.active_course_count,
                    row.completed_course_count,
                    row.average_progress,
                    row.last_activity_at,
                ]),
            ]),
            `vidyalms-learners-page-${list.page}.csv`,
        );
        announce(s.exported);
    };
    return (
        <div className="vy-learners-workspace">
            <PageHeader
                title={adminStrings.learners.title}
                eyebrow={s.eyebrow}
                description={s.description}
                actions={
                    <>
                        <Button
                            variant="outline"
                            onClick={exportPage}
                            disabled={!items.length || learners.isFetching || search !== list.search.trim()}
                        >
                            <Icon icon={Download} size={15} />
                            {s.exportPage}
                        </Button>
                        <Button onClick={() => setGrantOpen(true)}>
                            <Icon icon={Plus} size={16} />
                            {adminStrings.enrolments.grant}
                        </Button>
                    </>
                }
            />
            <LearnerSummary
                label={s.summary}
                items={[
                    { label: s.matching, value: count(total), detail: s.allPages, icon: Users },
                    {
                        label: s.enrolments,
                        value: count(items.reduce((sum, row) => sum + row.course_count, 0)),
                        detail: s.onPage,
                        icon: BookOpen,
                    },
                    {
                        label: s.completed,
                        value: count(items.reduce((sum, row) => sum + row.completed_course_count, 0)),
                        detail: s.onPage,
                        icon: GraduationCap,
                    },
                    {
                        label: s.average,
                        value: learners.isSuccess
                            ? `${items.length ? Math.round(items.reduce((sum, row) => sum + row.average_progress, 0) / items.length) : 0}%`
                            : '—',
                        detail: s.onPage,
                        icon: Activity,
                    },
                ]}
            />
            {message && (
                <AdminNotice
                    glyph="✓"
                    title={message}
                    action={
                        <Button variant="ghost" size="sm" onClick={() => setMessage(null)}>
                            {adminStrings.courses.dismiss}
                        </Button>
                    }
                />
            )}
            <section className="vy-learner-collection" aria-label={directory.table}>
                <div className="vy-learner-toolbar" role="search" aria-label={uiText("vidyalms.ui.2dd87522c229c9d6", "Learner search and filters")}>
                    <FilterSearch
                        label={adminStrings.learners.searchLabel}
                        placeholder={directory.searchPlaceholder}
                        value={list.search}
                        onValueChange={(value) => list.update({ search: value })}
                    />
                    {list.search && (
                        <Button variant="ghost" size="sm" onClick={() => list.update({ search: '' })}>
                            {s.reset}
                        </Button>
                    )}
                    <div className="vy-learner-toolbar-filters">
                        <LearnerFilter
                            label={directory.sortLabel}
                            value={list.sort}
                            options={SORT_OPTIONS}
                            onValueChange={(value) => list.update({ sort: value as typeof list.sort })}
                        />
                        <LearnerFilter
                            label={s.sortOrder}
                            value={list.order}
                            options={[
                                { value: 'desc', label: s.descending },
                                { value: 'asc', label: s.ascending },
                            ]}
                            onValueChange={(value) =>
                                list.update({ order: value === 'asc' ? 'asc' : 'desc' })
                            }
                        />
                    </div>
                </div>
                {(learners.isFetching || search !== list.search.trim()) && !learners.isPending && (
                    <p role="status" className="vy-learner-collection-note">
                        {s.refreshing}
                    </p>
                )}
                {learners.isError ? (
                    <div className="p-5">
                        <ScreenError
                            error={learners.error}
                            onRetry={() => void learners.refetch()}
                            coursesUrl={screenUrl(config, 'courses')}
                        />
                    </div>
                ) : (
                    <DataTable
                        caption={directory.table}
                        columns={columns}
                        rows={items}
                        rowKey={(row) => row.user_id}
                        sort={{ key: list.sort, order: list.order }}
                        onSortChange={(value) =>
                            list.update({ sort: value.key as typeof list.sort, order: value.order })
                        }
                        loading={learners.isPending}
                        empty={
                            <EmptyState
                                title={list.search ? directory.empty : s.emptyTitle}
                                description={list.search ? undefined : s.emptyDescription}
                            />
                        }
                    />
                )}
                <footer className="vy-learner-collection-footer">
                    <span aria-live="polite">{pageRange(list.page, list.perPage, total, items.length)}</span>
                    <div>
                        <LearnerFilter
                            label={s.perPage}
                            value={String(list.perPage)}
                            options={[20, 50, 100].map((value) => ({
                                value: String(value),
                                label: uiText("vidyalms.ui.4d72d6c7364d5c21", "{p0} per page", { p0: value }),
                            }))}
                            onValueChange={(value) => list.update({ perPage: Number(value) })}
                        />
                        <Pagination
                            label={uiText("vidyalms.ui.881e915fc6a32df7", "Learner pages")}
                            page={list.page}
                            perPage={list.perPage}
                            total={total}
                            onPageChange={(page) => list.update({ page })}
                        />
                    </div>
                </footer>
                <span className="sr-only">{adminStrings.list.total(total)}</span>
            </section>
            <p className="vy-learner-footnote">{s.retained}</p>
            <GrantAccessDialog open={grantOpen} onOpenChange={setGrantOpen} onGranted={report} />
        </div>
    );
}
