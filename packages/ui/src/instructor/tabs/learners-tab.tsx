import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Plus } from 'lucide-react';
import type { EnrolmentRecord } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import {
    Badge,
    Button,
    DataTable,
    EmptyState,
    ErrorState,
    Icon,
    Pagination,
    type DataTableColumn,
} from '@/shared/components';
import { useCourseLearners, type LearnerParams } from '../api';
import { instructorStrings as strings } from '../strings';
import { deniedFor } from '../lib/problems';
import { shortDate, wholePercent } from '../lib/format';
import { PermissionDenied } from '../components/states';
import { ListFooter, PanelHeader } from '../components/table';
import { Field, Select, TextInput } from '../components/field';
import { EnrolDialog } from './enrol-dialog';
import { ExtendDialog } from './extend-dialog';
import { hasRoute, useInstructorConfig } from '../config';

/*
 * Artboard 4a: the roster of one course. A suspended row keeps its progress
 * visible, because expiry suspends an enrolment and never deletes what the
 * learner did; the row's action is Re-activate, which extends the
 * entitlement and resumes the enrolment with everything intact.
 *
 * Search, filter, sort and paging are all query parameters. The core
 * resolves names through the users port over the roster before it cuts the
 * page, so sorting by name is still a server-side sort.
 */

const PER_PAGE = 25;

const SOURCE_LABELS: Record<string, string> = strings.learners.sourceLabel;

function statusVariant(status: EnrolmentRecord['status']): 'ok' | 'neutral' | 'warn' {
    switch (status) {
        case 'active':
        case 'completed':
            return 'ok';
        case 'suspended':
            return 'neutral';
        default:
            return 'warn';
    }
}

