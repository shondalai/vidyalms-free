import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { ArrowUpRight } from 'lucide-react';
import { studioStrings as studio } from '@/instructor/studio-strings';
import { CourseCards } from '../components/course-cards';
import { CourseArtwork } from '../components/course-artwork';
import { CourseLibraryControls } from '../components/course-library-controls';
import { ResourceLifecycleActions } from '../components/resource-lifecycle-actions';
import {
    Badge,
    Icon,
    Button,
    DataTable,
    EmptyState,
    ProgressBar,
    buttonVariants,
    type DataTableColumn,
} from '@/shared/components';
import { useCourseStateCount, useManagedCourses, type CourseListParams, type ManagedCourse } from '../api';
import { useAdminConfig, workbenchUrl } from '../config';
import { useDebounced, useListState } from '../hooks';
import { formatDate, instructorNames, roundPercent } from '../lib';
import { adminStrings } from '../strings';
import { ListFrame } from '../components/list-frame';
import { NewCourseDialog } from '../components/new-course-dialog';
import { AdminNotice, ScreenError, WelcomeState } from '../components/states';

/* Server-filtered course library; list and card views share the same actions. */

const PER_PAGE = 20;
const strings = adminStrings.courses;

function ruleText(course: ManagedCourse): string {
    if (course.completion_rule === 'percentage') {
        return strings.rules.percentage(course.completion_threshold ?? 0);
    }
    return course.completion_rule === 'pass_final' ? strings.rules.pass_final : strings.rules.all_lessons;
}

