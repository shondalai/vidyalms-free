import { translate as uiText } from '@/shared/i18n';
import { useEffect, useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Button, EmptyState, ErrorState, Icon, Pagination, SaveIndicator } from '@/shared/components';
import {
    useArchiveBankEntry,
    useBankEntries,
    useBankEntryUsage,
    useBankEntryVersions,
    useCreateQuestionCategory,
    useLearningObjectives,
    useQuestionCategories,
    useRemoveQuestionCategory,
    useSetBankEntryObjectives,
    useSetBankEntryTags,
    useUpdateBankEntry,
    useUpdateQuestionCategory,
} from '../assessment-api';
import { useCourseQuizzes, useManagedCourse } from '../api';
import { assessmentStrings as strings } from '../assessment-strings';
import { deniedFor } from '../lib/problems';
import { useSaveTracker } from '../lib/save-state';
import { dateTimeText } from '../lib/format';
import { ListSkeleton, PermissionDenied, SaveError } from '../components/states';
import { CheckboxField, Field, Select, TextArea, TextInput } from '../components/field';
import type { BankEntry, OwnerScope, QuestionCategory } from '../lib/assessment-types';

/*
 * The question bank: folders inside an owner scope, the entries in them,
 * and one entry's versions, metadata and placements.
 *
 * Two rules from the model drive the whole screen. A bank belongs to a
 * course or a category and never to the person who wrote it, which is why
 * the scope is a first-class control and there is no "my questions" list at
 * all: user-scoped ownership is the most consistently reported operational
 * complaint about every system that has it. And editing a question that has
 * been answered writes a new version rather than mutating in place, which is
 * why the version list is on the entry rather than hidden behind an edit
 * button and why the entry says how many attempts have been served.
 */

interface ScopeChoice {
    key: string;
    scope: OwnerScope;
    scopeId: number | undefined;
    label: string;
}

function flatten(categories: readonly QuestionCategory[], depth = 0): { category: QuestionCategory; depth: number }[] {
    return categories.flatMap((category) => [
        { category, depth },
        ...flatten(category.children ?? [], depth + 1),
    ]);
}

function promptOf(entry: BankEntry): string {
    const prompt = entry.latest_version?.definition?.prompt;
    return typeof prompt === 'string' && prompt !== '' ? prompt : (entry.latest_version?.type ?? String(entry.id));
}

