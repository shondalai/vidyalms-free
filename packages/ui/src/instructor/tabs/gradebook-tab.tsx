import { useEffect, useState } from 'react';
import { DataTable, ErrorState, Pagination, type DataTableColumn } from '@/shared/components';
import type { GradebookItem } from '@vidyalms/api-sdk';
import { useGradebook, useGradeItems, type GradebookParams } from '../api';
import { instructorStrings as strings } from '../strings';
import { deniedFor } from '../lib/problems';
import { waitingFor } from '../lib/format';
import { ListSkeleton, PanelState, PermissionDenied } from '../components/states';
import { FilterChips, ListFooter, PanelHeader } from '../components/table';
import { GradebookQueue } from './gradebook-queue';
import { hasRoute, useInstructorConfig } from '../config';

/*
 * Artboard 3: two views of the same queue, one toggle. The queue is for
 * doing the work, the table for seeing the whole; both read the same route
 * with the same server-side filters, sort and paging, because a page of
 * pending work is never sorted in the browser.
 */

const PER_PAGE = 20;

type View = 'queue' | 'table';
type TypeFilter = 'all' | 'submission' | 'quiz_answer';

export function GradebookTab({ courseId, narrow }: { courseId: number; narrow: boolean }) {
    const config = useInstructorConfig();
    const [view, setView] = useState<View>('queue');
    const [status, setStatus] = useState<'pending' | 'graded'>('pending');
    const [type, setType] = useState<TypeFilter>('all');
    const [sort, setSort] = useState<'waiting_since' | 'graded_at' | 'learner'>('waiting_since');
    const [order, setOrder] = useState<'asc' | 'desc'>('asc');
    const [page, setPage] = useState(1);
    const [index, setIndex] = useState(0);

    const params: GradebookParams = {
        status,
        ...(type === 'all' ? {} : { type }),
        sort,
        order,
        page,
        per_page: PER_PAGE,
    };
    const gradebookQuery = useGradebook(courseId, params);
    const items = gradebookQuery.data?.items ?? [];
    const total = gradebookQuery.data?.total ?? 0;

    /* The one thing this screen may not work out for itself. The course
       total is produced by a registered GradeAggregation provider, so the
       method it uses arrives from the server or the total is not computed at
       all; nothing here adds up a cell. */
    const courseTotal = useGradeItems(courseId, hasRoute(config, '/manage/courses/{id}/gradebook/items')).data?.course_total ?? null;

    /* Grading removes the item under the cursor, so the index has to stay
       inside the page it is pointing at. */
    useEffect(() => {
        setIndex((current) => Math.min(Math.max(0, current), Math.max(0, items.length - 1)));
    }, [items.length]);

    const pages = Math.max(1, Math.ceil(total / PER_PAGE));
    /* The queue sorts by how long a thing has waited; the history by when it
       was graded. Sorting by learner is offered on both. */
    const sortForStatus = status === 'graded' ? 'graded_at' : 'waiting_since';

    const columns: DataTableColumn<GradebookItem>[] = [
        {
            key: 'learner',
            header: strings.gradebook.columns.learner,
            sortKey: 'learner',
            weight: 1.2,
            cell: (item) => item.learner.name ?? strings.gradebook.unknownLearner,
        },
        {
            key: 'item',
            header: strings.gradebook.columns.item,
            weight: 1.8,
            cell: (item) => (
                <span className="text-fg-2">
                    {item.lesson_title}
                    {item.quiz_answer !== null && (
                        <span>
                            {' '}
                            · {strings.gradebook.attemptOf(item.quiz_answer.attempt_number, item.quiz_answer.quiz_title)}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'waiting',
            header: strings.gradebook.columns.waiting,
            sortKey: sortForStatus,
            width: '110px',
            cell: (item) => (
                <span className={item.status === 'pending' ? 'font-semibold' : 'text-fg-2'}>
                    {waitingFor(item.waiting_since)}
                </span>
            ),
        },
        {
            key: 'type',
            header: strings.gradebook.columns.type,
            width: '110px',
            cell: (item) => (
                <span className="text-fg-2">
                    {item.type === 'submission' ? strings.gradebook.assignment : strings.gradebook.quiz}
                </span>
            ),
        },
        {
            key: 'grade',
            header: strings.gradebook.grade,
            hideHeader: true,
            width: '90px',
            align: 'end',
            cell: (item) => (
                <button
                    type="button"
                    onClick={() => {
                        setIndex(items.findIndex((candidate) => candidate.key === item.key));
                        setView('queue');
                    }}
                    className="h-7 cursor-pointer rounded border border-border bg-surface px-2.5 font-display text-[11px] font-semibold text-fg"
                >
                    <span className="sr-only">
                        {strings.gradebook.gradeNamed(item.learner.name ?? strings.gradebook.unknownLearner)}
                    </span>
                    <span aria-hidden="true">{strings.gradebook.grade}</span>
                </button>
            ),
        },
    ];

    const goToIndex = (next: number) => {
        if (next < 0) {
            if (page > 1) {
                setPage(page - 1);
                setIndex(PER_PAGE - 1);
            }
            return;
        }
        if (next >= items.length) {
            if (page < pages) {
                setPage(page + 1);
                setIndex(0);
            }
            return;
        }
        setIndex(next);
    };

    const header = (
        <PanelHeader
            title={strings.gradebook.heading}
            meta={
                gradebookQuery.data === undefined
                    ? undefined
                    : strings.gradebook.pendingAndGraded(
                          gradebookQuery.data.pending_total,
                          gradebookQuery.data.graded_total,
                      )
            }
            actions={
                <div
                    role="group"
                    aria-label={strings.gradebook.viewLabel}
                    className="flex overflow-hidden rounded border border-border font-display text-[11.5px] font-semibold"
                >
                    {(['queue', 'table'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-pressed={view === option}
                            onClick={() => setView(option)}
                            className={
                                view === option
                                    ? 'cursor-pointer bg-fg px-3 py-1.5 text-surface'
                                    : 'cursor-pointer px-3 py-1.5 text-fg-2 hover:text-fg'
                            }
                        >
                            {option === 'queue' ? strings.gradebook.queue : strings.gradebook.table}
                        </button>
                    ))}
                </div>
            }
        />
    );

    return (
        <section className="rounded-lg border border-border bg-surface" aria-label={strings.gradebook.heading}>
            {header}

            <div className="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3">
                <FilterChips
                    label={strings.gradebook.filters.statusLabel}
                    current={status}
                    onSelect={(next) => {
                        /* The contract's own defaults: oldest first in the
                           queue so nothing ages out unseen, newest first in
                           the history. */
                        const graded = next === 'graded';
                        setStatus(graded ? 'graded' : 'pending');
                        setSort(graded ? 'graded_at' : 'waiting_since');
                        setOrder(graded ? 'desc' : 'asc');
                        setPage(1);
                        setIndex(0);
                    }}
                    options={[
                        {
                            id: 'pending',
                            label: strings.gradebook.filters.pending(gradebookQuery.data?.pending_total ?? 0),
                        },
                        {
                            id: 'graded',
                            label: strings.gradebook.filters.graded(gradebookQuery.data?.graded_total ?? 0),
                        },
                    ]}
                />
                <FilterChips
                    label={strings.gradebook.filters.typeLabel}
                    current={type}
                    onSelect={(next) => {
                        setType(next === 'submission' ? 'submission' : next === 'quiz_answer' ? 'quiz_answer' : 'all');
                        setPage(1);
                        setIndex(0);
                    }}
                    options={[
                        { id: 'all', label: strings.gradebook.filters.all },
                        { id: 'submission', label: strings.gradebook.filters.submissions },
                        { id: 'quiz_answer', label: strings.gradebook.filters.quizAnswers },
                    ]}
                />
                {courseTotal !== null && (
                    <span className="text-[11.5px] text-fg-2">
                        {courseTotal.aggregation_available
                            ? strings.gradebook.aggregation.by(
                                  courseTotal.aggregation_label ?? courseTotal.aggregation_key,
                              )
                            : strings.gradebook.aggregation.missing(courseTotal.aggregation_key)}
                    </span>
                )}
            </div>

            {gradebookQuery.isPending ? (
                <ListSkeleton label={strings.states.loading} />
            ) : gradebookQuery.isError ? (
                <div className="p-4">
                    {deniedFor(gradebookQuery.error) ? (
                        <PermissionDenied />
                    ) : (
                        <ErrorState
                            error={gradebookQuery.error}
                            title={strings.gradebook.error.title}
                            onRetry={() => void gradebookQuery.refetch()}
                        />
                    )}
                </div>
            ) : items.length === 0 ? (
                status === 'pending' ? (
                    <PanelState
                        glyph="✓"
                        tone="ok"
                        role="status"
                        title={strings.gradebook.empty.title}
                        body={strings.gradebook.empty.body}
                    />
                ) : (
                    <PanelState
                        glyph="◇"
                        role="status"
                        title={strings.gradebook.emptyGraded.title}
                        body={strings.gradebook.emptyGraded.body}
                    />
                )
            ) : view === 'queue' ? (
                <GradebookQueue
                    courseId={courseId}
                    items={items}
                    offset={(page - 1) * PER_PAGE}
                    total={total}
                    index={index}
                    onIndex={goToIndex}
                    onGraded={() => void gradebookQuery.refetch()}
                    narrow={narrow}
                />
            ) : (
                <>
                    <DataTable
                        caption={strings.gradebook.heading}
                        columns={columns}
                        rows={items}
                        rowKey={(item) => item.key}
                        sort={{ key: sort, order }}
                        onSortChange={(next) => {
                            setSort(next.key === 'learner' ? 'learner' : sortForStatus);
                            setOrder(next.order);
                            setPage(1);
                        }}
                    />
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
                </>
            )}
        </section>
    );
}
