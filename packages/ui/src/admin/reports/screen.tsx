import { translate as uiText } from '@/shared/i18n';
import { useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    BookOpen,
    CalendarClock,
    Filter,
    LayoutGrid,
    RefreshCw,
    Search,
} from 'lucide-react';
import { ProblemError, type SiteLearningReport } from '@vidyalms/api-sdk';
import { useIsFetching, useQuery, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    ErrorState,
    Skeleton,
} from '@/shared/components';
import { PageHeader } from '../components/page-header';
import { useAdminConfig, workbenchUrl } from '../config';
import { useDebounced, useHashRoute } from '../hooks';
import { reportKeys, useCatalogue, useReport } from './api';
import { EntityPicker } from './entity-picker';
import { ExportDialog, ExportRun } from './exports';
import { ReportFilters } from './filters';
import {
    categories,
    categoryFor,
    fieldsFor,
    formatValue,
    parametersFor,
    positiveId,
    ready,
    reportHref,
    type Catalogue,
    type Parameters,
    type RecordData,
    type ReportData,
    type ReportDefinition,
} from './model';
import { Facts, LearningChart, LearningStats, ReportTable, ValueDetails, reportIcons } from './presentation';
import { ScheduleDialog, Schedules } from './schedules';
import { reportWorkspaceStrings as s } from './strings';