export function CoursesScreen() {
    const config = useAdminConfig();
    const [view, setView] = useState<'list' | 'grid'>(() => {
        try {
            return localStorage.getItem('vidyalms-course-view') === 'grid' ? 'grid' : 'list';
        } catch {
            return 'list';
        }
    });
    useEffect(() => {
        try {
            localStorage.setItem('vidyalms-course-view', view);
        } catch {
            /* Storage is optional. */
        }
    }, [view]);
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const list = useListState(
        'all',
        { key: 'title', order: 'asc' },
        {
            persistence: 'hash-query',
            allowedFilters: ['all', 'published', 'draft', 'archived', 'trashed'],
            allowedSortKeys: [
                'title',
                'state',
                'enrolled_count',
                'completion_rate',
                'updated_at',
                'published_at',
            ],
        },
    );
    const search = useDebounced(list.search).trim();
    const [dialogOpen, setDialogOpen] = useState(false);
    const [created, setCreated] = useState<{ id: number; title: string } | null>(null);
    const [notice, setNotice] = useState('');
    const onChanged = (message: string) => {
        setNotice(message);
        list.setPage(1);
    };

    const params: CourseListParams = {
        category_id: categoryId ?? undefined,
        /* The sortable columns below are declared with the contract's own
           sort values, so the active key is always one of them. */
        state: list.filter === 'all' ? undefined : (list.filter as CourseListParams['state']),
        q: search === '' ? undefined : search,
        sort: list.sort.key as CourseListParams['sort'],
        order: list.sort.order,
        page: list.page,
        per_page: PER_PAGE,
    };
    const courses = useManagedCourses(params);
    const published = useCourseStateCount('published');
    const draft = useCourseStateCount('draft');
    const archived = useCourseStateCount('archived');
    const trashed = useCourseStateCount('trashed');

    const items = courses.data?.items ?? [];
    const total = courses.data?.total ?? 0;
    const filtered = list.filter !== 'all' || search !== '' || categoryId !== null;
    const freshInstall = !filtered && courses.isSuccess && total === 0;

    const columns: DataTableColumn<ManagedCourse>[] = [
        {
            key: 'title',
            header: strings.columns.course,
            sortKey: 'title',
            weight: 1.7,
            minWidth: 350,
            cell: (course) => (
                <div className="vy-course-identity">
                    <CourseArtwork course={course} />
                    <div className="vy-course-identity-copy">
                        <a className="vy-course-title" href={course.state === 'trashed' ? undefined : workbenchUrl(config, course.id)}>
                            {course.title}
                        </a>
                        {course.summary && <p className="vy-course-summary">{course.summary}</p>}
                        <span className="vy-course-metadata">
                            {uiText("vidyalms.ui.ae04d0e6f63d1e51", "{p0} · {p1}", { p0: strings.meta(instructorNames(course.instructors), course.lesson_count), p1: ruleText(course) })}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'state',
            header: strings.columns.state,
            sortKey: 'state',
            width: '125px',
            cell: (course) =>
                course.state === 'published' ? (
                    <Badge variant="ok">{strings.states.published}</Badge>
                ) : (
                    <Badge variant="neutral" glyph={null}>
                        {strings.states[course.state] ?? course.state}
                    </Badge>
                ),
        },
        {
            key: 'enrolled',
            header: strings.columns.enrolled,
            sortKey: 'enrolled_count',
            width: '110px',
            cell: (course) => <strong className="vy-course-number">{course.enrolled_count.toLocaleString(config.locale)}</strong>,
        },
        {
            key: 'completion',
            header: strings.columns.completionRate,
            sortKey: 'completion_rate',
            width: '170px',
            cell: (course) => {
                const rate = roundPercent(course.completion_rate);
                if (rate === null) {
                    return <span className="text-fg-2" aria-label={strings.noEnrolments}>—</span>;
                }
                return (
                    <div className="vy-course-completion">
                        <strong>{rate}%</strong>
                        <ProgressBar
                            value={rate}
                            label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.columns.completionRate, p1: course.title })}
                        />
                    </div>
                );
            },
        },
        {
            key: 'updated',
            header: uiText('vidyalms.ui.3a5ecca188c0579c', 'Updated'),
            sortKey: 'updated_at',
            width: '125px',
            cell: (course) => <span className="vy-course-updated">{formatDate(course.updated_at, config.locale) ?? '—'}</span>,
        },
        {
            key: 'actions',
            header: adminStrings.list.actions,
            hideHeader: true,
            width: '150px',
            align: 'end',
            cell: (course) => (
                <div className="flex items-center justify-end gap-1">
                {course.state !== 'trashed' && <a
                    className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                    href={workbenchUrl(config, course.id)}
                    aria-label={strings.openNamed(course.title)}
                    title={strings.openNamed(course.title)}
                >
                    <Icon icon={ArrowUpRight} size={18} />
                </a>}
                <ResourceLifecycleActions kind="course" id={course.id} name={course.title} state={course.state} onChanged={onChanged} />
                </div>
            ),
        },
    ];

    const newCourseButton = (
        <Button size="sm" onClick={() => setDialogOpen(true)}>
            <span aria-hidden="true">+</span>
            {strings.newCourse}
        </Button>
    );

    return (
        <div className="vy-course-library">
            <header className="vy-library-heading">
                <div>
                    <p className="vy-studio-eyebrow">{studio.library}</p>
                    <h1>{studio.libraryTitle}</h1>
                    <p>{studio.libraryIntro}</p>
                </div>
                {newCourseButton}
            </header>
            <div className="vy-library-metrics">
                {(
                    [
                        [
                            'all',
                            studio.allCourses,
                            published.data === undefined ||
                            draft.data === undefined ||
                            archived.data === undefined
                                ? undefined
                                : published.data + draft.data + archived.data,
                        ],
                        ['published', studio.published, published.data],
                        ['draft', studio.drafts, draft.data],
                        ['archived', studio.archived, archived.data],
                    ] as const
                ).map(([key, label, count]) => (
                    <button
                        key={key}
                        type="button"
                        aria-pressed={list.filter === key}
                        onClick={() => list.setFilter(key)}
                    >
                        <span>{label}</span>
                        <strong>{count === undefined ? '—' : count.toLocaleString()}</strong>
                    </button>
                ))}
            </div>
            {notice && <AdminNotice role="status" glyph="✓" title={notice} />}
            <ListFrame
                title={list.filter === 'all' ? studio.allCourses : strings.states[list.filter] ?? studio.allCourses}
                meta={courses.isSuccess ? adminStrings.list.total(total) : undefined}
                banner={
                    created === null ? undefined : (
                        <AdminNotice
                            role="status"
                            glyph="✓"
                            title={strings.created(created.title)}
                            action={
                                <>
                                    <a
                                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                                        href={workbenchUrl(config, created.id)}
                                    >
                                        {strings.open}
                                    </a>
                                    <Button variant="ghost" size="sm" onClick={() => setCreated(null)}>
                                        {strings.dismiss}
                                    </Button>
                                </>
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
                <CourseLibraryControls
                    search={list.search} onSearch={list.setSearch}
                    filter={list.filter} onFilter={list.setFilter}
                    chips={[
                        { value: 'all', label: adminStrings.list.all },
                        { value: 'published', label: strings.chips.published, count: published.data },
                        { value: 'draft', label: strings.chips.draft, count: draft.data },
                        { value: 'archived', label: strings.chips.archived, count: archived.data },
                        { value: 'trashed', label: strings.chips.trashed, count: trashed.data },
                    ]}
                    categoryId={categoryId} onCategory={(id) => {
                        setCategoryId(id);
                        list.setPage(1);
                    }}
                    sort={list.sort} onSort={list.setSort} view={view} onView={setView}
                    fetching={courses.isFetching && !courses.isPending}
                    onReset={() => {
                        list.setSearch('');
                        list.setFilter('all');
                        setCategoryId(null);
                        list.setPage(1);
                    }}
                />
                <div aria-busy={courses.isFetching}>
                    {courses.isError ? (
                        <div className="p-4">
                            <ScreenError
                                error={courses.error}
                                onRetry={() => void courses.refetch()}
                                forbidden={
                                    <AdminNotice
                                        glyph="◇"
                                        title={adminStrings.permission.noneTitle}
                                        body={adminStrings.permission.noneBody}
                                    />
                                }
                            />
                        </div>
                    ) : view === 'grid' && items.length > 0 ? (
                        <CourseCards courses={items} onChanged={onChanged} />
                    ) : (
                        <DataTable
                            className="vy-course-table"
                            caption={strings.table}
                            columns={columns}
                            rows={items}
                            rowKey={(course) => course.id}
                            sort={list.sort}
                            onSortChange={list.setSort}
                            loading={courses.isPending}
                            empty={
                                freshInstall ? (
                                    <WelcomeState action={newCourseButton} />
                                ) : (
                                    <EmptyState title={strings.empty} />
                                )
                            }
                        />
                    )}
                </div>
            </ListFrame>
            <NewCourseDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                onCreated={(course) => {
                    setCreated({ id: course.id, title: course.title });
                }}
            />
        </div>
    );
}