/** The entry's metadata, versions and placements, which are one answer about one question. */
function EntryDetail({ entry, onClose }: { entry: BankEntry; onClose: () => void }) {
    const versionsQuery = useBankEntryVersions(entry.id);
    const usageQuery = useBankEntryUsage(entry.id);
    const objectivesQuery = useLearningObjectives();
    const updateEntry = useUpdateBankEntry();
    const setTags = useSetBankEntryTags();
    const setObjectives = useSetBankEntryObjectives();
    const archive = useArchiveBankEntry();
    const save = useSaveTracker();
    const [tagText, setTagText] = useState((entry.tags ?? []).join('\n'));
    const [confirmArchive, setConfirmArchive] = useState(false);

    const versions = versionsQuery.data ?? [];
    const served = versions.reduce((sum, version) => sum + (version.attempts_served ?? 0), 0);
    const chosen = new Set((entry.objectives ?? []).map((objective) => objective.id));

    return (
        <section aria-label={promptOf(entry)} className="rounded-lg border border-border bg-surface">
            <div className="flex flex-wrap items-start gap-2.5 border-b border-border px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h4 className="font-display text-[13px] font-bold text-fg">{promptOf(entry)}</h4>
                    <p className="text-[11.5px] text-fg-2">
                        {[
                            entry.latest_version?.type ?? '',
                            strings.bank.status[entry.status],
                            strings.bank.versionsOf(entry.versions_count ?? versions.length),
                            strings.bank.usedIn(entry.usage_count ?? 0),
                        ]
                            .filter((part) => part !== '')
                            .join(' · ')}
                    </p>
                </div>
                <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
                <Button variant="outline" size="sm" onClick={onClose}>
                    {strings.common.close}
                </Button>
            </div>

            {served > 0 && (
                <p
                    role="status"
                    className="mx-4 mt-3 rounded border border-border border-l-[3px] border-l-warn bg-bg px-3 py-2 text-[11.5px] text-fg-2"
                >
                    <strong className="font-semibold text-warn-strong">{strings.bank.attemptsServed(served)}</strong>{' '}
                    {strings.bank.editWritesVersion}
                </p>
            )}

            {save.state === 'failed' && (
                <div className="px-4 pt-3">
                    <SaveError error={save.error} onRetry={save.retry} />
                </div>
            )}

            <div className="grid gap-4 p-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label={strings.bank.filterStatus}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={entry.status}
                                onChange={(event) =>
                                    void save.run((idempotencyKey) =>
                                        updateEntry.mutateAsync({
                                            entryId: entry.id,
                                            body: { status: event.target.value as BankEntry['status'] },
                                            idempotencyKey,
                                        }),
                                    )
                                }
                            >
                                <option value="draft">{strings.bank.status.draft}</option>
                                <option value="ready">{strings.bank.status.ready}</option>
                                <option value="archived">{strings.bank.status.archived}</option>
                            </Select>
                        )}
                    </Field>
                    <Field label={strings.bank.difficulty} hint={strings.bank.difficultyHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                defaultValue={entry.difficulty_hint ?? ''}
                                onBlur={(event) =>
                                    void save.run((idempotencyKey) =>
                                        updateEntry.mutateAsync({
                                            entryId: entry.id,
                                            body: {
                                                difficulty_hint:
                                                    event.target.value.trim() === '' ? null : event.target.value.trim(),
                                            },
                                            idempotencyKey,
                                        }),
                                    )
                                }
                            />
                        )}
                    </Field>
                    <Field label={strings.bank.estimatedTime} hint={strings.bank.estimatedTimeHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                inputMode="numeric"
                                aria-describedby={describedBy}
                                defaultValue={
                                    entry.estimated_time_seconds === null ||
                                    entry.estimated_time_seconds === undefined
                                        ? ''
                                        : String(entry.estimated_time_seconds)
                                }
                                onBlur={(event) =>
                                    void save.run((idempotencyKey) =>
                                        updateEntry.mutateAsync({
                                            entryId: entry.id,
                                            body: {
                                                estimated_time_seconds:
                                                    event.target.value.trim() === ''
                                                        ? null
                                                        : Number(event.target.value),
                                            },
                                            idempotencyKey,
                                        }),
                                    )
                                }
                            />
                        )}
                    </Field>
                </div>

                <Field label={strings.bank.tags} hint={strings.bank.tagsHint}>
                    {({ id, describedBy }) => (
                        <TextArea
                            id={id}
                            rows={3}
                            aria-describedby={describedBy}
                            value={tagText}
                            onChange={(event) => setTagText(event.target.value)}
                            onBlur={() =>
                                void save.run((idempotencyKey) =>
                                    setTags.mutateAsync({
                                        entryId: entry.id,
                                        tags: tagText
                                            .split('\n')
                                            .map((tag) => tag.trim())
                                            .filter((tag) => tag !== ''),
                                        idempotencyKey,
                                    }),
                                )
                            }
                        />
                    )}
                </Field>

                <fieldset className="grid gap-1.5 rounded border border-border p-3">
                    <legend className="px-1 font-display text-xs font-semibold text-fg">
                        {strings.bank.objectives}
                    </legend>
                    <p className="text-[11.5px] text-fg-2">{strings.bank.objectivesHint}</p>
                    {objectivesQuery.isPending ? (
                        <ListSkeleton label={strings.common.loading} rows={2} />
                    ) : (objectivesQuery.data ?? []).length === 0 ? (
                        <p className="text-[11.5px] text-fg-2">{strings.bank.objectiveNone}</p>
                    ) : (
                        (objectivesQuery.data ?? []).map((objective) => (
                            <CheckboxField
                                key={objective.id}
                                label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: objective.identifier, p1: objective.label })}
                                checked={chosen.has(objective.id)}
                                onChange={(event) => {
                                    const next = new Set(chosen);
                                    if (event.target.checked) {
                                        next.add(objective.id);
                                    } else {
                                        next.delete(objective.id);
                                    }
                                    void save.run((idempotencyKey) =>
                                        setObjectives.mutateAsync({
                                            entryId: entry.id,
                                            objectiveIds: [...next],
                                            idempotencyKey,
                                        }),
                                    );
                                }}
                            />
                        ))
                    )}
                </fieldset>

                <div>
                    <h5 className="mb-1.5 font-display text-xs font-semibold text-fg">{strings.bank.versions}</h5>
                    {versionsQuery.isPending ? (
                        <ListSkeleton label={strings.common.loading} rows={2} />
                    ) : versions.length === 0 ? (
                        <p className="text-[11.5px] text-fg-2">{strings.common.none}</p>
                    ) : (
                        <ul className="rounded border border-border">
                            {versions.map((version) => (
                                <li
                                    key={version.id}
                                    className="flex flex-wrap items-center gap-2 border-b border-border/60 px-3 py-2 text-[12px] last:border-b-0"
                                >
                                    <span className="font-mono text-[11px] font-bold text-fg-2">
                                        {strings.bank.version(version.version)}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate">{version.type}</span>
                                    <span className="text-[11px] text-fg-2">
                                        {strings.bank.status[version.status]}
                                    </span>
                                    <span className="text-[11px] text-fg-2">
                                        {strings.bank.attemptsServed(version.attempts_served ?? 0)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div>
                    <h5 className="mb-1.5 font-display text-xs font-semibold text-fg">{strings.bank.usageTitle}</h5>
                    {usageQuery.isPending ? (
                        <ListSkeleton label={strings.common.loading} rows={1} />
                    ) : (usageQuery.data?.placements ?? []).length === 0 ? (
                        <p className="text-[11.5px] text-fg-2">{strings.bank.usageEmpty}</p>
                    ) : (
                        <ul className="grid gap-1 text-[12px]">
                            {(usageQuery.data?.placements ?? []).map((placement) => (
                                <li key={placement.slot_id} className="text-fg-2">
                                    {strings.bank.usagePlacement(placement.quiz_title, placement.kind)}
                                    {placement.question_version_id !== null && (
                                        <span> · {strings.bank.pinned(placement.question_version_id)}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
                    {confirmArchive ? (
                        <>
                            <p className="min-w-0 flex-1 text-[11.5px] text-fg-2">{strings.bank.archiveConfirm}</p>
                            <Button variant="outline" size="sm" onClick={() => setConfirmArchive(false)}>
                                {strings.common.cancel}
                            </Button>
                            <Button
                                size="sm"
                                onClick={() =>
                                    void save
                                        .run((idempotencyKey) =>
                                            archive.mutateAsync({ entryId: entry.id, idempotencyKey }),
                                        )
                                        .then((ok) => {
                                            if (ok) {
                                                setConfirmArchive(false);
                                                onClose();
                                            }
                                        })
                                }
                            >
                                {strings.bank.archive}
                            </Button>
                        </>
                    ) : (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={entry.status === 'archived'}
                            onClick={() => setConfirmArchive(true)}
                        >
                            {strings.bank.archive}
                        </Button>
                    )}
                </div>
            </div>
        </section>
    );
}

export function BankManager({ courseId, narrow }: { courseId: number; narrow: boolean }) {
    const courseQuery = useManagedCourse(courseId);
    const quizzesQuery = useCourseQuizzes(courseId);
    const [scopeKey, setScopeKey] = useState(`course:${courseId}`);
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [openEntry, setOpenEntry] = useState<number | null>(null);
    const [newFolder, setNewFolder] = useState('');
    const [renaming, setRenaming] = useState<number | null>(null);
    const createCategory = useCreateQuestionCategory();
    const updateCategory = useUpdateQuestionCategory();
    const removeCategory = useRemoveQuestionCategory();
    const folderSave = useSaveTracker();

    const scopes: ScopeChoice[] = useMemo(() => {
        const list: ScopeChoice[] = [{ key: `course:${courseId}`, scope: 'course', scopeId: courseId, label: strings.bank.scopes.course }];
        const category = courseQuery.data?.category_id ?? null;
        if (category !== null) {
            list.push({ key: `category:${category}`, scope: 'category', scopeId: category, label: strings.bank.scopes.category });
        }
        for (const quiz of quizzesQuery.data ?? []) {
            list.push({
                key: `quiz:${quiz.id}`,
                scope: 'quiz',
                scopeId: quiz.id,
                label: uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.bank.scopes.quiz, p1: quiz.title }),
            });
        }
        list.push({ key: 'site', scope: 'site', scopeId: undefined, label: strings.bank.scopes.site });
        return list;
    }, [courseId, courseQuery.data, quizzesQuery.data]);

    const chosenScope = scopes.find((scope) => scope.key === scopeKey) ?? scopes[0];
    const categoriesQuery = useQuestionCategories(chosenScope?.scope ?? 'course', chosenScope?.scopeId);
    const entriesQuery = useBankEntries({
        scope: chosenScope?.scope,
        scope_id: chosenScope?.scopeId,
        category_id: categoryId ?? undefined,
        q: q.trim() === '' ? undefined : q.trim(),
        status: status === '' ? undefined : (status as BankEntry['status']),
        page,
        per_page: 20,
    });

    /* A folder chosen in one scope means nothing in another. */
    useEffect(() => {
        setCategoryId(null);
        setPage(1);
        setOpenEntry(null);
    }, [scopeKey]);

    const folders = flatten(categoriesQuery.data ?? []);
    const entriesPage = entriesQuery.data;
    const entries = entriesPage?.items ?? [];
    const detail = entries.find((entry) => entry.id === openEntry) ?? null;

    const addFolder = () => {
        const name = newFolder.trim();
        if (name === '' || chosenScope === undefined) {
            return;
        }
        void folderSave
            .run((idempotencyKey) =>
                createCategory.mutateAsync({
                    body: {
                        owner_scope: chosenScope.scope,
                        owner_id: chosenScope.scopeId ?? null,
                        parent_id: categoryId,
                        name,
                    },
                    idempotencyKey,
                }),
            )
            .then((ok) => {
                if (ok) {
                    setNewFolder('');
                }
            });
    };

    return (
        <div className={cn('grid gap-4', !narrow && 'grid-cols-[260px_1fr] items-start')}>
            <nav aria-label={strings.bank.folders} className="rounded-lg border border-border bg-surface">
                <div className="grid gap-2.5 border-b border-border p-3">
                    <Field label={strings.bank.scope}>
                        {({ id }) => (
                            <Select id={id} value={scopeKey} onChange={(event) => setScopeKey(event.target.value)}>
                                {scopes.map((scope) => (
                                    <option key={scope.key} value={scope.key}>
                                        {scope.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>
                {categoriesQuery.isPending ? (
                    <ListSkeleton label={strings.common.loading} rows={3} />
                ) : categoriesQuery.isError ? (
                    <div className="p-3">
                        {deniedFor(categoriesQuery.error) ? (
                            <PermissionDenied />
                        ) : (
                            <ErrorState
                                error={categoriesQuery.error}
                                title={strings.bank.errorTitle}
                                onRetry={() => void categoriesQuery.refetch()}
                            />
                        )}
                    </div>
                ) : (
                    <ul>
                        <li className="border-b border-border/60">
                            <button
                                type="button"
                                aria-current={categoryId === null ? 'true' : undefined}
                                onClick={() => setCategoryId(null)}
                                className={cn(
                                    'w-full cursor-pointer px-3.5 py-2 text-left text-[12.5px]',
                                    categoryId === null ? 'bg-accent-soft font-semibold text-fg' : 'text-fg-2',
                                )}
                            >
                                {strings.bank.allFolders}
                            </button>
                        </li>
                        {folders.map(({ category, depth }) => (
                            <li key={category.id} className="border-b border-border/60 last:border-b-0">
                                {renaming === category.id ? (
                                    <div
                                        className="flex items-center gap-1.5 py-2 pr-2"
                                        style={{ paddingLeft: `${14 + depth * 14}px` }}
                                    >
                                        <Field
                                            label={strings.bank.renameFolder}
                                            hideLabel
                                            className="min-w-0 flex-1"
                                        >
                                            {({ id }) => (
                                                <TextInput
                                                    id={id}
                                                    autoFocus
                                                    className="h-8"
                                                    defaultValue={category.name}
                                                    onKeyDown={(event) => {
                                                        if (event.key === 'Escape') {
                                                            setRenaming(null);
                                                        }
                                                    }}
                                                    onBlur={(event) => {
                                                        const name = event.target.value.trim();
                                                        setRenaming(null);
                                                        if (name !== '' && name !== category.name) {
                                                            void folderSave.run((idempotencyKey) =>
                                                                updateCategory.mutateAsync({
                                                                    categoryId: category.id,
                                                                    body: { name },
                                                                    idempotencyKey,
                                                                }),
                                                            );
                                                        }
                                                    }}
                                                />
                                            )}
                                        </Field>
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-1">
                                        <button
                                            type="button"
                                            aria-current={categoryId === category.id ? 'true' : undefined}
                                            onClick={() => setCategoryId(category.id)}
                                            style={{ paddingLeft: `${14 + depth * 14}px` }}
                                            className={cn(
                                                'min-w-0 flex-1 cursor-pointer py-2 pr-2 text-left text-[12.5px]',
                                                categoryId === category.id
                                                    ? 'bg-accent-soft font-semibold text-fg'
                                                    : 'text-fg-2',
                                            )}
                                        >
                                            <span className="block truncate">{category.name}</span>
                                            <span className="block text-[10.5px] text-fg-2">
                                                {strings.bank.entryCount(category.entry_count ?? 0)}
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            className="cursor-pointer px-2 py-2 text-[11px] text-fg-2 hover:text-fg"
                                            onClick={() => setRenaming(category.id)}
                                        >
                                            <span className="sr-only">{uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.bank.renameFolder, p1: category.name })}</span>
                                            <span aria-hidden="true">{strings.common.edit}</span>
                                        </button>
                                        <button
                                            type="button"
                                            className="cursor-pointer px-2 py-2 text-[11px] text-fg-2 hover:text-danger"
                                            onClick={() =>
                                                void folderSave.run((idempotencyKey) =>
                                                    removeCategory.mutateAsync({
                                                        categoryId: category.id,
                                                        idempotencyKey,
                                                    }),
                                                )
                                            }
                                        >
                                            <span className="sr-only">{uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.bank.removeFolder, p1: category.name })}</span>
                                            <span aria-hidden="true">{strings.common.remove}</span>
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="grid gap-2 border-t border-border p-3">
                    <Field label={strings.bank.addFolder}>
                        {({ id }) => (
                            <TextInput
                                id={id}
                                value={newFolder}
                                onChange={(event) => setNewFolder(event.target.value)}
                                placeholder={strings.bank.folderName}
                            />
                        )}
                    </Field>
                    <Button variant="outline" size="sm" disabled={newFolder.trim() === ''} onClick={addFolder}>
                        <Icon icon={Plus} size={13} />
                        {strings.bank.addFolder}
                    </Button>
                    {folderSave.state === 'failed' && (
                        <SaveError error={folderSave.error} onRetry={folderSave.retry} />
                    )}
                </div>
            </nav>

            <div className="min-w-0 grid gap-4">
                <section aria-label={strings.bank.title} className="rounded-lg border border-border bg-surface">
                    <div className="flex flex-wrap items-end gap-3 border-b border-border px-4 py-3">
                        <Field label={strings.bank.searchPlaceholder} className="min-w-[180px] flex-1">
                            {({ id }) => (
                                <TextInput
                                    id={id}
                                    value={q}
                                    placeholder={strings.bank.searchPlaceholder}
                                    onChange={(event) => {
                                        setQ(event.target.value);
                                        setPage(1);
                                    }}
                                />
                            )}
                        </Field>
                        <Field label={strings.bank.filterStatus} className="w-[150px]">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={status}
                                    onChange={(event) => {
                                        setStatus(event.target.value);
                                        setPage(1);
                                    }}
                                >
                                    <option value="">{strings.bank.anyStatus}</option>
                                    <option value="draft">{strings.bank.status.draft}</option>
                                    <option value="ready">{strings.bank.status.ready}</option>
                                    <option value="archived">{strings.bank.status.archived}</option>
                                </Select>
                            )}
                        </Field>
                    </div>

                    {entriesQuery.isPending ? (
                        <ListSkeleton label={strings.common.loading} rows={4} />
                    ) : entriesQuery.isError ? (
                        <div className="p-4">
                            {deniedFor(entriesQuery.error) ? (
                                <PermissionDenied />
                            ) : (
                                <ErrorState
                                    error={entriesQuery.error}
                                    title={strings.bank.errorTitle}
                                    onRetry={() => void entriesQuery.refetch()}
                                />
                            )}
                        </div>
                    ) : entries.length === 0 ? (
                        <EmptyState
                            title={
                                q.trim() === '' && status === ''
                                    ? strings.bank.emptyTitle
                                    : strings.bank.emptyFilteredTitle
                            }
                            description={
                                q.trim() === '' && status === ''
                                    ? strings.bank.emptyBody
                                    : strings.bank.emptyFilteredBody
                            }
                        />
                    ) : (
                        <>
                            <ul>
                                {entries.map((entry) => (
                                    <li key={entry.id}>
                                        <button
                                            type="button"
                                            aria-current={openEntry === entry.id ? 'true' : undefined}
                                            onClick={() =>
                                                setOpenEntry((current) => (current === entry.id ? null : entry.id))
                                            }
                                            className={cn(
                                                'flex w-full cursor-pointer flex-wrap items-center gap-2.5 border-b border-border/60 px-4 py-2.5 text-left text-[12.5px]',
                                                openEntry === entry.id && 'bg-accent-soft',
                                            )}
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="line-clamp-1">{promptOf(entry)}</span>
                                                <span className="block text-[11px] text-fg-2">
                                                    {[
                                                        entry.latest_version?.type ?? '',
                                                        entry.latest_version !== null &&
                                                        entry.latest_version !== undefined
                                                            ? strings.bank.version(entry.latest_version.version)
                                                            : '',
                                                        strings.bank.status[entry.status],
                                                        strings.bank.usedIn(entry.usage_count ?? 0),
                                                        entry.updated_at === undefined
                                                            ? ''
                                                            : dateTimeText(entry.updated_at),
                                                    ]
                                                        .filter((part) => part !== '')
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                            {(entry.tags ?? []).length > 0 && (
                                                <span className="text-[10.5px] text-fg-2">
                                                    {(entry.tags ?? []).join(', ')}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            {entriesPage !== undefined && entriesPage.total > entriesPage.per_page && (
                                <div className="p-3">
                                    <Pagination
                                        page={entriesPage.page}
                                        perPage={entriesPage.per_page}
                                        total={entriesPage.total}
                                        label={strings.bank.title}
                                        onPageChange={setPage}
                                    />
                                </div>
                            )}
                        </>
                    )}
                </section>

                {detail !== null && <EntryDetail entry={detail} onClose={() => setOpenEntry(null)} />}
            </div>
        </div>
    );
}