export function LearnersTab({ courseId, narrow }: { courseId: number; narrow: boolean }) {
    const canFindAccounts = hasRoute(useInstructorConfig(), '/admin/users');
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<'' | 'active' | 'suspended' | 'completed' | 'cancelled'>('');
    const [sort, setSort] = useState<'name' | 'progress' | 'last_active'>('name');
    const [order, setOrder] = useState<'asc' | 'desc'>('asc');
    const [page, setPage] = useState(1);
    const [enrolOpen, setEnrolOpen] = useState(false);
    const [extendFor, setExtendFor] = useState<EnrolmentRecord | null>(null);

    const params: LearnerParams = {
        ...(query.trim() === '' ? {} : { q: query.trim() }),
        ...(status === '' ? {} : { status }),
        sort,
        order,
        page,
        per_page: PER_PAGE,
    };
    const learnersQuery = useCourseLearners(courseId, params);
    const items = learnersQuery.data?.items ?? [];
    const total = learnersQuery.data?.total ?? 0;

    const filtered = query.trim() !== '' || status !== '';

    const columns: DataTableColumn<EnrolmentRecord>[] = [
        {
            key: 'learner',
            header: strings.learners.columns.learner,
            sortKey: 'name',
            weight: 1.4,
            cell: (item) => (
                <>
                    {item.user.name ?? strings.gradebook.unknownLearner}
                    {item.status === 'suspended' && item.entitlement.expires_at !== null && (
                        <span className="ml-1.5 text-[10.5px] text-fg-2">
                            · {strings.learners.expired(shortDate(item.entitlement.expires_at))}
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'progress',
            header: strings.learners.columns.progress,
            sortKey: 'progress',
            weight: 1,
            cell: (item) => <ProgressCell item={item} />,
        },
        ...(narrow
            ? []
            : [
                  {
                      key: 'last_active',
                      header: strings.learners.columns.lastActive,
                      sortKey: 'last_active',
                      width: '120px' as const,
                      cell: (item: EnrolmentRecord) => (
                          <span className="text-fg-2">
                              {item.last_activity_at === null
                                  ? strings.learners.never
                                  : shortDate(item.last_activity_at)}
                          </span>
                      ),
                  },
              ]),
        {
            key: 'state',
            header: strings.learners.columns.state,
            width: '120px',
            cell: (item) => <Badge variant={statusVariant(item.status)}>{strings.learners.status[item.status]}</Badge>,
        },
        {
            key: 'source',
            header: strings.learners.columns.source,
            width: '110px',
            /* A suspended row spends this column on the way back in: the
               design puts Re-activate exactly where the source would be. */
            cell: (item) =>
                item.status === 'suspended' ? (
                    <button
                        type="button"
                        onClick={() => setExtendFor(item)}
                        className="h-7 cursor-pointer rounded border border-border bg-surface px-2.5 font-display text-[11px] font-semibold text-fg"
                    >
                        <span className="sr-only">
                            {strings.learners.reactivateNamed(item.user.name ?? strings.gradebook.unknownLearner)}
                        </span>
                        <span aria-hidden="true">{strings.learners.reactivate}</span>
                    </button>
                ) : (
                    <span className="text-fg-2">
                        {SOURCE_LABELS[item.entitlement.source] ?? item.entitlement.source}
                    </span>
                ),
        },
    ];

    return (
        <section className="rounded-lg border border-border bg-surface" aria-label={strings.learners.heading}>
            <PanelHeader
                title={strings.learners.heading}
                /* The count is of the current filter, so it only claims to be
                   the enrolment total while nothing is filtering. */
                meta={
                    learnersQuery.data === undefined
                        ? undefined
                        : query.trim() === '' && status === ''
                          ? strings.header.enrolled(total)
                          : strings.learners.matching(total)
                }
                actions={
                    <>
                        <Field className="w-[180px]" label={strings.learners.search} hideLabel>
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    type="search"
                                    className="h-[34px]"
                                    placeholder={strings.learners.searchPlaceholder}
                                    aria-label={strings.learners.search}
                                    value={query}
                                    onChange={(event) => {
                                        setQuery(event.target.value);
                                        setPage(1);
                                    }}
                                />
                            )}
                        </Field>
                        <Field className="w-[150px]" label={strings.learners.filterStatus} hideLabel>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    className="h-[34px]"
                                    aria-label={strings.learners.filterStatus}
                                    value={status}
                                    onChange={(event) => {
                                        const next = event.target.value;
                                        setStatus(
                                            next === 'active' ||
                                                next === 'suspended' ||
                                                next === 'completed' ||
                                                next === 'cancelled'
                                                ? next
                                                : '',
                                        );
                                        setPage(1);
                                    }}
                                >
                                    <option value="">{strings.learners.anyStatus}</option>
                                    <option value="active">{strings.learners.status.active}</option>
                                    <option value="suspended">{strings.learners.status.suspended}</option>
                                    <option value="completed">{strings.learners.status.completed}</option>
                                    <option value="cancelled">{strings.learners.status.cancelled}</option>
                                </Select>
                            )}
                        </Field>
                        {canFindAccounts && <Button size="sm" onClick={() => setEnrolOpen(true)}>
                            <Icon icon={Plus} size={13} />
                            {strings.learners.enrol}
                        </Button>}
                    </>
                }
            />

            {learnersQuery.isError ? (
                <div className="p-4">
                    {deniedFor(learnersQuery.error) ? (
                        <PermissionDenied />
                    ) : (
                        <>
                            <ErrorState
                                error={learnersQuery.error}
                                title={strings.learners.error.title}
                                onRetry={() => void learnersQuery.refetch()}
                            />
                            {/* Artboard 4b says this out loud: a roster that
                                will not load is not a roster that was lost. */}
                            <p className="mt-2 text-[12.5px] text-fg-2">{strings.learners.error.body}</p>
                        </>
                    )}
                </div>
            ) : (
                <>
                    <DataTable
                        caption={strings.learners.heading}
                        columns={columns}
                        rows={items}
                        rowKey={(item) => item.id}
                        loading={learnersQuery.isPending}
                        sort={{ key: sort, order }}
                        onSortChange={(next) => {
                            setSort(
                                next.key === 'progress'
                                    ? 'progress'
                                    : next.key === 'last_active'
                                      ? 'last_active'
                                      : 'name',
                            );
                            setOrder(next.order);
                            setPage(1);
                        }}
                        empty={
                            filtered ? (
                                <EmptyState
                                    title={strings.learners.emptyFiltered.title}
                                    description={strings.learners.emptyFiltered.body}
                                />
                            ) : (
                                <EmptyState
                                    title={strings.learners.empty.title}
                                    description={strings.learners.empty.body}
                                    action={canFindAccounts ? (
                                        <Button size="sm" onClick={() => setEnrolOpen(true)}>
                                            {strings.learners.empty.action}
                                        </Button>
                                    ) : undefined}
                                />
                            )
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

            {canFindAccounts && enrolOpen && <EnrolDialog courseId={courseId} onClose={() => setEnrolOpen(false)} />}
            {extendFor !== null && (
                <ExtendDialog courseId={courseId} enrolment={extendFor} onClose={() => setExtendFor(null)} />
            )}
        </section>
    );
}

/**
 * The progress cell. A suspended learner keeps the progress they had, shown
 * in a muted fill and labelled "kept", because expiry suspends an enrolment
 * and never deletes what the learner did.
 */
function ProgressCell({ item }: { item: EnrolmentRecord }) {
    const suspended = item.status === 'suspended';
    const percent = wholePercent(item.progress_percent);
    const name = item.user.name ?? strings.gradebook.unknownLearner;
    return (
        <span className="flex items-center gap-2">
            <span
                role="progressbar"
                aria-label={strings.learners.progressLabel(name)}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                className="h-[5px] w-[90px] overflow-hidden rounded-full bg-surface-2"
            >
                <span
                    className={cn('block h-full rounded-full', suspended ? 'bg-fg-2/50' : 'bg-accent')}
                    style={{ width: `${percent}%` }}
                />
            </span>
            <span className={suspended ? 'text-fg-2' : undefined}>
                {suspended ? strings.learners.progressKept(percent) : uiText("vidyalms.ui.d4bdf581de91640f", "{p0}%", { p0: percent })}
            </span>
        </span>
    );
}
