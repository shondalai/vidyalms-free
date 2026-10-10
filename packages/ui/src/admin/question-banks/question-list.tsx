import { translate as uiText } from '@/shared/i18n';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    ChevronDown,
    FolderPlus,
    FolderTree,
    LibraryBig,
    Pencil,
    Plus,
    Save,
    Trash2,
    FileQuestion,
    Filter,
    Shapes,
} from 'lucide-react';
import {
    Badge,
    Button,
    DataTable,
    Icon,
    Spinner,
    type DataTableColumn,
    type DataTableSort,
} from '@/shared/components';
import { cn } from '@/shared/cn';
import { useQuestionTypeCatalogue } from '@/question-types';
import {
    useCreateQuestionBankCategory,
    useBulkUpdateQuestionBankEntries,
    useQuestionBank,
    useQuestionBankCategories,
    useQuestionBankEntries,
    type QuestionBankEntryParams,
} from '@/instructor/assessment-api';
import type { BankEntry, QuestionCategory } from '@/instructor/lib/assessment-types';
import { pickerGroups } from '@/instructor/lib/question-picker';
import { useSaveTracker } from '@/instructor/lib/save-state';
import { useDebounced } from '../hooks';
import { BankFilter as FilterSelect, BankCollection as ListFrame } from './workspace';
import { PageHeader } from '../components/page-header';
import { ScreenError } from '../components/states';
import { BankInput } from './fields';
import { AuthorPicker } from './owner-picker';
import { ObjectivePicker } from './objective-picker';
import { FolderManagementDialogs, type FlatBankFolder } from './folder-dialogs';
import { questionBankHash } from './routes';
import { bankStrings as strings } from './strings';
import { BankSummary, BankNavigation, BankEmpty } from './workspace';

const PER_PAGE = 20;
const STATUSES = new Set(['draft', 'ready', 'archived']);
const USAGE = new Set(['used', 'unused']);
const SORTS = new Set(['updated_at', 'type', 'status', 'difficulty', 'usage_count']);
const DATE = /^\d{4}-\d{2}-\d{2}$/;

interface QuestionListValues {
    q: string;
    categoryId: number | null;
    descendants: boolean;
    status: NonNullable<QuestionBankEntryParams['status']> | '';
    type: string;
    tag: string;
    objectiveId: number | null;
    objectiveLabel: string;
    difficulty: string;
    usage: 'used' | 'unused' | '';
    createdBy: number | null;
    lastUsedBefore: string;
    sort: NonNullable<QuestionBankEntryParams['sort']>;
    order: 'asc' | 'desc';
    page: number;
    perPage: number;
}

interface SavedQuestionView {
    id: string;
    name: string;
    query: string;
}

function savedViewKey(userId: number, bankId: number): string {
    return `vidyalms:question-bank:${bankId}:saved-filters:${userId}`;
}

function readSavedViews(userId: number, bankId: number): SavedQuestionView[] {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(savedViewKey(userId, bankId)) ?? '[]');
        if (!Array.isArray(parsed)) {
            return [];
        }
        return parsed.filter((view): view is SavedQuestionView => {
            if (typeof view !== 'object' || view === null) {
                return false;
            }
            const value = view as Partial<SavedQuestionView>;
            return (
                typeof value.id === 'string' &&
                typeof value.name === 'string' &&
                typeof value.query === 'string'
            );
        });
    } catch {
        return [];
    }
}

function writeSavedViews(userId: number, bankId: number, views: SavedQuestionView[]): void {
    try {
        window.localStorage.setItem(savedViewKey(userId, bankId), JSON.stringify(views));
    } catch {
        // Storage can be disabled by browser policy. The active filters still work.
    }
}

