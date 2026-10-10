import { translate as uiText } from '@/shared/i18n';
import { useRef, useState } from 'react';
import { Download, Plus, RefreshCw } from 'lucide-react';
import { Button, Icon, Skeleton, buttonVariants } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { ProblemError, useAdminDashboard, useManagedCourses } from '../api';
import { screenUrl, useAdminConfig, workbenchUrl } from '../config';
import { formatNumber, parseApiDate } from '../lib';
import { adminStrings } from '../strings';
import { NewCourseDialog } from '../components/new-course-dialog';
import {
    AdminNotice,
    CountsUnavailable,
    PermissionNotice,
    ProDataNotice,
    ScreenError,
    WelcomeState,
} from '../components/states';
import {
    activityCsv,
    LearningActivity,
    LearnerProgress,
    NeedsAttention,
    OverviewMetrics,
    OverviewPanel,
    RecentActivity,
    TopCourses,
} from './overview-panels';

const s = adminStrings.dashboard;

/** Keep a manager's courses usable when site-wide reporting is refused. */
function CoursesFallback() {
    const config = useAdminConfig();
    const courses = useManagedCourses({
        sort: 'updated_at',
        order: 'desc',
        per_page: 5,
    });
    const items = courses.data?.items ?? [];
    return (
        <OverviewPanel id="vy-fallback-courses" title={s.fallbackCourses.title}>
            {courses.isPending && <Skeleton className="m-4 h-16" />}
            {courses.isError && (
                <div className="p-4">
                    <ScreenError error={courses.error} onRetry={() => void courses.refetch()} />
                </div>
            )}
            {courses.isSuccess && items.length === 0 && (
                <p className="vy-overview-empty">{s.fallbackCourses.empty}</p>
            )}
            {items.length > 0 && (
                <ul>
                    {items.map((course) => (
                        <li
                            key={course.id}
                            className="flex items-center gap-3 border-t border-border p-4 text-sm"
                        >
                            <span className="min-w-0 flex-1 truncate">{course.title}</span>
                            <span>{formatNumber(course.enrolled_count, config.locale)}</span>
                            <a
                                className={buttonVariants({ variant: 'outline', size: 'sm' })}
                                href={workbenchUrl(config, course.id)}
                                aria-label={adminStrings.courses.openNamed(course.title)}
                            >
                                {adminStrings.courses.open}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </OverviewPanel>
    );
}

export function DashboardScreen() {
    const config = useAdminConfig();
    const announce = useAnnounce();
    const [period, setPeriod] = useState<7 | 30>(30);
    const [tab, setTab] = useState<'overview' | 'activity'>('overview');
    const tabList = useRef<HTMLDivElement>(null);
    const dashboard = useAdminDashboard({ period_days: period });
    const [dialogOpen, setDialogOpen] = useState(false);
    const [created, setCreated] = useState<{ id: number; title: string } | null>(null);
    const courseButton = (
        <Button onClick={() => setDialogOpen(true)}>
            <Icon icon={Plus} size={17} />
            {adminStrings.welcome.action}
        </Button>
    );
    const dialog = (
        <NewCourseDialog
            open={dialogOpen}
            onOpenChange={setDialogOpen}
            onCreated={(course) => {
                setCreated({ id: course.id, title: course.title });
                announce(adminStrings.courses.created(course.title));
            }}
        />
    );
    const createdNotice = created && (
        <AdminNotice
            glyph="✓"
            title={adminStrings.courses.created(created.title)}
            action={
                <a className={buttonVariants({ variant: 'outline' })} href={workbenchUrl(config, created.id)}>
                    {adminStrings.courses.open}
                </a>
            }
        />
    );
    const data = dashboard.data;
    const date = parseApiDate(data?.generated_at) ?? new Date();
    const heading = (
        <header className="vy-overview-heading">
            <div>
                <div className="vy-overview-eyebrow">
                    <i />
                    {new Intl.DateTimeFormat(config.locale, {
                        weekday: 'long',
                        month: 'long',
                        day: 'numeric',
                        year: 'numeric',
                    }).format(date)}
                </div>
                <h1>
                    {s.title}
                    <span>.</span>
                </h1>
                <p>{s.description(config.user.name)}</p>
            </div>
            {data && data.courses.published + data.courses.draft + data.courses.archived === 0
                ? null
                : courseButton}
        </header>
    );
    const noCourses =
        data !== undefined && data.courses.published + data.courses.draft + data.courses.archived === 0;

    function exportActivity() {
        if (!data) return;
        const url = URL.createObjectURL(new Blob([activityCsv(data)], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `vidyalms-learning-activity-${data.activity.period_days}-days.csv`;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        announce(s.exported);
    }

    if (dashboard.isError) {
        const status = dashboard.error instanceof ProblemError ? dashboard.error.status : 0;
        if (status === 401)
            return <ScreenError error={dashboard.error} onRetry={() => void dashboard.refetch()} />;
        return (
            <div className="vy-dashboard-grid">
                {heading}
                {status === 403 ? (
                    <PermissionNotice coursesUrl={screenUrl(config, 'courses')} />
                ) : (
                    <CountsUnavailable onRetry={() => void dashboard.refetch()} />
                )}
                <CoursesFallback />
                {dialog}
                {createdNotice}
            </div>
        );
    }

    return (
        <div className="vy-overview">
            {heading}
            <div className="vy-overview-toolbar">
                <div
                    className="vy-overview-tabs"
                    role="tablist"
                    aria-label={s.dashboardViews}
                    ref={tabList}
                    onKeyDown={(event) => {
                        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                        event.preventDefault();
                        const next =
                            event.key === 'Home'
                                ? 'overview'
                                : event.key === 'End'
                                  ? 'activity'
                                  : tab === 'overview'
                                    ? 'activity'
                                    : 'overview';
                        setTab(next);
                        tabList.current?.querySelector<HTMLButtonElement>(`[id="vy-tab-${next}"]`)?.focus();
                    }}
                >
                    {(['overview', 'activity'] as const).map((value) => (
                        <button
                            key={value}
                            id={`vy-tab-${value}`}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            aria-controls={`vy-panel-${value}`}
                            tabIndex={tab === value ? 0 : -1}
                            onClick={() => setTab(value)}
                        >
                            {value === 'overview' ? s.overviewTab : s.activityFeed}
                            {value === 'activity' && data && <span>{data.activity.recent.length}</span>}
                        </button>
                    ))}
                </div>
                <div className="vy-overview-actions">
                    <select
                        className="vy-overview-select"
                        aria-label={s.reportingPeriod}
                        value={period}
                        onChange={(event) => setPeriod(Number(event.target.value) as 7 | 30)}
                    >
                        <option value={30}>{s.lastDays(30)}</option>
                        <option value={7}>{s.lastDays(7)}</option>
                    </select>
                    <Button
                        variant="outline"
                        onClick={exportActivity}
                        disabled={!data || dashboard.isFetching}
                    >
                        <Icon icon={Download} size={15} />
                        {s.export}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        aria-label={s.refreshed}
                        title={s.refreshed}
                        disabled={dashboard.isFetching}
                        onClick={() => void dashboard.refetch()}
                    >
                        <Icon icon={RefreshCw} size={15} />
                    </Button>
                </div>
            </div>
            {data === undefined ? (
                <div className="vy-overview-content" aria-busy="true">
                    <div className="vy-overview-metrics">
                        {[0, 1, 2, 3].map((index) => (
                            <Skeleton key={index} className="h-36" />
                        ))}
                    </div>
                    <div className="vy-overview-columns">
                        <Skeleton className="h-80" />
                        <Skeleton className="h-80" />
                    </div>
                    <Skeleton className="h-80" />
                </div>
            ) : (
                <>
                    {noCourses && (
                        <div className="mb-5">
                            <WelcomeState action={courseButton} />
                        </div>
                    )}
                    {data.attention.lessons_without_provider.count > 0 && (
                        <ProDataNotice
                            count={data.attention.lessons_without_provider.count}
                            types={data.attention.lessons_without_provider.types}
                        />
                    )}
                    <div
                        id="vy-panel-overview"
                        role="tabpanel"
                        aria-labelledby="vy-tab-overview"
                        hidden={tab !== 'overview'}
                        tabIndex={0}
                    >
                        <div className="vy-overview-content">
                            <OverviewMetrics data={data} config={config} />
                            <div className="vy-overview-columns">
                                <LearningActivity data={data} config={config} />
                                <NeedsAttention data={data} config={config} />
                            </div>
                            <div className="vy-overview-columns">
                                <TopCourses data={data} config={config} />
                                <LearnerProgress data={data} config={config} />
                            </div>
                            <RecentActivity data={data} config={config} />
                        </div>
                    </div>
                    <div
                        id="vy-panel-activity"
                        role="tabpanel"
                        aria-labelledby="vy-tab-activity"
                        hidden={tab !== 'activity'}
                        tabIndex={0}
                    >
                        <RecentActivity data={data} config={config} full />
                    </div>
                    <footer className="vy-overview-footer">
                        <span>{s.currentTotals}</span>
                        <span>
                            <i />
                            {s.updated}{' '}
                            {new Intl.DateTimeFormat(config.locale, {
                                day: 'numeric',
                                month: 'short',
                                hour: '2-digit',
                                minute: '2-digit',
                                timeZone: 'UTC',
                            }).format(date)}{' '}
                            {uiText("vidyalms.ui.7e5f76c94a635c21", "UTC")}</span>
                    </footer>
                </>
            )}
            {dialog}
            {createdNotice}
        </div>
    );
}
