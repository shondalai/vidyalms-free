import { useEffect, useState } from 'react';
import type { ManagedCourse } from '@vidyalms/api-sdk';
import {
    buttonVariants,
    DataTable,
    EmptyState,
    ErrorState,
    Pagination,
    type DataTableColumn,
} from '@/shared/components';
import { useManagedCourses, type CourseListParams } from '../api';
import { fillUrlTemplate, useInstructorConfig } from '../config';
import { instructorStrings as strings } from '../strings';
import { deniedFor } from '../lib/problems';
import { wholePercent } from '../lib/format';
import { StateText } from '../components/chips';
import { PermissionDenied } from '../components/states';
import { ListFooter, PanelHeader } from '../components/table';
import { Field, Select, TextInput } from '../components/field';

/*
 * What the mount renders when its config carries no course: the courses the
 * caller may act on, each linking into its own workbench through the host's
 * URL template. A caller holding manage sees every course; a caller holding
 * only teach sees the ones they are an instructor of, which is the API's
 * decision, not this screen's.
 */

const PER_PAGE = 20;

type SortKey = 'title' | 'enrolled_count' | 'updated_at';

export function CourseListScreen({ narrow }: { narrow: boolean }) {
    const config = useInstructorConfig();
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [state, setState] = useState<'' | 'draft' | 'published' | 'archived'>('');
    const [sort, setSort] = useState<SortKey>('title');
    const [order, setOrder] = useState<'asc' | 'desc'>('asc');
    const [page, setPage] = useState(1);
    useEffect(() => {
        const timer = window.setTimeout(() => setQuery(search.trim()), 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    const params: CourseListParams = {
        ...(query === '' ? {} : { q: query }),
        ...(state === '' ? {} : { state }),
        sort,
        order,
        page,
        per_page: PER_PAGE,
    };
    const coursesQuery = useManagedCourses(params);
    const items = coursesQuery.data?.items ?? [];
    const total = coursesQuery.data?.total ?? 0;
    const filtered = search.trim() !== '' || state !== '';

    const columns: DataTableColumn<ManagedCourse>[] = [
        {
            key: 'title',
            header: strings.courses.columns.course,
            sortKey: 'title',
            weight: 2,
            cell: (course) => <span className="font-medium">{course.title}</span>,
        },
        {
            key: 'state',
            header: strings.courses.columns.state,
            width: '110px',
            cell: (course) =>
                course.state === 'archived' ? (
                    <span className="text-[11px] font-semibold text-fg-2">{strings.header.state.archived}</span>
                ) : (
                    <StateText state={course.state === 'published' ? 'published' : 'draft'} />
                ),
        },
        ...(narrow
            ? []
            : [
                  {
                      key: 'lessons',
                      header: strings.courses.columns.lessons,
                      width: '100px' as const,
                      cell: (course: ManagedCourse) => <span className="text-fg-2">{course.lesson_count}</span>,
                  },
              ]),
        {
            key: 'enrolled',
            header: strings.courses.columns.enrolled,
            sortKey: 'enrolled_count',
            width: '110px',
            cell: (course) => <span className="text-fg-2">{course.enrolled_count}</span>,
        },
        ...(narrow
            ? []
            : [
                  {
                      key: 'completion',
                      header: strings.courses.columns.completion,
                      width: '150px' as const,
                      cell: (course: ManagedCourse) => (
                          <span className="text-fg-2">
                              {course.completion_rate === null
                                  ? strings.courses.noCompletionRate
                                  : strings.courses.completionRate(wholePercent(course.completion_rate))}
                          </span>
                      ),
                  },
              ]),
        {
            key: 'open',
            header: strings.courses.open,
            hideHeader: true,
            width: '90px',
            align: 'end',
            cell: (course) => (
                <a
                    className={buttonVariants({ variant: 'outline', size: 'sm' })}
                    href={fillUrlTemplate(config.urls.workbench, { course_id: course.id })}
                >
                    <span className="sr-only">{strings.courses.openNamed(course.title)}</span>
                    <span aria-hidden="true">{strings.courses.open}</span>
                </a>
            ),
        },
    ];

    return (
        <>
            <PanelHeader
                level="h2"
                title={strings.courses.title}
                actions={
                    <>
                        <Field className="w-[180px]" label={strings.courses.search} hideLabel>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    type="search"
                                    className="h-[34px]"
                                    placeholder={strings.courses.searchPlaceholder}
                                    aria-label={strings.courses.search}
                                    value={search}
                                    onChange={(event) => {
                                        setSearch(event.target.value);
                                        setPage(1);
                                    }}
                                />
                            )}
                        </Field>
                        <Field className="w-[150px]" label={strings.courses.filterState} hideLabel>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    className="h-[34px]"
                                    aria-label={strings.courses.filterState}
                                    value={state}
                                    onChange={(event) => {
                                        const next = event.target.value;
                                        setState(
                                            next === 'draft' || next === 'published' || next === 'archived'
                                                ? next
                                                : '',
                                        );
                                        setPage(1);
                                    }}
                                >
                                    <option value="">{strings.courses.anyState}</option>
                                    <option value="published">{strings.header.state.published}</option>
                                    <option value="draft">{strings.header.state.draft}</option>
                                    <option value="archived">{strings.header.state.archived}</option>
                                </Select>
                            )}
                        </Field>
                    </>
                }
            />

            {coursesQuery.isError ? (
                <div className="p-4">
                    {deniedFor(coursesQuery.error) ? (
                        <PermissionDenied title={strings.courses.denied.title} body={strings.courses.denied.body} />
                    ) : (
                        <ErrorState
                            error={coursesQuery.error}
                            title={strings.courses.error.title}
                            onRetry={() => void coursesQuery.refetch()}
                        />
                    )}
                </div>
            ) : (
                <>
                    <DataTable
                        caption={strings.courses.title}
                        columns={columns}
                        rows={items}
                        rowKey={(course) => course.id}
                        loading={coursesQuery.isPending}
                        sort={{ key: sort, order }}
                        onSortChange={(next) => {
                            setSort(next.key === 'enrolled_count' ? 'enrolled_count' : 'title');
                            setOrder(next.order);
                            setPage(1);
                        }}
                        empty={
                            <EmptyState
                                title={
                                    filtered ? strings.courses.emptyFiltered.title : strings.courses.empty.title
                                }
                                description={
                                    filtered ? strings.courses.emptyFiltered.body : strings.courses.empty.body
                                }
                            />
                        }
                    />
                    {total > 0 && (
                        <ListFooter
                            range={strings.pagination.range(
                                (page - 1) * PER_PAGE + 1,
                                Math.min(total, page * PER_PAGE),
                                total,
                            )}
                        >
                            <Pagination
                                label={strings.pagination.label}
                                page={page}
                                perPage={PER_PAGE}
                                total={total}
                                onPageChange={setPage}
                            />
                        </ListFooter>
                    )}
                </>
            )}
        </>
    );
}
