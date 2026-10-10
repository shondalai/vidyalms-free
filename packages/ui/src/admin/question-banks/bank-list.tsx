import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import {
    Database,
    FolderTree,
    FolderOpen,
    HelpCircle,
    Pencil,
    Plus,
    ArrowUpRight,
    Shapes,
} from 'lucide-react';
import { ProblemError, type QuestionBank } from '@vidyalms/api-sdk';
import {
    Button,
    DataTable,
    Badge,
    Icon,
    Spinner,
    type DataTableColumn,
    type DataTableSort,
} from '@/shared/components';
import {
    useQuestionBanks,
    type QuestionBankListParams,
    type QuestionBankState,
} from '@/instructor/assessment-api';
import type { OwnerScope } from '@/instructor/lib/assessment-types';
import { useCourseCategory, useManagedCourse } from '@/shared/manage';
import { useDebounced } from '../hooks';
import { useQuestionTypeCatalogue } from '@/question-types';
import { BankSummary, BankEmpty } from './workspace';
import { BankFilter as FilterSelect, BankCollection as ListFrame } from './workspace';
import { pickerEntries } from '@/instructor/lib/question-picker';
import { PageHeader } from '../components/page-header';
import { ScreenError } from '../components/states';
import { bankStrings as strings } from './strings';
import { questionBankHash } from './routes';
import { OwnerPicker } from './owner-picker';
import { ResourceLifecycleActions } from '../components/resource-lifecycle-actions';
import { AdminNotice } from '../components/states';

const PER_PAGE = 20;
const STATES = new Set(['active', 'archived', 'trashed']);
const OWNERS = new Set(['site', 'category', 'course', 'quiz']);
const SORTS = new Set(['name', 'updated_at', 'created_at', 'question_count']);

interface BankListValues {
    q: string;
    state: QuestionBankState | '';
    owner: OwnerScope | '';
    ownerId: number | null;
    sort: NonNullable<QuestionBankListParams['sort']>;
    order: 'asc' | 'desc';
    page: number;
    perPage: number;
}

function positive(raw: string | null): number | null {
    return raw !== null && /^[1-9]\d*$/.test(raw) && Number.isSafeInteger(Number(raw)) ? Number(raw) : null;
}

function pageOf(query: URLSearchParams): number {
    const raw = query.get('page');
    return raw !== null && /^[1-9]\d*$/.test(raw) && Number.isSafeInteger(Number(raw)) ? Number(raw) : 1;
}

function valuesOf(query: URLSearchParams): BankListValues {
    const state = query.get('state');
    const owner = query.get('owner_scope');
    const sort = query.get('sort');
    const order = query.get('order');
    return {
        q: query.get('q') ?? '',
        state: STATES.has(state ?? '') ? (state as QuestionBankState) : 'active',
        owner: OWNERS.has(owner ?? '') ? (owner as OwnerScope) : '',
        ownerId: positive(query.get('owner_id')),
        sort: SORTS.has(sort ?? '') ? (sort as NonNullable<QuestionBankListParams['sort']>) : 'updated_at',
        order: order === 'asc' || order === 'desc' ? order : 'desc',
        page: pageOf(query),
        perPage: [20, 50, 100].includes(Number(query.get('per_page')))
            ? Number(query.get('per_page'))
            : PER_PAGE,
    };
}

function BankOwnerLabel({ bank }: { bank: QuestionBank }) {
    const course = useManagedCourse(bank.owner_scope === 'course' ? bank.owner_id : null);
    const category = useCourseCategory(bank.owner_scope === 'category' ? bank.owner_id : null);
    if (bank.owner_scope === 'site') {
        return <>{strings.workspace.siteScope}</>;
    }
    if (bank.owner_scope === 'course') {
        return <>{course.data?.title ?? uiText("vidyalms.ui.58b459f707db26de", "Course-owned bank")}</>;
    }
    if (bank.owner_scope === 'category') {
        return <>{category.data?.label ?? uiText("vidyalms.ui.eff282578ee89d39", "Course category-owned bank")}</>;
    }
    return <>{uiText("vidyalms.ui.967e4c3b25d1124a", "Quiz-owned bank")}</>;
}