function BulkActions({
    bankId,
    selected,
    folders,
    onClear,
    onDone,
}: {
    bankId: number;
    selected: ReadonlySet<string | number>;
    folders: FlatBankFolder[];
    onClear: () => void;
    onDone: (updated: number) => void;
}) {
    const bulk = useBulkUpdateQuestionBankEntries();
    const save = useSaveTracker();
    const [folder, setFolder] = useState('unchanged');
    const [status, setStatus] = useState<'draft' | 'ready' | 'archived'>('ready');
    const [confirmStatus, setConfirmStatus] = useState(false);
    const [tag, setTag] = useState('');
    const ids = [...selected].filter((id): id is number => typeof id === 'number');
    const updatedCount = useRef(ids.length);
    const handledSave = useRef<number | null>(null);

    const apply = (body: {
        category_id?: number | null;
        status?: 'draft' | 'ready' | 'archived';
        add_tags?: string[];
        remove_tags?: string[];
    }) => {
        updatedCount.current = ids.length;
        void save.run(async (idempotencyKey) => {
            const result = await bulk.mutateAsync({
                bankId,
                body: { entry_ids: ids, ...body },
                idempotencyKey,
            });
            updatedCount.current = result.updated;
            return result;
        });
    };

    /* Retry replays inside the tracker, so finish from the successful state
       instead of attaching completion only to the first attempt. */
    useEffect(() => {
        if (save.state !== 'saved' || save.savedAt === null || handledSave.current === save.savedAt) {
            return;
        }
        handledSave.current = save.savedAt;
        onDone(updatedCount.current);
    }, [onDone, save.savedAt, save.state]);

    const failure =
        save.state === 'failed' ? (
            <p role="alert" className="flex items-center gap-2 text-xs text-danger">
                {uiText("vidyalms.ui.d62964c8f7b0ed2a", "The selected questions were not updated.")}<Button type="button" variant="ghost" size="sm" onClick={save.retry}>
                    {uiText("vidyalms.ui.5148c3e20576923b", "Retry now")}</Button>
            </p>
        ) : null;

    if (confirmStatus) {
        return (
            <div
                role="alertdialog"
                aria-label={strings.questions.changeStatus}
                className="flex w-full flex-wrap items-center gap-2"
            >
                <strong>{strings.questions.confirmStatus(ids.length, strings.questions[status])}</strong>
                <Button
                    size="sm"
                    variant={status === 'archived' ? 'destructive' : 'primary'}
                    disabled={save.state === 'saving'}
                    onClick={() => apply({ status })}
                >
                    {strings.questions.confirm}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setConfirmStatus(false)}>
                    {strings.questions.cancelBulk}
                </Button>
                {save.state === 'saving' && (
                    <p role="status" className="text-xs text-fg-2">
                        {uiText("vidyalms.ui.c0d27fc7221393ce", "Updating ")}{ids.length} {uiText("vidyalms.ui.9f97e4db1c28a5a1", " selected questions…")}</p>
                )}
                {failure}
            </div>
        );
    }

    return (
        <div className="grid w-full gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="mr-1">{strings.questions.selected(ids.length)}</strong>
                <label className="sr-only" htmlFor="vy-bulk-folder">
                    {strings.questions.moveTo}
                </label>
                <select
                    id="vy-bulk-folder"
                    className="h-10 min-w-40 rounded border border-border bg-bg px-3 text-xs text-fg"
                    value={folder}
                    onChange={(event) => setFolder(event.target.value)}
                >
                    <option value="unchanged">{strings.questions.moveTo}</option>
                    <option value="root">{strings.editor.noCategory}</option>
                    {folders.map(({ category, depth }) => (
                        <option
                            key={category.id}
                            value={category.id}
                        >{uiText("vidyalms.ui.380e3dbd6730acee", "{p0}{p1}", { p0: '· '.repeat(depth), p1: category.name })}</option>
                    ))}
                </select>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={folder === 'unchanged' || save.state === 'saving'}
                    onClick={() => apply({ category_id: folder === 'root' ? null : Number(folder) })}
                >
                    {strings.questions.move}
                </Button>
                <label className="sr-only" htmlFor="vy-bulk-status">
                    {strings.questions.changeStatus}
                </label>
                <select
                    id="vy-bulk-status"
                    className="h-10 rounded border border-border bg-bg px-3 text-xs text-fg"
                    value={status}
                    onChange={(event) => setStatus(event.target.value as typeof status)}
                >
                    <option value="draft">{strings.questions.draft}</option>
                    <option value="ready">{strings.questions.ready}</option>
                    <option value="archived">{strings.questions.archived}</option>
                </select>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={save.state === 'saving'}
                    onClick={() => setConfirmStatus(true)}
                >
                    {strings.questions.applyStatus}
                </Button>
                <BankInput
                    className="w-36"
                    aria-label={uiText("vidyalms.ui.b7b209ecfb3e6fc1", "Bulk tag")}
                    placeholder={strings.questions.addTags}
                    value={tag}
                    onChange={(event) => setTag(event.target.value)}
                />
                <Button
                    size="sm"
                    variant="outline"
                    disabled={tag.trim() === '' || save.state === 'saving'}
                    onClick={() => apply({ add_tags: commaTags(tag) })}
                >
                    {strings.questions.addTag}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={tag.trim() === '' || save.state === 'saving'}
                    onClick={() => apply({ remove_tags: commaTags(tag) })}
                >
                    {strings.questions.removeTag}
                </Button>
                <Button size="sm" variant="ghost" onClick={onClear}>
                    {strings.questions.clearSelection}
                </Button>
            </div>
            {save.state === 'saving' && (
                <p role="status" className="text-xs text-fg-2">
                    {uiText("vidyalms.ui.c0d27fc7221393ce", "Updating ")}{ids.length} {uiText("vidyalms.ui.9f97e4db1c28a5a1", " selected questions…")}</p>
            )}
            {failure}
        </div>
    );
}