type Navigation = (path: string) => void;
function hashUrl(hash: string) {
    try {
        return new URL(hash || '/', 'https://route.invalid');
    } catch {
        return null;
    }
}
export function ReportsScreen() {
    const [hash, navigate] = useHashRoute();
    const routePath = hash.split('?')[0];
    useEffect(() => {
        const main = document.getElementById('vy-admin-main');
        if (main) main.scrollTop = 0;
        window.scrollTo({ top: 0 });
    }, [routePath]);
    const url = hashUrl(hash);
    const valid =
        url &&
        url.origin === 'https://route.invalid' &&
        (/^\/(?:library|schedules)?$/.test(url.pathname) ||
            /^\/report\/[^/]+$/.test(url.pathname) ||
            (/^\/exports\/[1-9]\d*$/.test(url.pathname) && positiveId(url.pathname.slice(9)) !== null));
    if (!valid || (url.searchParams.has('org') && positiveId(url.searchParams.get('org')) === null))
        return <EmptyState title={s.invalid} />;
    return <ReportWorkspace key={url.searchParams.get('org') ?? 'site'} url={url} navigate={navigate} />;
}
function ReportWorkspace({ url, navigate }: { url: URL; navigate: Navigation }) {
    const organisation = positiveId(url.searchParams.get('org'));
    const catalogue = useCatalogue(organisation);
    const cache = useQueryClient();
    const refreshing = useIsFetching({ queryKey: reportKeys }) > 0;
    const libraryOrigin = useRef('/');
    const path = url.pathname;
    if (path === '/' || path === '/library') libraryOrigin.current = `${path}${url.search}`;
    const scoped = (destination: string) => `${destination}${organisation ? `?org=${organisation}` : ''}`;
    const switchOrganisation = (id: number | null) => {
        const query = new URLSearchParams();
        if (id) query.set('org', String(id));
        const category = url.searchParams.get('category');
        if (category) query.set('category', category);
        navigate(`${['/schedules', '/library'].includes(path) ? path : '/'}${query.size ? `?${query}` : ''}`);
    };
    const denied = catalogue.error instanceof ProblemError && [403, 404].includes(catalogue.error.status);
    if (catalogue.isError)
        return (
            <div className="vy-report-workspace">
                <PageHeader title={s.title} description={s.intro} />
                {denied ? (
                    <EmptyState title={s.forbidden} description={s.forbiddenHint} />
                ) : (
                    <ErrorState title={s.failed} error={catalogue.error} onRetry={() => void catalogue.refetch()} />
                )}
                {organisation && (
                    <Button variant="outline" onClick={() => switchOrganisation(null)}>
                        {s.allOrganisations}
                    </Button>
                )}
            </div>
        );
    let key = '';
    try {
        key = path.startsWith('/report/') ? decodeURIComponent(path.slice(8)) : '';
    } catch {
        return <EmptyState title={s.invalid} />;
    }
    const report = catalogue.data?.reports.find((item) => item.key === key);
    const isExplorer = path.startsWith('/report/');
    const runId = path.startsWith('/exports/') ? positiveId(path.slice(9)) : null;
    const back = url.searchParams.get('back');
    const safeBack = back && /^\/(report\/[^/]+|library)?(?:\?.*)?$/.test(back) ? back : libraryOrigin.current;
    return (
        <div className="vy-report-workspace">
            {isExplorer || runId ? (
                <Button
                    className="vy-report-back"
                    variant="ghost"
                    onClick={() =>
                        navigate(runId ? safeBack : libraryOrigin.current === '/' ? scoped('/') : libraryOrigin.current)
                    }
                >
                    <ArrowLeft size={15} />
                    {isExplorer ? s.backLibrary : s.back}
                </Button>
            ) : null}
            <PageHeader
                title={
                    isExplorer ? (
                        (report?.label ?? s.title)
                    ) : runId ? (
                        s.runHeading
                    ) : (
                        <>
                            {s.title}
                            <span className="vy-report-title-dot">.</span>
                        </>
                    )
                }
                description={isExplorer && report ? categoryFor(report.key).description : s.intro}
                eyebrow={isExplorer ? s.explorer : undefined}
                actions={
                    !isExplorer && !runId ? (
                        <Button
                            variant="outline"
                            onClick={() => void cache.invalidateQueries({ queryKey: reportKeys })}
                            disabled={refreshing}
                        >
                            <RefreshCw size={14} />
                            {s.refreshReports}
                        </Button>
                    ) : undefined
                }
            />
            {!isExplorer && !runId && (
                <nav className="vy-report-tabs" aria-label={s.workspace}>
                    {[
                        { path: '/', label: s.overview, Icon: LayoutGrid },
                        { path: '/library', label: s.library, Icon: BookOpen },
                        { path: '/schedules', label: s.schedules, Icon: CalendarClock },
                    ].map((tab) => (
                        <button
                            key={tab.path}
                            aria-current={path === tab.path ? 'page' : undefined}
                            onClick={() => navigate(scoped(tab.path))}
                        >
                            <tab.Icon size={15} />
                            {tab.label}
                        </button>
                    ))}
                </nav>
            )}
            {catalogue.isPending ? (
                <Skeleton className="h-64 w-full" aria-label={s.loading} />
            ) : (
                catalogue.data && (
                    <>
                        {!isExplorer && !runId && (path !== '/' || organisation !== null) && (
                            <div className="vy-report-scope">
                                <EntityPicker kind="organisation" value={organisation} onChange={switchOrganisation} />
                                <span>{organisation ? s.organisationScope : s.site}</span>
                            </div>
                        )}
                        {runId ? (
                            <ExportRun id={runId} />
                        ) : isExplorer ? (
                            report ? (
                                <Explorer
                                    key={`${report.key}:${organisation}`}
                                    report={report}
                                    catalogue={catalogue.data}
                                    organisation={organisation}
                                    url={url}
                                    navigate={navigate}
                                />
                            ) : (
                                <EmptyState title={s.missing} />
                            )
                        ) : path === '/schedules' ? (
                            <Schedules catalogue={catalogue.data} organisation={organisation} />
                        ) : path === '/library' ? (
                            <ReportLibrary
                                catalogue={catalogue.data}
                                organisation={organisation}
                                url={url}
                                navigate={navigate}
                            />
                        ) : (
                            <Overview
                                catalogue={catalogue.data}
                                organisation={organisation}
                                url={url}
                                navigate={navigate}
                                onOrganisation={switchOrganisation}
                            />
                        )}
                    </>
                )
            )}
        </div>
    );
}
function Overview({
    catalogue,
    organisation,
    url,
    navigate,
    onOrganisation,
}: {
    catalogue: Catalogue;
    organisation: number | null;
    url: URL;
    navigate: Navigation;
    onOrganisation: (id: number | null) => void;
}) {
    const client = useApiClient();
    const search = url.searchParams.get('search') ?? '';
    const debounced = useDebounced(search);
    const rawState = url.searchParams.get('state') ?? '';
    const state = ['draft', 'published', 'archived'].includes(rawState)
        ? (rawState as 'draft' | 'published' | 'archived')
        : undefined;
    const page = Math.min(1000000, positiveId(url.searchParams.get('page')) ?? 1);
    const requestedSize = Number(url.searchParams.get('per_page') ?? 20);
    const perPage = [20, 50, 100].includes(requestedSize) ? requestedSize : 20;
    const query = useQuery({
        queryKey: [...reportKeys, 'overview', page, perPage, debounced, state],
        enabled: organisation === null,
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/reports/overview', {
                        params: { query: { page, per_page: perPage, search: debounced || undefined, state } },
                        signal,
                    })
                ).data,
            ),
    });
    const orgSummary = useReport(
        'org-overview',
        {},
        organisation,
        1,
        20,
        organisation !== null && catalogue.reports.some((r) => r.key === 'org-overview'),
    );
    const change = (patch: Record<string, string | number>) => {
        const params = new URLSearchParams(url.searchParams);
        for (const [key, value] of Object.entries(patch)) {
            if (value === '') params.delete(key);
            else params.set(key, String(value));
        }
        navigate(`/?${params}`);
    };
    const [detail, setDetail] = useState<RecordData | null>(null);
    const course = (id: number) => navigate(reportHref('course-overview', { course_id: id }));
    const category = url.searchParams.get('category');
    return (
        <>
            {organisation === null && (
                <section className="vy-report-panel vy-report-filter-panel">
                    <div className="vy-report-panel-heading">
                        <div>
                            <h2>
                                <Filter size={16} />
                                {s.filters}
                            </h2>
                            <p>{s.allTime}</p>
                        </div>
                        <Button variant="ghost" size="sm" onClick={() => navigate('/')}>
                            {s.reset}
                        </Button>
                    </div>
                    <div className="vy-report-overview-filters">
                        <EntityPicker kind="organisation" value={organisation} onChange={onOrganisation} />
                        <label className="vy-report-field">
                            <span>{s.searchCourses}</span>
                            <div className="vy-report-search">
                                <Search size={16} />
                                <input
                                    type="search"
                                    aria-label={s.searchCourses}
                                    value={search}
                                    onChange={(event) => change({ search: event.target.value, page: 1 })}
                                />
                            </div>
                        </label>
                        <label className="vy-report-field">
                            <span>{s.courseStatus}</span>
                            <select
                                value={state ?? ''}
                                onChange={(event) => change({ state: event.target.value, page: 1 })}
                            >
                                <option value="">{s.allStatuses}</option>
                                <option value="draft">{s.draft}</option>
                                <option value="published">{s.published}</option>
                                <option value="archived">{s.archived}</option>
                            </select>
                        </label>
                        <EntityPicker
                            kind="course"
                            value={null}
                            onChange={(id) => {
                                if (id) course(id);
                            }}
                        />
                    </div>
                </section>
            )}
            {organisation === null ? (
                <>
                    {query.isError ? (
                        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
                    ) : (
                        <LearningStats summary={query.data?.summary} />
                    )}
                </>
            ) : orgSummary.data?.computed ? (
                <>
                    <Facts data={orgSummary.data} />
                    {Number(orgSummary.data.suppressed_cells ?? 0) > 0 && (
                        <p className="vy-report-notice">{s.hidden}</p>
                    )}
                </>
            ) : orgSummary.isError ? (
                <ErrorState error={orgSummary.error} onRetry={() => void orgSummary.refetch()} />
            ) : null}
            <div className="vy-report-library-grid">
                {categories
                    .filter(
                        (group) =>
                            catalogue.reports.some((r) => categoryFor(r.key).key === group.key) ||
                            (organisation === null && ['organizations', 'certificates'].includes(group.key)),
                    )
                    .map((group) => {
                        const count = catalogue.reports.filter((r) => categoryFor(r.key).key === group.key).length;
                        const Icon = reportIcons[group.icon];
                        return (
                            <button
                                key={group.key}
                                className="vy-report-category-card"
                                onClick={() =>
                                    navigate(
                                        `/library?category=${group.key}${organisation ? `&org=${organisation}` : ''}`,
                                    )
                                }
                            >
                                <span className={`vy-report-category-icon ${group.tone}`}>
                                    <Icon size={22} />
                                </span>
                                <h2>{group.title}</h2>
                                <p>{group.description}</p>
                                <span className="vy-report-card-bottom">
                                    {s.explore}
                                    <ArrowRight size={15} />
                                    {count > 0 && <small>{s.available(count)}</small>}
                                </span>
                            </button>
                        );
                    })}
            </div>
            {category && (
                <ReportLibrary catalogue={catalogue} organisation={organisation} url={url} navigate={navigate} />
            )}
            {organisation === null && query.data && (
                <>
                    <LearningChart rows={query.data.items} onCourse={course} />
                    <p className="vy-report-scope-note">
                        {query.data.scope === 'assigned_courses' ? s.assigned : s.site} · {s.observed}:{' '}
                        {formatValue(query.data.generated_at, 'generated_at')}
                        <span>{s.allTimeHint}</span>
                    </p>
                    <ReportTable
                        data={learningData(query.data)}
                        page={page}
                        perPage={perPage}
                        onPage={(p) => change({ page: p })}
                        onSize={(size) => change({ per_page: size, page: 1 })}
                        onCourse={course}
                        onDetail={setDetail}
                    />
                </>
            )}
            {detail && <RecordDialog row={detail} onClose={() => setDetail(null)} onCourse={course} />}
        </>
    );
}
function learningData(data: SiteLearningReport): ReportData {
    return {
        ...data,
        report: 'site-learning',
        computed: true,
        computed_by: 'site-learning',
        reason: null,
        columns: [
            { key: 'title', label: s.course },
            { key: 'state', label: s.courseStatus },
            { key: 'enrolments', label: s.enrolments },
            { key: 'active', label: s.activeEnrolments },
            { key: 'completed', label: s.completed },
            { key: 'completion_rate', label: s.completionRate },
        ],
    };
}
function ReportLibrary({
    catalogue,
    organisation,
    url,
    navigate,
}: {
    catalogue: Catalogue;
    organisation: number | null;
    url: URL;
    navigate: Navigation;
}) {
    const search = url.searchParams.get('q') ?? '';
    const group = url.searchParams.get('category') ?? '';
    const change = (patch: Record<string, string>) => {
        const params = new URLSearchParams(url.searchParams);
        for (const [key, value] of Object.entries(patch)) {
            if (value) params.set(key, value);
            else params.delete(key);
        }
        navigate(`/library${params.size ? `?${params}` : ''}`);
    };
    const reports = catalogue.reports.filter(
        (report) =>
            (!group || categoryFor(report.key).key === group) &&
            `${report.label} ${report.key}`.toLowerCase().includes(search.toLowerCase()),
    );
    const category = categories.find((item) => item.key === group);
    return (
        <section className="vy-report-library">
            <div className="vy-report-library-heading">
                <div>
                    <h2>{category?.title ?? s.availableReports}</h2>
                    <p>{category?.description ?? s.intro}</p>
                </div>
                <span>{s.available(reports.length)}</span>
            </div>
            <div className="vy-report-library-tools">
                <label className="vy-report-search">
                    <Search size={16} />
                    <input
                        type="search"
                        aria-label={s.searchReports}
                        placeholder={s.searchReports}
                        value={search}
                        onChange={(event) => change({ q: event.target.value })}
                    />
                </label>
                <label className="vy-report-field">
                    <span className="sr-only">{uiText("vidyalms.ui.292c06f0045a45d0", "Category")}</span>
                    <select
                        aria-label={uiText("vidyalms.ui.c6b037244870aa4b", "Report category")}
                        value={group}
                        onChange={(event) => change({ category: event.target.value })}
                    >
                        <option value="">{s.allCategories}</option>
                        {categories.map((item) => (
                            <option key={item.key} value={item.key}>
                                {item.title}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {!reports.length ? (
                <EmptyState
                    title={
                        organisation === null && ['organizations', 'certificates'].includes(group)
                            ? s.organisationFirst
                            : s.empty
                    }
                    description={
                        organisation === null && ['organizations', 'certificates'].includes(group)
                            ? s.organisationHint
                            : s.emptyHint
                    }
                />
            ) : (
                <div className="vy-report-library-grid">
                    {reports.map((report) => {
                        const category = categoryFor(report.key);
                        const Icon = reportIcons[category.icon];
                        return (
                            <button
                                className="vy-report-category-card vy-report-definition-card"
                                key={report.key}
                                onClick={() => navigate(reportHref(report.key, {}, organisation))}
                            >
                                <div className="vy-report-definition-heading">
                                    <span className={`vy-report-category-icon ${category.tone}`}>
                                        <Icon size={20} />
                                    </span>
                                    <Badge>{report.tabular ? s.detailedReport : s.summaryReport}</Badge>
                                </div>
                                <h3>{report.label}</h3>
                                <p>{category.description}</p>
                                <span className="vy-report-card-bottom">
                                    {s.open}
                                    <ArrowUpRight size={15} />
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
function Explorer({
    report,
    catalogue,
    organisation,
    url,
    navigate,
}: {
    report: ReportDefinition;
    catalogue: Catalogue;
    organisation: number | null;
    url: URL;
    navigate: Navigation;
}) {
    const parameters = parametersFor(report, url.searchParams);
    const page = Math.min(1000000, positiveId(url.searchParams.get('page')) ?? 1);
    const requestedSize = Number(url.searchParams.get('per_page') ?? 20);
    const perPage = [20, 50, 100].includes(requestedSize) ? requestedSize : 20;
    const query = useReport(
        report.key,
        parameters,
        organisation,
        page,
        perPage,
        ready(report, parameters),
        !report.tabular && !report.honours_visibility,
    );
    const [dialog, setDialog] = useState<'export' | 'schedule' | null>(null);
    const [detail, setDetail] = useState<RecordData | null>(null);
    const courseId = positiveId(parameters.course_id);
    const config = useAdminConfig();
    const apply = (values: Parameters) => navigate(reportHref(report.key, values, organisation));
    const course = (id: number) =>
        navigate(
            reportHref(organisation ? 'org-course-completion' : 'course-overview', { course_id: id }, organisation),
        );
    const related = catalogue.reports.filter(
        (item) =>
            item.key !== report.key &&
            (categoryFor(item.key).key === categoryFor(report.key).key ||
                (courseId &&
                    ['courses', 'learners', 'risk', 'assessment'].includes(categoryFor(item.key).key) &&
                    fieldsFor(item).some(([key]) => key === 'course_id'))),
    );
    return (
        <>
            <ReportFilters
                key={`${report.key}:${JSON.stringify(parameters)}`}
                report={report}
                applied={parameters}
                onApply={apply}
            />
            <div className="vy-report-explorer-actions">
                <div>
                    {organisation && (
                        <Badge>
                            {s.organisation} #{organisation}
                        </Badge>
                    )}
                    {courseId && (
                        <a href={workbenchUrl(config, courseId)} className="vy-report-text-link">
                            {s.openCourse}
                            <ArrowUpRight size={14} />
                        </a>
                    )}
                </div>
                <div className="vy-report-actions">
                    {report.exportable && (
                        <>
                            <Button
                                variant="outline"
                                disabled={!ready(report, parameters) || query.isError}
                                onClick={() => setDialog('schedule')}
                            >
                                <CalendarClock size={15} />
                                {s.newSchedule}
                            </Button>
                            <Button
                                disabled={!query.data?.computed || query.isFetching}
                                onClick={() => setDialog('export')}
                            >
                                {s.export}
                            </Button>
                        </>
                    )}
                </div>
            </div>
            {!ready(report, parameters) ? (
                <EmptyState title={s.chooseRequired} />
            ) : query.isError ? (
                <ErrorState error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <Skeleton className="h-64 w-full" />
            ) : query.data.computed === false ? (
                <EmptyState
                    title={s.notComputed}
                    description={typeof query.data.reason === 'string' ? query.data.reason : undefined}
                />
            ) : (
                <>
                    <Facts data={query.data} />
                    {!report.tabular && Number(query.data.suppressed_cells ?? 0) > 0 && (
                        <p className="vy-report-notice">{s.hidden}</p>
                    )}
                    {Array.isArray(query.data.notes) && query.data.notes.length > 0 && (
                        <div className="vy-report-notice">
                            {query.data.notes.map((note, index) => (
                                <p key={index}>{String(note)}</p>
                            ))}
                        </div>
                    )}
                    {(report.tabular || query.data.items.length > 0) && (
                        <ReportTable
                            data={query.data}
                            page={page}
                            perPage={perPage}
                            onPage={(next) => apply({ ...parameters, page: next, per_page: perPage })}
                            onSize={(size) => apply({ ...parameters, per_page: size })}
                            onDetail={setDetail}
                            onCourse={report.key === 'site-learning' ? course : undefined}
                        />
                    )}
                </>
            )}
            {related.length > 0 && (
                <section className="vy-report-panel">
                    <div className="vy-report-panel-heading">
                        <h2>{s.related}</h2>
                    </div>
                    <div className="vy-report-related">
                        {related.map((item) => (
                            <Button
                                key={item.key}
                                variant="outline"
                                onClick={() =>
                                    navigate(
                                        reportHref(
                                            item.key,
                                            courseId && fieldsFor(item).some(([name]) => name === 'course_id')
                                                ? { course_id: courseId }
                                                : {},
                                            organisation,
                                        ),
                                    )
                                }
                            >
                                {item.label}
                                <ArrowRight size={14} />
                            </Button>
                        ))}
                    </div>
                </section>
            )}
            {detail && <RecordDialog row={detail} onClose={() => setDetail(null)} onCourse={course} />}
            {dialog === 'export' && (
                <ExportDialog
                    report={report}
                    catalogue={catalogue}
                    parameters={parameters}
                    organisation={organisation}
                    onClose={() => setDialog(null)}
                    onAccepted={(id) => {
                        setDialog(null);
                        navigate(
                            `/exports/${id}?back=${encodeURIComponent(reportHref(report.key, parameters, organisation))}${organisation ? `&org=${organisation}` : ''}`,
                        );
                    }}
                />
            )}
            {dialog === 'schedule' && (
                <ScheduleDialog
                    catalogue={catalogue}
                    organisation={organisation}
                    report={report}
                    parameters={parameters}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}
function RecordDialog({
    row,
    onClose,
    onCourse,
}: {
    row: RecordData;
    onClose: () => void;
    onCourse: (id: number) => void;
}) {
    const config = useAdminConfig();
    const learner = positiveId(row.user_id ?? row.learner_id);
    const course = positiveId(row.course_id);
    const organisation = positiveId(row.organisation_id);
    const destination = (screen: string, hash: string) =>
        config.urls.screens[screen] ? `${config.urls.screens[screen].split('#')[0]}#${hash}` : null;
    const learnerUrl = learner ? destination('learners', `/learner/${learner}`) : null;
    const orgUrl = organisation ? destination('organisations', `/organisation/${organisation}`) : null;
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="vy-report-dialog vy-report-record-dialog">
                <DialogTitle>{String(row.learner || row.title || row.name || s.record)}</DialogTitle>
                <DialogDescription>{s.record}</DialogDescription>
                <ValueDetails value={row} />
                <div className="vy-report-actions">
                    {learnerUrl && (
                        <a className="vy-report-primary-link" href={learnerUrl}>
                            {s.openLearner}
                            <ArrowUpRight size={14} />
                        </a>
                    )}
                    {course && (
                        <Button
                            variant="outline"
                            onClick={() => {
                                onClose();
                                onCourse(course);
                            }}
                        >
                            {s.courseDrilldown}
                            <ArrowRight size={14} />
                        </Button>
                    )}
                    {orgUrl && (
                        <a className="vy-report-text-link" href={orgUrl}>
                            {s.openOrganisation}
                            <ArrowUpRight size={14} />
                        </a>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