function updatedLabel(value: string | null): string {
    if (value === null || value === '') {
        return uiText("vidyalms.ui.048a985e61a37f65", "Not updated yet");
    }
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

export function QuestionBankList({
    query,
    navigate,
    replace,
}: {
    query: URLSearchParams;
    navigate: (hash: string) => void;
    replace: (hash: string) => void;
}) {
    const values = valuesOf(query);
    const [notice, setNotice] = useState('');
    const search = useDebounced(values.q).trim();
    const requestOwner =
        values.owner === 'site'
            ? 'site'
            : values.owner !== '' && values.ownerId !== null
              ? values.owner
              : undefined;
    const params: QuestionBankListParams = {
        q: search || undefined,
        state: values.state || undefined,
        /* Course/category/quiz ownership is a pair. Preserve the scope in
           the hash while its picker is open, but do not send half a pair
           that BankOwnerInput must reject. */
        owner_scope: requestOwner,
        owner_id:
            requestOwner !== undefined && requestOwner !== 'site' ? (values.ownerId ?? undefined) : undefined,
        sort: values.sort,
        order: values.order,
        page: values.page,
        per_page: values.perPage,
    };
    const banks = useQuestionBanks(params);
    const catalogue = useQuestionTypeCatalogue();
    const filtered = values.q.trim() !== '' || values.state !== 'active' || values.owner !== '';
    const items = banks.data?.items ?? [];
    const total = banks.data?.total ?? 0;
    const needsTeacherScope =
        banks.isError &&
        banks.error instanceof ProblemError &&
        banks.error.status === 403 &&
        values.owner === '';

    const route = (changes: Partial<typeof values>, replaceHistory = false) => {
        const next = { ...values, ...changes };
        const hash = questionBankHash('/', {
            q: next.q,
            state: next.state === 'active' ? undefined : next.state,
            owner_scope: next.owner,
            owner_id: next.owner !== '' && next.owner !== 'site' ? next.ownerId : undefined,
            sort: next.sort === 'updated_at' ? undefined : next.sort,
            order: next.order === 'desc' ? undefined : next.order,
            page: next.page === 1 ? undefined : next.page,
            per_page: next.perPage === PER_PAGE ? undefined : next.perPage,
        });
        (replaceHistory ? replace : navigate)(hash);
    };

    const sort: DataTableSort = { key: values.sort, order: values.order };
    const columns: DataTableColumn<QuestionBank>[] = [
        {
            key: 'name',
            header: strings.list.columns.name,
            sortKey: 'name',
            weight: 1.8,
            minWidth: 280,
            cell: (bank) => (
                <button
                    type="button"
                    className="vy-bank-title-link"
                    disabled={bank.state === 'trashed'}
                    onClick={() => navigate(`/banks/${bank.id}/questions`)}
                >
                    <span className={`vy-bank-tile vy-bank-tone-${bank.id % 4}`}>
                        <Icon icon={FolderOpen} size={21} />
                    </span>
                    <span>
                        <strong>{bank.name}</strong>
                        {bank.description && <small>{bank.description}</small>}
                    </span>
                </button>
            ),
        },
        {
            key: 'owner',
            header: strings.list.columns.owner,
            width: '165px',
            cell: (bank) => (
                <div className="grid gap-1">
                    <span className="text-xs font-medium text-fg">{strings.form[bank.owner_scope]}</span>
                    <span className="text-xs text-fg-2">
                        <BankOwnerLabel bank={bank} />
                    </span>
                </div>
            ),
        },
        {
            key: 'questions',
            header: strings.list.columns.questions,
            sortKey: 'question_count',
            width: '105px',
            cell: (bank) => (
                <div className="grid gap-1">
                    <strong className="text-sm text-fg">{(bank.question_count ?? 0).toLocaleString()}</strong>
                    <span className="text-xs text-fg-2">{strings.list.folders(bank.folder_count ?? 0)}</span>
                </div>
            ),
        },
        {
            key: 'status',
            header: strings.workspace.status,
            width: '110px',
            cell: (bank) => (
                <Badge variant={bank.state === 'active' ? 'ok' : 'neutral'}>{strings.list[bank.state]}</Badge>
            ),
        },
        {
            key: 'updated',
            header: strings.list.columns.updated,
            sortKey: 'updated_at',
            width: '130px',
            cell: (bank) => updatedLabel(bank.updated_at),
        },
        {
            key: 'actions',
            header: 'Actions',
            hideHeader: true,
            width: '170px',
            align: 'end',
            cell: (bank) => (
                <div className="flex justify-end gap-1">
                    {bank.state !== 'trashed' && <><Button
                        variant="ghost"
                        size="sm"
                        aria-label={strings.list.open(bank.name)}
                        onClick={() => navigate(`/banks/${bank.id}/questions`)}
                    >
                        <Icon icon={ArrowUpRight} size={16} />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label={strings.list.edit(bank.name)}
                        onClick={() => navigate(`/banks/${bank.id}`)}
                    >
                        <Icon icon={Pencil} size={14} />
                    </Button></>}
                    <ResourceLifecycleActions kind="bank" id={bank.id} name={bank.name} state={bank.state}
                        onChanged={(message) => {
                            setNotice(message);
                            route({ page: 1 }, true);
                        }} />
                </div>
            ),
        },
    ];

    return (
        <div className="vy-bank-workspace vy-bank-library">
            <PageHeader
                className="vy-bank-header"
                eyebrow={strings.eyebrow}
                title={strings.list.title}
                description={strings.list.description}
            />
            <BankSummary
                items={[
                    {
                        label: strings.workspace.matchingBanks,
                        value: banks.isSuccess ? total.toLocaleString() : '\u2014',
                        detail: strings.workspace.serverTotal,
                        icon: Database,
                    },
                    {
                        label: strings.workspace.questionsPage,
                        value: banks.isSuccess
                            ? items
                                  .reduce((sum, bank) => sum + (bank.question_count ?? 0), 0)
                                  .toLocaleString()
                            : '\u2014',
                        detail: strings.workspace.pageScope,
                        icon: HelpCircle,
                    },
                    {
                        label: strings.workspace.foldersPage,
                        value: banks.isSuccess
                            ? items.reduce((sum, bank) => sum + (bank.folder_count ?? 0), 0).toLocaleString()
                            : '\u2014',
                        detail: strings.workspace.pageScope,
                        icon: FolderTree,
                    },
                    {
                        label: strings.workspace.types,
                        value: catalogue.data
                            ? pickerEntries(catalogue.data.types, catalogue.data.presets).length
                            : '\u2014',
                        detail: strings.workspace.installed,
                        icon: Shapes,
                    },
                ]}
            />
            {notice && <AdminNotice role="status" glyph="✓" title={notice} />}
            <ListFrame
                title={
                    values.state === 'trashed' ? uiText("vidyalms.ui.c560122ac4706dcb", "Trash") : values.state === 'archived'
                        ? strings.workspace.archivedBanks
                        : strings.workspace.activeBanks
                }
                meta={banks.isSuccess ? `${total} total` : undefined}
                search={{
                    label: strings.list.search,
                    placeholder: strings.list.search,
                    value: values.q,
                    onChange: (q) => route({ q, page: 1 }, true),
                }}
                chips={
                    <>
                        <FilterSelect
                            label={uiText("vidyalms.ui.50fd6ced2a998f91", "Bank state")}
                            value={values.state}
                            onValueChange={(state) => route({ state: state as typeof values.state, page: 1 })}
                            options={[
                                { value: 'active', label: strings.list.active },
                                { value: 'archived', label: strings.list.archived },
                                { value: 'trashed', label: strings.list.trashed },
                            ]}
                        />
                        <FilterSelect
                            label={uiText("vidyalms.ui.1c54583e42f9ba20", "Bank owner")}
                            value={values.owner}
                            onValueChange={(owner) =>
                                route({ owner: owner as typeof values.owner, ownerId: null, page: 1 })
                            }
                            options={[
                                { value: '', label: strings.list.allOwners },
                                { value: 'site', label: strings.form.site },
                                { value: 'course', label: strings.form.course },
                                { value: 'category', label: strings.form.category },
                            ]}
                        />
                        <FilterSelect
                            label={strings.workspace.sort}
                            value={`${values.sort}:${values.order}`}
                            onValueChange={(value) => {
                                const [sort, order] = value.split(':');
                                route({
                                    sort: sort as typeof values.sort,
                                    order: order as typeof values.order,
                                    page: 1,
                                });
                            }}
                            options={[
                                { value: 'updated_at:desc', label: uiText("vidyalms.ui.474b2a869ac1477d", "Recently updated") },
                                { value: 'created_at:desc', label: uiText("vidyalms.ui.ffb6f5764bddb68c", "Newest first") },
                                { value: 'name:asc', label: uiText("vidyalms.ui.825eb20bd683372c", "Name A to Z") },
                                { value: 'name:desc', label: uiText("vidyalms.ui.6c91347792d64028", "Name Z to A") },
                                { value: 'question_count:desc', label: uiText("vidyalms.ui.69f3cad542ba5041", "Most questions") },
                                ...(![
                                    'updated_at:desc',
                                    'created_at:desc',
                                    'name:asc',
                                    'name:desc',
                                    'question_count:desc',
                                ].includes(`${values.sort}:${values.order}`)
                                    ? [
                                          {
                                              value: `${values.sort}:${values.order}`,
                                              label: uiText("vidyalms.ui.ff46a851b217a7a1", "{p0} ({p1})", { p0: strings.list.columns[values.sort === 'question_count' ? 'questions' : values.sort === 'name' ? 'name' : 'updated'], p1: values.order }),
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                        {filtered && (
                            <Button variant="ghost" size="sm" onClick={() => navigate('/')}>
                                {strings.workspace.clearFilters}
                            </Button>
                        )}
                        {(values.owner === 'course' || values.owner === 'category') && (
                            <OwnerPicker
                                kind={values.owner}
                                value={values.ownerId}
                                label=""
                                onChange={(ownerId) => route({ ownerId, page: 1 })}
                            />
                        )}
                    </>
                }
                action={
                    <>
                        {banks.isFetching && !banks.isPending && (
                            <Spinner label={uiText("vidyalms.ui.9e6a78abbd10ebde", "Refreshing question banks")} />
                        )}
                        <Button onClick={() => navigate('/new')}>
                            <Icon icon={Plus} size={16} />
                            {strings.list.new}
                        </Button>
                    </>
                }
                footerAction={
                    <FilterSelect
                        label={strings.workspace.pageSize}
                        value={String(values.perPage)}
                        onValueChange={(value) => route({ perPage: Number(value), page: 1 })}
                        options={[20, 50, 100].map((size) => ({
                            value: String(size),
                            label: uiText("vidyalms.ui.4d72d6c7364d5c21", "{p0} per page", { p0: size }),
                        }))}
                    />
                }
                page={values.page}
                perPage={values.perPage}
                total={total}
                shownCount={items.length}
                onPageChange={(page) => route({ page })}
            >
                {needsTeacherScope ? (
                    <div className="grid gap-3 p-4">
                        <div>
                            <h2 className="font-display text-sm font-semibold text-fg">{uiText("vidyalms.ui.8a1518e9c1ab4748", "Choose a course")}</h2>
                            <p className="mt-1 text-xs text-fg-2">
                                {uiText("vidyalms.ui.3936f289b199b7e1", "Select a course to view question banks you can manage.")}</p>
                        </div>
                        <OwnerPicker
                            kind="course"
                            value={null}
                            label=""
                            onChange={(ownerId) => route({ owner: 'course', ownerId, page: 1 })}
                        />
                    </div>
                ) : banks.isError ? (
                    <div className="p-4">
                        <ScreenError error={banks.error} onRetry={() => void banks.refetch()} />
                    </div>
                ) : (
                    <DataTable
                        caption={strings.list.table}
                        columns={columns}
                        rows={items}
                        rowKey={(bank) => bank.id}
                        sort={sort}
                        onSortChange={(next) =>
                            route({ sort: next.key as typeof values.sort, order: next.order, page: 1 })
                        }
                        loading={banks.isPending}
                        empty={
                            <BankEmpty
                                filtered={filtered}
                                onReset={() => navigate('/')}
                                onCreate={() => navigate('/new')}
                            />
                        }
                    />
                )}
            </ListFrame>
        </div>
    );
}