function commaTags(raw: string): string[] {
    return [
        ...new Set(
            raw
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean),
        ),
    ];
}

function flattenCategories(categories: QuestionCategory[], depth = 0): FlatBankFolder[] {
    return categories.flatMap((category) => [
        { category, depth },
        ...flattenCategories(category.children ?? [], depth + 1),
    ]);
}

function positive(raw: string | null): number | null {
    if (raw === null || !/^[1-9]\d*$/.test(raw)) {
        return null;
    }
    const value = Number(raw);
    return Number.isSafeInteger(value) ? value : null;
}

function listValues(query: URLSearchParams): QuestionListValues {
    const status = query.get('status');
    const usage = query.get('usage');
    const sort = query.get('sort');
    const order = query.get('order');
    return {
        q: query.get('q') ?? '',
        categoryId: positive(query.get('category_id')),
        descendants: query.get('include_subcategories') === 'true',
        status: STATUSES.has(status ?? '') ? (status as NonNullable<QuestionBankEntryParams['status']>) : '',
        type: query.get('type') ?? '',
        tag: query.get('tag') ?? '',
        objectiveId: positive(query.get('objective_id')),
        objectiveLabel: query.get('objective_label') ?? '',
        difficulty: query.get('difficulty') ?? '',
        usage: USAGE.has(usage ?? '') ? (usage as 'used' | 'unused') : '',
        createdBy: positive(query.get('created_by')),
        lastUsedBefore: DATE.test(query.get('last_used_before') ?? '')
            ? (query.get('last_used_before') ?? '')
            : '',
        sort: SORTS.has(sort ?? '') ? (sort as NonNullable<QuestionBankEntryParams['sort']>) : 'updated_at',
        order: order === 'asc' || order === 'desc' ? order : 'desc',
        page: positive(query.get('page')) ?? 1,
        perPage: [20, 50, 100].includes(Number(query.get('per_page')))
            ? Number(query.get('per_page'))
            : PER_PAGE,
    };
}

function promptOf(entry: BankEntry): string {
    const prompt = entry.latest_version?.definition?.prompt;
    return typeof prompt === 'string' && prompt.trim() !== '' ? prompt : `Question #${entry.id}`;
}

function statusBadge(status: BankEntry['status']) {
    if (status === 'ready') {
        return <Badge variant="ok">{strings.questions.ready}</Badge>;
    }
    return (
        <Badge variant="neutral" glyph={null}>
            {strings.questions[status]}
        </Badge>
    );
}

function dateLabel(value: string | undefined): string {
    if (value === undefined) {
        return uiText("vidyalms.ui.9d6d14993755723a", "Not updated");
    }
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

export function QuestionList({
    bankId,
    userId,
    query,
    navigate,
    replace,
}: {
    bankId: number;
    userId: number;
    query: URLSearchParams;
    navigate: (hash: string) => void;
    replace: (hash: string) => void;
}) {
    const values = listValues(query);
    const debounced = useDebounced(values.q).trim();
    const bank = useQuestionBank(bankId);
    const categories = useQuestionBankCategories(bankId);
    const catalogue = useQuestionTypeCatalogue();
    const createCategory = useCreateQuestionBankCategory();
    const folderSave = useSaveTracker();
    const [folderName, setFolderName] = useState('');
    const [editingFolder, setEditingFolder] = useState<QuestionCategory | null>(null);
    const [deletingFolder, setDeletingFolder] = useState<QuestionCategory | null>(null);
    const [selected, setSelected] = useState<Set<string | number>>(new Set());
    const [bulkMessage, setBulkMessage] = useState('');
    const [authorLabel, setAuthorLabel] = useState('');
    const advancedCount = [
        values.difficulty,
        values.usage,
        values.tag,
        values.objectiveId,
        values.createdBy,
        values.lastUsedBefore,
    ].filter(Boolean).length;
    const filtered =
        advancedCount > 0 ||
        values.categoryId !== null ||
        values.q.trim() !== '' ||
        values.status !== '' ||
        values.type !== '';
    const [moreFilters, setMoreFilters] = useState(advancedCount > 0);
    const [folderSearch, setFolderSearch] = useState('');
    const [savedViews, setSavedViews] = useState(() => readSavedViews(userId, bankId));
    const [savedViewId, setSavedViewId] = useState('');
    const [savingView, setSavingView] = useState(false);
    const [viewName, setViewName] = useState('');
    const params: QuestionBankEntryParams = {
        q: debounced || undefined,
        category_id: values.categoryId ?? undefined,
        include_subcategories: values.categoryId === null ? undefined : values.descendants,
        status: values.status || undefined,
        type: values.type || undefined,
        tag: values.tag.trim() || undefined,
        objective_id: values.objectiveId ?? undefined,
        difficulty: values.difficulty || undefined,
        usage: values.usage || undefined,
        created_by: values.createdBy ?? undefined,
        last_used_before: values.lastUsedBefore === '' ? undefined : `${values.lastUsedBefore}T00:00:00.000Z`,
        sort: values.sort,
        order: values.order,
        page: values.page,
        per_page: values.perPage,
    };
    const entries = useQuestionBankEntries(bankId, params);
    const items = entries.data?.items ?? [];
    const total = entries.data?.total ?? 0;
    const folders = useMemo(() => flattenCategories(categories.data ?? []), [categories.data]);
    const routeQuery = query.toString();
    const visibleFolders = folders.filter(({ category }) =>
        category.name.toLocaleLowerCase().includes(folderSearch.trim().toLocaleLowerCase()),
    );

    useEffect(() => setSelected(new Set()), [bankId, routeQuery]);

    useEffect(() => {
        if (folderSave.state === 'saved') setFolderName('');
    }, [folderSave.state, folderSave.savedAt]);

    const route = (changes: Partial<typeof values>, replaceHistory = false) => {
        const next = { ...values, ...changes };
        if (Object.keys(changes).some((key) => !['page', 'perPage'].includes(key))) setSavedViewId('');
        const hash = questionBankHash(`/banks/${bankId}/questions`, {
            q: next.q,
            category_id: next.categoryId,
            include_subcategories: next.categoryId === null ? undefined : next.descendants,
            status: next.status,
            type: next.type,
            tag: next.tag,
            objective_id: next.objectiveId,
            objective_label: next.objectiveId === null ? undefined : next.objectiveLabel,
            difficulty: next.difficulty,
            usage: next.usage,
            created_by: next.createdBy,
            last_used_before: next.lastUsedBefore,
            sort: next.sort === 'updated_at' ? undefined : next.sort,
            order: next.order === 'desc' ? undefined : next.order,
            page: next.page === 1 ? undefined : next.page,
            per_page: next.perPage === PER_PAGE ? undefined : next.perPage,
        });
        (replaceHistory ? replace : navigate)(hash);
    };

    const saveView = () => {
        const name = viewName.trim();
        if (name === '') {
            return;
        }
        const savedQuery = new URLSearchParams(query);
        savedQuery.delete('page');
        const view: SavedQuestionView = {
            id: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
            name,
            query: savedQuery.toString(),
        };
        const next = [...savedViews, view];
        setSavedViews(next);
        writeSavedViews(userId, bankId, next);
        setSavedViewId(view.id);
        setViewName('');
        setSavingView(false);
    };

    const applySavedView = (id: string) => {
        setSavedViewId(id);
        setMoreFilters(true);
        const view = savedViews.find((candidate) => candidate.id === id);
        if (view !== undefined) {
            replace(`/banks/${bankId}/questions${view.query === '' ? '' : `?${view.query}`}`);
        }
    };

    const deleteSavedView = () => {
        if (savedViewId === '') {
            return;
        }
        const next = savedViews.filter((view) => view.id !== savedViewId);
        setSavedViews(next);
        writeSavedViews(userId, bankId, next);
        setSavedViewId('');
    };

    const addFolder = () => {
        const name = folderName.trim();
        if (name === '') {
            return;
        }
        void folderSave.run((idempotencyKey) =>
            createCategory.mutateAsync({
                bankId,
                body: { name, parent_id: values.categoryId },
                idempotencyKey,
            }),
        );
    };

    const typeLabels = new Map((catalogue.data?.types ?? []).map((type) => [type.key, type.label]));
    const picker = pickerGroups(catalogue.data?.types ?? [], catalogue.data?.presets ?? []);
    const columns: DataTableColumn<BankEntry>[] = [
        {
            key: 'question',
            header: strings.questions.columns.question,
            weight: 2,
            cell: (entry) => (
                <button
                    type="button"
                    className="vy-bank-question-link max-w-full cursor-pointer text-left"
                    onClick={() => navigate(`/banks/${bankId}/questions/${entry.id}/edit`)}
                >
                    <span className="line-clamp-2 font-medium text-fg hover:text-accent">
                        {promptOf(entry)}
                    </span>
                    <span className="mt-0.5 block text-[10.5px] text-fg-2">
                        {entry.latest_version === null
                            ? uiText("vidyalms.ui.77c5eac6b1eb811b", "No version")
                            : strings.questions.version(entry.latest_version.version)}
                        {(entry.tags ?? []).length > 0 ? uiText("vidyalms.ui.04420b9dfe3f0a87", " · {p0}", { p0: (entry.tags ?? []).join(', ') }) : ''}
                    </span>
                </button>
            ),
        },
        {
            key: 'type',
            header: strings.questions.columns.type,
            sortKey: 'type',
            width: '150px',
            cell: (entry) => (
                <span className="vy-bank-format-tag">
                    {typeLabels.get(entry.latest_version?.type ?? '') ??
                        entry.latest_version?.type ??
                        uiText("vidyalms.ui.b764cdc0eab71374", "Unknown")}
                </span>
            ),
        },
        {
            key: 'status',
            header: strings.questions.columns.status,
            sortKey: 'status',
            width: '100px',
            cell: (entry) => statusBadge(entry.status),
        },
        {
            key: 'difficulty',
            header: strings.questions.columns.difficulty,
            sortKey: 'difficulty',
            width: '110px',
            cell: (entry) => entry.difficulty_hint || 'Not set',
        },
        {
            key: 'usage',
            header: strings.questions.columns.usage,
            sortKey: 'usage_count',
            width: '100px',
            cell: (entry) => strings.questions.usedCount(entry.usage_count ?? 0),
        },
        {
            key: 'updated',
            header: strings.questions.columns.updated,
            sortKey: 'updated_at',
            width: '125px',
            cell: (entry) => dateLabel(entry.updated_at),
        },
        {
            key: 'actions',
            header: 'Actions',
            hideHeader: true,
            width: '64px',
            align: 'end',
            cell: (entry) => (
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={strings.questions.edit(promptOf(entry))}
                    onClick={() => navigate(`/banks/${bankId}/questions/${entry.id}/edit`)}
                >
                    <Icon icon={Pencil} size={14} />
                </Button>
            ),
        },
    ];

    if (bank.isError) {
        return <ScreenError error={bank.error} onRetry={() => void bank.refetch()} />;
    }

    return (
        <div className="vy-bank-workspace vy-bank-detail">
            <PageHeader
                className="vy-bank-header"
                eyebrow={strings.eyebrow}
                title={bank.data?.name ?? uiText("vidyalms.ui.d8c022e8a4f80e72", "Question bank")}
                description={bank.data?.description || strings.workspace.organize}
                actions={
                    <>
                        {bank.data && <Badge variant={bank.data.state === 'active' ? 'ok' : 'neutral'}>{strings.list[bank.data.state]}</Badge>}
                        <Button size="sm" onClick={() => navigate(`/banks/${bankId}/questions/new`)}>
                            <Icon icon={Plus} size={15} />
                            {strings.questions.add}
                        </Button>
                    </>
                }
            />
            <BankNavigation bankId={bankId} active="questions" navigate={navigate} />
            {bank.data?.state === 'archived' && (
                <p role="status" className="rounded-lg border border-border bg-surface p-4 text-sm text-fg-2">
                    {strings.workspace.archivedHint}
                </p>
            )}
            <BankSummary
                items={[
                    {
                        label: strings.workspace.inBank,
                        value: bank.data?.question_count?.toLocaleString() ?? '\u2014',
                        detail: bank.data?.name ?? '',
                        icon: LibraryBig,
                    },
                    {
                        label: strings.workspace.matchingQuestions,
                        value: entries.isSuccess ? total.toLocaleString() : '\u2014',
                        detail: strings.workspace.serverTotal,
                        icon: FileQuestion,
                    },
                    {
                        label: strings.list.stats.folders,
                        value: categories.isSuccess ? folders.length.toLocaleString() : '\u2014',
                        detail: strings.questions.includeChildren,
                        icon: FolderTree,
                    },
                    {
                        label: strings.workspace.types,
                        value: catalogue.data
                            ? picker.reduce((sum, group) => sum + group.entries.length, 0)
                            : '\u2014',
                        detail: strings.workspace.installed,
                        icon: Shapes,
                    },
                ]}
            />
            <div className="vy-bank-detail-grid">
                <aside aria-label={uiText("vidyalms.ui.86c8f0e1a2c2d9f2", "Question folders")} className="vy-bank-folders">
                    <div className="flex items-center gap-2 border-b border-border px-3 py-3 font-display text-xs font-semibold text-fg">
                        <Icon icon={FolderTree} size={15} />
                        {uiText("vidyalms.ui.c4d6bb200f4c058a", "Folders")}</div>
                    <BankInput
                        type="search"
                        className="vy-bank-folder-search"
                        aria-label={strings.workspace.searchFolders}
                        placeholder={strings.workspace.searchFolders}
                        value={folderSearch}
                        onChange={(event) => setFolderSearch(event.target.value)}
                    />
                    {categories.isError ? (
                        <div className="p-3">
                            <ScreenError error={categories.error} onRetry={() => void categories.refetch()} />
                        </div>
                    ) : (
                        <ul className="max-h-[380px] overflow-y-auto py-1">
                            <li>
                                <button
                                    type="button"
                                    aria-current={values.categoryId === null ? 'page' : undefined}
                                    onClick={() => route({ categoryId: null, descendants: false, page: 1 })}
                                    className={cn(
                                        'w-full px-3 py-2 text-left text-xs',
                                        values.categoryId === null
                                            ? 'bg-accent-soft font-semibold text-accent'
                                            : 'text-fg-2 hover:bg-surface-2',
                                    )}
                                >
                                    {strings.questions.allCategories}
                                </button>
                            </li>
                            {visibleFolders.map(({ category, depth }) => (
                                <li
                                    key={category.id}
                                    className={cn(
                                        'flex items-center gap-0.5 pr-1',
                                        values.categoryId === category.id
                                            ? 'bg-accent-soft text-accent'
                                            : 'text-fg-2 hover:bg-surface-2',
                                    )}
                                >
                                    <button
                                        type="button"
                                        aria-current={values.categoryId === category.id ? 'page' : undefined}
                                        onClick={() => route({ categoryId: category.id, page: 1 })}
                                        style={{ paddingLeft: `${12 + depth * 14}px` }}
                                        className="flex min-w-0 flex-1 items-center justify-between gap-2 py-2 pr-1 text-left text-xs"
                                    >
                                        <span
                                            className={cn(
                                                'truncate',
                                                values.categoryId === category.id && 'font-semibold',
                                            )}
                                        >
                                            {category.name}
                                        </span>
                                        <span className="tabular-nums">{category.entry_count ?? 0}</span>
                                    </button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 w-7 shrink-0 p-0"
                                        aria-label={uiText("vidyalms.ui.331d7841116f45f9", "Edit folder {p0}", { p0: category.name })}
                                        onClick={() => setEditingFolder(category)}
                                    >
                                        <Icon icon={Pencil} size={12} />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 w-7 shrink-0 p-0 text-danger"
                                        aria-label={uiText("vidyalms.ui.2fb4393c6cc38faa", "Delete folder {p0}", { p0: category.name })}
                                        onClick={() => setDeletingFolder(category)}
                                    >
                                        <Icon icon={Trash2} size={12} />
                                    </Button>
                                </li>
                            ))}
                            {visibleFolders.length === 0 && folderSearch !== '' && (
                                <li className="p-3 text-xs text-fg-2">{strings.workspace.noFolders}</li>
                            )}
                        </ul>
                    )}
                    <div className="grid gap-2 border-t border-border p-3">
                        <p className="text-xs leading-relaxed text-fg-2">
                            {strings.workspace.folderDestination(
                                folders.find(({ category }) => category.id === values.categoryId)?.category
                                    .name ?? strings.workspace.bankRoot,
                            )}
                        </p>
                        <label htmlFor="vy-new-bank-folder" className="sr-only">
                            {strings.questions.folderName}
                        </label>
                        <BankInput
                            id="vy-new-bank-folder"
                            value={folderName}
                            disabled={folderSave.state === 'saving'}
                            placeholder={strings.questions.folderName}
                            onChange={(event) => setFolderName(event.target.value)}
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={folderName.trim() === '' || folderSave.state === 'saving'}
                            onClick={addFolder}
                        >
                            <Icon icon={FolderPlus} size={14} />
                            {strings.questions.addFolder}
                        </Button>
                        {folderSave.state === 'failed' && (
                            <p role="alert" className="text-xs text-danger">
                                {strings.workspace.folderFailed}
                                <Button variant="ghost" size="sm" onClick={folderSave.retry}>
                                    {strings.workspace.retry}
                                </Button>
                            </p>
                        )}
                        {folderSave.state === 'saved' && (
                            <p role="status" className="text-xs text-ok-strong">
                                {strings.workspace.folderCreated}
                            </p>
                        )}
                    </div>
                </aside>
                <ListFrame
                    title={uiText("vidyalms.ui.9a72221a27476f3e", "Questions")}
                    meta={entries.isSuccess ? `${total} total` : undefined}
                    search={{
                        label: strings.questions.search,
                        placeholder: strings.questions.search,
                        value: values.q,
                        onChange: (q) => route({ q, page: 1 }, true),
                    }}
                    chips={
                        <>
                            <label className="inline-flex h-10 cursor-pointer items-center gap-2 rounded border border-border bg-bg px-3 text-xs text-fg">
                                <input
                                    type="checkbox"
                                    checked={values.descendants}
                                    disabled={values.categoryId === null}
                                    onChange={(event) =>
                                        route({ descendants: event.target.checked, page: 1 })
                                    }
                                    className="h-4 w-4 accent-accent"
                                />
                                {strings.questions.includeChildren}
                            </label>
                            <FilterSelect
                                label={uiText("vidyalms.ui.920e413c7d411b61", "Status")}
                                value={values.status}
                                onValueChange={(status) =>
                                    route({ status: status as typeof values.status, page: 1 })
                                }
                                options={[
                                    { value: '', label: strings.questions.allStatuses },
                                    { value: 'draft', label: strings.questions.draft },
                                    { value: 'ready', label: strings.questions.ready },
                                    { value: 'archived', label: strings.questions.archived },
                                ]}
                            />
                            <FilterSelect
                                label={uiText("vidyalms.ui.efe8656fc7c22c7e", "Question type")}
                                value={values.type}
                                onValueChange={(type) => route({ type, page: 1 })}
                                options={[
                                    { value: '', label: strings.questions.allTypes },
                                    ...(catalogue.data?.types ?? []).map((type) => ({
                                        value: type.key,
                                        label: type.label,
                                    })),
                                ]}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-expanded={moreFilters}
                                onClick={() => setMoreFilters((open) => !open)}
                            >
                                <Icon icon={Filter} size={14} />
                                {strings.questions.moreFilters}
                                {advancedCount > 0 ? uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: advancedCount }) : ''}
                                <Icon icon={ChevronDown} size={14} />
                            </Button>
                            {filtered && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSavedViewId('');
                                        navigate(`/banks/${bankId}/questions`);
                                    }}
                                >
                                    {strings.workspace.clearFilters}
                                </Button>
                            )}
                            {moreFilters && (
                                <div
                                    className="vy-bank-advanced"
                                    role="region"
                                    aria-label={strings.workspace.advanced}
                                >
                                    <p>{strings.workspace.advanced}</p>
                                    <FilterSelect
                                        label={uiText("vidyalms.ui.be44133ed57f9e44", "Difficulty")}
                                        value={values.difficulty}
                                        onValueChange={(difficulty) => route({ difficulty, page: 1 })}
                                        options={[
                                            { value: '', label: strings.questions.allDifficulty },
                                            { value: 'easy', label: uiText("vidyalms.ui.d6915875decb6e43", "Easy") },
                                            { value: 'medium', label: uiText("vidyalms.ui.8e588cd187741f1c", "Medium") },
                                            { value: 'hard', label: uiText("vidyalms.ui.9e73f8fa9cb76c44", "Hard") },
                                        ]}
                                    />
                                    <FilterSelect
                                        label={uiText("vidyalms.ui.8d59829c1e15afe1", "Usage")}
                                        value={values.usage}
                                        onValueChange={(usage) =>
                                            route({ usage: usage as typeof values.usage, page: 1 })
                                        }
                                        options={[
                                            { value: '', label: strings.questions.allUsage },
                                            { value: 'used', label: strings.questions.used },
                                            { value: 'unused', label: strings.questions.unused },
                                        ]}
                                    />
                                    <BankInput
                                        className="w-36"
                                        aria-label={strings.questions.tag}
                                        placeholder={strings.questions.tag}
                                        value={values.tag}
                                        onChange={(event) =>
                                            route({ tag: event.target.value, page: 1 }, true)
                                        }
                                    />
                                    <ObjectivePicker
                                        value={
                                            values.objectiveId === null
                                                ? []
                                                : [
                                                      {
                                                          id: values.objectiveId,
                                                          label:
                                                              values.objectiveLabel || 'Selected objective',
                                                      },
                                                  ]
                                        }
                                        onChange={(objectives) =>
                                            route({
                                                objectiveId: objectives[0]?.id ?? null,
                                                objectiveLabel: objectives[0]?.label ?? '',
                                                page: 1,
                                            })
                                        }
                                    />

                                    <AuthorPicker
                                        value={values.createdBy}
                                        label={authorLabel}
                                        onChange={(createdBy, label) => {
                                            setAuthorLabel(label);
                                            route({ createdBy, page: 1 });
                                        }}
                                    />
                                    <label className="grid h-10 grid-cols-[auto_minmax(8rem,1fr)] items-center gap-2 rounded border border-border bg-bg px-3 text-xs text-fg">
                                        <span>{strings.questions.lastUsedBefore}</span>
                                        <input
                                            type="date"
                                            aria-label={strings.questions.lastUsedBefore}
                                            className="h-8 min-w-0 border-0 bg-transparent p-0 text-xs text-fg"
                                            value={values.lastUsedBefore}
                                            onChange={(event) =>
                                                route({ lastUsedBefore: event.target.value, page: 1 })
                                            }
                                        />
                                    </label>
                                </div>
                            )}
                        </>
                    }
                    action={
                        <>
                            <label className="sr-only" htmlFor="vy-saved-question-view">
                                {strings.questions.savedFilters}
                            </label>
                            <select
                                id="vy-saved-question-view"
                                className="h-10 min-w-36 rounded border border-border bg-bg px-3 text-xs text-fg"
                                value={savedViewId}
                                onChange={(event) => applySavedView(event.target.value)}
                            >
                                <option value="">{strings.questions.savedFilters}</option>
                                {savedViews.map((view) => (
                                    <option key={view.id} value={view.id}>
                                        {view.name}
                                    </option>
                                ))}
                            </select>
                            {savingView ? (
                                <>
                                    <BankInput
                                        autoFocus
                                        className="w-36"
                                        aria-label={strings.questions.viewName}
                                        placeholder={strings.questions.viewName}
                                        value={viewName}
                                        onChange={(event) => setViewName(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                saveView();
                                            }
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={viewName.trim() === ''}
                                        onClick={saveView}
                                    >
                                        <Icon icon={Save} size={14} />
                                        {strings.questions.save}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setSavingView(false);
                                            setViewName('');
                                        }}
                                    >
                                        {strings.questions.cancelBulk}
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setSavingView(true)}
                                >
                                    <Icon icon={Save} size={14} />
                                    {strings.questions.saveFilters}
                                </Button>
                            )}
                            {savedViewId !== '' && (
                                <Button type="button" variant="ghost" size="sm" onClick={deleteSavedView}>
                                    {strings.questions.deleteView}
                                </Button>
                            )}
                            <FilterSelect
                                label={strings.workspace.pageSize}
                                value={String(values.perPage)}
                                onValueChange={(value) => route({ perPage: Number(value), page: 1 })}
                                options={[20, 50, 100].map((size) => ({
                                    value: String(size),
                                    label: uiText("vidyalms.ui.4d72d6c7364d5c21", "{p0} per page", { p0: size }),
                                }))}
                            />
                            {entries.isFetching && !entries.isPending && (
                                <Spinner label={uiText("vidyalms.ui.ffaea726478c17d3", "Refreshing questions")} />
                            )}
                        </>
                    }
                    banner={
                        selected.size > 0 ? (
                            <BulkActions
                                bankId={bankId}
                                selected={selected}
                                folders={folders}
                                onClear={() => setSelected(new Set())}
                                onDone={(updated) => {
                                    setSelected(new Set());
                                    setBulkMessage(
                                        `${updated} selected ${updated === 1 ? 'question was' : 'questions were'} updated.`,
                                    );
                                }}
                            />
                        ) : bulkMessage === '' ? undefined : (
                            <p role="status" className="text-xs font-medium text-ok-strong">
                                {bulkMessage}
                            </p>
                        )
                    }
                    page={values.page}
                    perPage={values.perPage}
                    total={total}
                    shownCount={items.length}
                    onPageChange={(page) => route({ page })}
                >
                    {entries.isError ? (
                        <div className="p-4">
                            <ScreenError error={entries.error} onRetry={() => void entries.refetch()} />
                        </div>
                    ) : (
                        <DataTable
                            caption={strings.questions.table}
                            columns={columns}
                            rows={items}
                            rowKey={(entry) => entry.id}
                            sort={{ key: values.sort, order: values.order } as DataTableSort}
                            onSortChange={(next) =>
                                route({ sort: next.key as typeof values.sort, order: next.order, page: 1 })
                            }
                            loading={entries.isPending}
                            empty={
                                <BankEmpty
                                    question
                                    filtered={filtered}
                                    onReset={() => navigate(`/banks/${bankId}/questions`)}
                                    onCreate={() => navigate(`/banks/${bankId}/questions/new`)}
                                />
                            }
                            selection={{
                                selectedKeys: selected,
                                onSelectionChange: setSelected,
                                rowLabel: promptOf,
                                selectAllLabel: 'Select all questions on this page',
                            }}
                        />
                    )}
                </ListFrame>
            </div>
            {catalogue.isSuccess && picker.length === 0 && (
                <p role="status" className="text-xs text-fg-2">
                    {uiText("vidyalms.ui.0bb9a0b9a853488a", "No installed question type can be authored.")}</p>
            )}
            <FolderManagementDialogs
                bankId={bankId}
                folders={folders}
                editing={editingFolder}
                deleting={deletingFolder}
                onEditingChange={setEditingFolder}
                onDeletingChange={setDeletingFolder}
                onDeleted={(folderId) => {
                    if (values.categoryId === folderId) {
                        route({ categoryId: null, descendants: false, page: 1 });
                    }
                }}
            />
        </div>
    );
}
