import { translate as uiText } from '@/shared/i18n';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { studioStrings as studio } from '../studio-strings';
import { Dialog, DialogContent, DialogTitle, DialogDescription, DialogFooter } from '@/shared/components';
import { TextInput } from '../components/field';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Plus } from 'lucide-react';
import type { AuthoringLesson, AuthoringModule, LessonTypeInfo } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import { useAnnounce } from '@/shared/a11y';
import { Button, EmptyState, ErrorState, Icon, SaveIndicator } from '@/shared/components';
import {
    useAuthoringCurriculum,
    useLessonTypes,
    useReorderLessons,
    useReorderModules,
    useRestoreLesson,
    useRestoreModule,
} from '../api';
import { instructorStrings as strings } from '../strings';
import { orderAfterVisibleMove } from '../lib/reorder';
import { isArchived, isNotArchived } from '../lib/archive';
import { useSaveTracker } from '../lib/save-state';
import { CheckboxField } from '../components/field';
import { LessonTypeChip, StateText } from '../components/chips';
import { DragHandle, DropTarget, OrderControls, useListDrag } from '../components/order-controls';
import { ListSkeleton, PermissionDenied, SaveError } from '../components/states';
import { ModuleDialog } from './module-dialog';
import { LessonDialog } from './lesson-dialog';
import { QuizLessonEditor } from './quiz-lesson-editor';
import { AddLessonMenu } from './add-lesson-menu';
import { ArchiveDialog, type ArchiveTarget } from './archive-dialog';
import { deniedFor } from '../lib/problems';

/*
 * Artboard 1a: modules and lessons in explicit order, every row with move
 * buttons AND a drag handle, and an add-lesson menu listing what the
 * registry answers with each type's completion mode.
 *
 * Order is written as the whole list, so a move is one PUT of every id in
 * the wanted order and the server rewrites positions 1..n. While that write
 * is in flight the list shows the new order optimistically, and the
 * SaveIndicator says exactly where the write stands.
 *
 * Archived rows are out of the way, not gone. They keep no ordering
 * controls and no edit (editing one would quietly move it back out of the
 * archive through its state field), they are hidden behind one toggle, and
 * the only thing offered on them is Restore. They stay in the id list every
 * order write carries, because they are still rows of the course.
 */

type LessonSelection = { moduleId: number; lesson: AuthoringLesson | null; type: string };
export function CurriculumTab({
    courseId,
    narrow,
    onOpenQuiz,
    studioMode = false,
}: {
    courseId: number;
    narrow: boolean;
    onOpenQuiz?: (quizId: number | null) => void;
    studioMode?: boolean;
}) {
    const curriculumQuery = useAuthoringCurriculum(courseId);
    const lessonTypesQuery = useLessonTypes();
    const reorderModules = useReorderModules();
    const reorderLessons = useReorderLessons();
    const restoreModule = useRestoreModule();
    const restoreLesson = useRestoreLesson();
    const save = useSaveTracker();
    const announce = useAnnounce();

    const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set());
    const [moduleDialog, setModuleDialog] = useState<{ module: AuthoringModule | null } | null>(null);
    const [lessonDialog, setLessonDialog] = useState<{
        moduleId: number;
        lesson: AuthoringLesson | null;
        type: string;
    } | null>(null);
    const [search, setSearch] = useState('');
    const [pendingLesson, setPendingLesson] = useState<LessonSelection | null>(null);
    const selectLesson = (next: LessonSelection) => {
        if (next.lesson !== null && lessonDialog?.lesson?.id === next.lesson.id) return;
        if (hasUnsavedWorkspaceChanges()) setPendingLesson(next);
        else setLessonDialog(next);
    };
    const [archiveTarget, setArchiveTarget] = useState<ArchiveTarget | null>(null);
    const [showArchived, setShowArchived] = useState(false);
    const [archiveHasDraft, setArchiveHasDraft] = useState(false);
    const affectsEditor = (target: ArchiveTarget) =>
        lessonDialog !== null &&
        (target.kind === 'module'
            ? target.id === lessonDialog.moduleId
            : target.id === lessonDialog.lesson?.id);
    const openArchive = (target: ArchiveTarget) => {
        setArchiveHasDraft(affectsEditor(target) && hasUnsavedWorkspaceChanges());
        setArchiveTarget(target);
    };
    /* What the last archive or restore did, in the server's words when it
       gave them. Announced once and left on screen until it is dismissed or
       replaced. */
    const [note, setNote] = useState<string | null>(null);
    /* The order shown while a reorder is in flight; null means "server order". */
    const [pendingModules, setPendingModules] = useState<number[] | null>(null);
    const [pendingLessons, setPendingLessons] = useState<{ moduleId: number; ids: number[] } | null>(null);

    const serverModules = useMemo(() => curriculumQuery.data?.modules ?? [], [curriculumQuery.data]);

    const initializedStudio = useRef(false);
    useEffect(() => {
        if (!studioMode || initializedStudio.current || !curriculumQuery.isSuccess) return;
        initializedStudio.current = true;
        const active = serverModules.filter(isNotArchived);
        setExpanded(new Set(active.map((module) => module.id)));
        const firstModule = active.find((module) => module.lessons.some(isNotArchived));
        const firstLesson = firstModule?.lessons.find(isNotArchived);
        if (firstModule && firstLesson)
            setLessonDialog({ moduleId: firstModule.id, lesson: firstLesson, type: firstLesson.type });
    }, [studioMode, curriculumQuery.isSuccess, serverModules]);

    const modules = useMemo(() => {
        if (pendingModules === null) {
            return serverModules;
        }
        const byId = new Map(serverModules.map((module) => [module.id, module] as const));
        const ordered = pendingModules.flatMap((id) => {
            const module = byId.get(id);
            return module === undefined ? [] : [module];
        });
        return ordered.length === serverModules.length ? ordered : serverModules;
    }, [serverModules, pendingModules]);

    const activeModules = useMemo(() => modules.filter(isNotArchived), [modules]);
    const archivedModules = useMemo(() => modules.filter(isArchived), [modules]);

    const lessonsOf = (module: AuthoringModule): AuthoringLesson[] => {
        if (pendingLessons === null || pendingLessons.moduleId !== module.id) {
            return module.lessons;
        }
        const byId = new Map(module.lessons.map((lesson) => [lesson.id, lesson] as const));
        const ordered = pendingLessons.ids.flatMap((id) => {
            const lesson = byId.get(id);
            return lesson === undefined ? [] : [lesson];
        });
        return ordered.length === module.lessons.length ? ordered : module.lessons;
    };

    const archivedCount =
        archivedModules.length +
        activeModules.reduce((total, module) => total + module.lessons.filter(isArchived).length, 0);

    const report = (message: string) => {
        setNote(message);
        announce(message);
    };

    /*
     * The optimistic order is cleared either way. On success the server holds
     * what the screen already shows; on failure it does not, and leaving the
     * pending order up would show the reader an order the server refused,
     * indefinitely, while announce() had already told them it took. The
     * mutation invalidates the course on both outcomes, so the list snaps
     * back to what the server holds and the retry keeps its key.
     */
    const moveModule = (from: number, to: number) => {
        const next = orderAfterVisibleMove(modules, activeModules, from, to);
        const ids = next.map((module) => module.id);
        const moved = activeModules[from];
        setPendingModules(ids);
        if (moved !== undefined) {
            announce(strings.curriculum.movedModule(moved.title, to + 1, activeModules.length));
        }
        void save
            .run((idempotencyKey) => reorderModules.mutateAsync({ courseId, moduleIds: ids, idempotencyKey }))
            .then((ok) => {
                setPendingModules(null);
                if (!ok && moved !== undefined) {
                    announce(strings.curriculum.moveRefused(moved.title));
                }
            });
    };

    const moveLesson = (module: AuthoringModule, from: number, to: number) => {
        const lessons = lessonsOf(module);
        const visible = lessons.filter(isNotArchived);
        const next = orderAfterVisibleMove(lessons, visible, from, to);
        const ids = next.map((lesson) => lesson.id);
        const moved = visible[from];
        setPendingLessons({ moduleId: module.id, ids });
        if (moved !== undefined) {
            announce(strings.curriculum.movedLesson(moved.title, to + 1, visible.length));
        }
        void save
            .run((idempotencyKey) =>
                reorderLessons.mutateAsync({ courseId, moduleId: module.id, lessonIds: ids, idempotencyKey }),
            )
            .then((ok) => {
                setPendingLessons(null);
                if (!ok && moved !== undefined) {
                    announce(strings.curriculum.moveRefused(moved.title));
                }
            });
    };

    const restore = (target: ArchiveTarget) => {
        void save
            .run((idempotencyKey) =>
                target.kind === 'module'
                    ? restoreModule.mutateAsync({ courseId, moduleId: target.id, idempotencyKey })
                    : restoreLesson.mutateAsync({ courseId, lessonId: target.id, idempotencyKey }),
            )
            .then((ok) => {
                if (ok) {
                    report(
                        target.kind === 'module'
                            ? strings.curriculum.archive.restoredModule(target.title)
                            : strings.curriculum.archive.restoredLesson(target.title),
                    );
                }
            });
    };

    const moduleDrag = useListDrag({ listId: 'modules', onDrop: (from, to) => moveModule(from, to) });

    if (curriculumQuery.isPending) {
        return <ListSkeleton label={strings.states.loading} rows={3} />;
    }

    if (curriculumQuery.isError) {
        if (deniedFor(curriculumQuery.error)) {
            return <PermissionDenied />;
        }
        return (
            <ErrorState
                error={curriculumQuery.error}
                title={strings.curriculum.error.title}
                onRetry={() => void curriculumQuery.refetch()}
            />
        );
    }

    const lessonTypes: LessonTypeInfo[] = lessonTypesQuery.data ?? [];
    const currentLesson =
        lessonDialog?.lesson === null || lessonDialog === null
            ? null
            : studioMode
              ? (serverModules
                    .flatMap((module) => module.lessons)
                    .find((lesson) => lesson.id === lessonDialog.lesson?.id) ?? lessonDialog.lesson)
              : lessonDialog.lesson;

    return (
        <section
            className="vy-curriculum-studio"
            data-narrow={narrow}
            aria-label={strings.curriculum.heading}
        >
            <div className="vy-studio-outline">
                <div className="vy-outline-intro">
                    <h3>{studio.outline}</h3>
                    {!studioMode && <p>{studio.outlineIntro}</p>}
                    <small>
                        {studio.structure(
                            activeModules.length,
                            activeModules.reduce(
                                (sum, module) => sum + module.lessons.filter(isNotArchived).length,
                                0,
                            ),
                        )}
                    </small>
                </div>
                <TextInput
                    aria-label={studio.findLesson}
                    placeholder={studio.findLesson}
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setExpanded(new Set(activeModules.map((m) => m.id)));
                    }}
                />
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setExpanded(new Set(activeModules.map((m) => m.id)))}
                    >
                        {studio.expandAll}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setExpanded(new Set())}>
                        {studio.collapseAll}
                    </Button>
                </div>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    {!studioMode && (
                        <h3 className="font-display text-sm font-bold text-fg">
                            {strings.curriculum.heading}
                        </h3>
                    )}
                    <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
                    <div className={studioMode || narrow ? 'w-full' : 'ml-auto'}>
                        <Button
                            size="sm"
                            className={studioMode ? 'w-full' : undefined}
                            onClick={() => setModuleDialog({ module: null })}
                        >
                            <Icon icon={Plus} size={14} />
                            {strings.header.addModule}
                        </Button>
                    </div>
                </div>

                {note !== null && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-[12.5px] text-fg">
                        <span className="min-w-0 flex-1">{note}</span>
                        <Button variant="outline" size="sm" onClick={() => setNote(null)}>
                            {strings.curriculum.archive.dismiss}
                        </Button>
                    </div>
                )}

                {archivedCount > 0 && (
                    <CheckboxField
                        label={strings.curriculum.archive.show(archivedCount)}
                        checked={showArchived}
                        onChange={(event) => setShowArchived(event.target.checked)}
                    />
                )}

                {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}

                {activeModules.length === 0 && archivedModules.length === 0 ? (
                    <EmptyState
                        title={strings.curriculum.empty.title}
                        description={strings.curriculum.empty.body}
                        action={
                            <Button size="sm" onClick={() => setModuleDialog({ module: null })}>
                                {strings.curriculum.empty.action}
                            </Button>
                        }
                    />
                ) : (
                    <ul className="grid gap-3.5">
                        {activeModules.map((module, index) => (
                            <li
                                key={module.id}
                                hidden={
                                    search.trim() !== '' &&
                                    ![module.title, ...module.lessons.map((l) => l.title)].some((title) =>
                                        title.toLowerCase().includes(search.trim().toLowerCase()),
                                    )
                                }
                            >
                                <DropTarget
                                    over={moduleDrag.isOver(index)}
                                    dragging={moduleDrag.isDragging(index)}
                                    className="rounded-lg border border-border bg-surface"
                                    {...moduleDrag.rowProps(index)}
                                >
                                    <ModuleRow
                                        module={module}
                                        index={index}
                                        total={activeModules.length}
                                        expanded={expanded.has(module.id)}
                                        narrow
                                        onToggle={() =>
                                            setExpanded((current) => {
                                                const next = new Set(current);
                                                if (next.has(module.id)) {
                                                    next.delete(module.id);
                                                } else {
                                                    next.add(module.id);
                                                }
                                                return next;
                                            })
                                        }
                                        onMoveUp={() => moveModule(index, index - 1)}
                                        onMoveDown={() => moveModule(index, index + 1)}
                                        onEdit={() => setModuleDialog({ module })}
                                        onArchive={() =>
                                            openArchive({
                                                kind: 'module',
                                                id: module.id,
                                                title: module.title,
                                            })
                                        }
                                    />
                                    {expanded.has(module.id) && (
                                        <LessonList
                                            moduleId={module.id}
                                            lessons={lessonsOf(module)}
                                            lessonTypes={lessonTypes}
                                            narrow
                                            selectedId={lessonDialog?.lesson?.id}
                                            search={
                                                module.title
                                                    .toLowerCase()
                                                    .includes(search.trim().toLowerCase())
                                                    ? ''
                                                    : search.trim()
                                            }
                                            showArchived={showArchived}
                                            onMove={(from, to) => moveLesson(module, from, to)}
                                            onEdit={(lesson) =>
                                                selectLesson({
                                                    moduleId: module.id,
                                                    lesson,
                                                    type: lesson.type,
                                                })
                                            }
                                            onAdd={(type) =>
                                                selectLesson({ moduleId: module.id, lesson: null, type })
                                            }
                                            onArchive={(lesson) =>
                                                openArchive({
                                                    kind: 'lesson',
                                                    id: lesson.id,
                                                    title: lesson.title,
                                                })
                                            }
                                            onRestore={(lesson) =>
                                                restore({
                                                    kind: 'lesson',
                                                    id: lesson.id,
                                                    title: lesson.title,
                                                })
                                            }
                                            moduleTitle={module.title}
                                        />
                                    )}
                                </DropTarget>
                            </li>
                        ))}
                    </ul>
                )}

                {showArchived && archivedModules.length > 0 && (
                    <section className="grid gap-2" aria-label={strings.curriculum.archive.section}>
                        <h4 className="font-display text-xs font-semibold text-fg">
                            {strings.curriculum.archive.section}
                        </h4>
                        <p className="text-[11.5px] text-fg-2">{strings.curriculum.archive.sectionBody}</p>
                        <ul className="grid gap-2">
                            {archivedModules.map((module) => (
                                <li
                                    key={module.id}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3',
                                        narrow && 'flex-wrap',
                                    )}
                                >
                                    <span className="min-w-0 flex-1 truncate font-display text-[13.5px] font-bold text-fg-2">
                                        {module.title}
                                    </span>
                                    <span className="shrink-0 text-[11.5px] text-fg-2">
                                        {strings.curriculum.moduleLessons(module.lessons.length)}
                                    </span>
                                    <StateText state={module.state} />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            restore({ kind: 'module', id: module.id, title: module.title })
                                        }
                                    >
                                        <span className="sr-only">
                                            {strings.curriculum.archive.restoreModule(module.title)}
                                        </span>
                                        <span aria-hidden="true">{strings.curriculum.archive.restore}</span>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
            <div className="vy-studio-editor">
                {lessonDialog === null ? (
                    <div className="vy-studio-empty">
                        <span aria-hidden="true">✦</span>
                        <h3>{studio.selectLesson}</h3>
                        <p>{studio.selectLessonBody}</p>
                    </div>
                ) : studioMode && currentLesson?.type === 'quiz' ? (
                    <QuizLessonEditor
                        key={currentLesson.id}
                        courseId={courseId}
                        lesson={currentLesson}
                        onSaved={(lesson) =>
                            setLessonDialog({ moduleId: lessonDialog.moduleId, lesson, type: lesson.type })
                        }
                    />
                ) : (
                    <LessonDialog
                        key={`${lessonDialog.moduleId}-${lessonDialog.lesson?.id ?? `new-${lessonDialog.type}`}`}
                        inline
                        keepOpen={studioMode}
                        courseId={courseId}
                        moduleId={lessonDialog.moduleId}
                        lesson={
                            studioMode && lessonDialog.lesson !== null
                                ? (serverModules
                                      .flatMap((module) => module.lessons)
                                      .find((lesson) => lesson.id === lessonDialog.lesson?.id) ??
                                  lessonDialog.lesson)
                                : lessonDialog.lesson
                        }
                        type={lessonDialog.type}
                        onOpenQuiz={onOpenQuiz}
                        onSaved={
                            studioMode
                                ? (lesson) =>
                                      setLessonDialog({
                                          moduleId: lessonDialog.moduleId,
                                          lesson,
                                          type: lesson.type,
                                      })
                                : undefined
                        }
                        onClose={() => setLessonDialog(null)}
                    />
                )}
            </div>
            <Dialog
                open={pendingLesson !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingLesson(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{strings.lesson.unsaved}</DialogTitle>
                    <DialogDescription>{strings.lesson.keepEditing}</DialogDescription>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPendingLesson(null)}>
                            {strings.lesson.keepEditing}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setLessonDialog(pendingLesson);
                                setPendingLesson(null);
                            }}
                        >
                            {strings.lesson.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {moduleDialog !== null && (
                <ModuleDialog
                    courseId={courseId}
                    module={moduleDialog.module}
                    onClose={() => setModuleDialog(null)}
                />
            )}
            {archiveTarget !== null && (
                <ArchiveDialog
                    courseId={courseId}
                    target={archiveTarget}
                    onClose={() => setArchiveTarget(null)}
                    draftWarning={archiveHasDraft}
                    onArchived={(message) => {
                        report(message);
                        if (affectsEditor(archiveTarget)) setLessonDialog(null);
                    }}
                />
            )}
        </section>
    );
}

function ModuleRow({
    module,
    index,
    total,
    expanded,
    narrow,
    onToggle,
    onMoveUp,
    onMoveDown,
    onEdit,
    onArchive,
}: {
    module: AuthoringModule;
    index: number;
    total: number;
    expanded: boolean;
    narrow: boolean;
    onToggle: () => void;
    onMoveUp: () => void;
    onMoveDown: () => void;
    onEdit: () => void;
    onArchive: () => void;
}) {
    const archivedLessons = module.lessons.filter(isArchived).length;
    return (
        <div
            className={cn(
                'vy-outline-module flex items-center gap-3 px-4 py-3',
                expanded && 'border-b border-border/60',
                narrow && 'flex-wrap',
            )}
        >
            <DragHandle
                label={strings.curriculum.dragModule(module.title)}
                canMoveUp={index > 0}
                canMoveDown={index < total - 1}
                onMoveUp={onMoveUp}
                onMoveDown={onMoveDown}
            />
            <span className="min-w-0 flex-1 truncate font-display text-[13.5px] font-bold text-fg">
                {module.title}
            </span>
            <span className="shrink-0 text-[11.5px] text-fg-2">
                {strings.curriculum.moduleLessons(module.lessons.filter(isNotArchived).length)}
                {archivedLessons > 0 && uiText("vidyalms.ui.04420b9dfe3f0a87", " · {p0}", { p0: strings.curriculum.archive.moduleLessons(archivedLessons) })}
            </span>
            {module.state !== 'published' && <StateText state={module.state} />}
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={expanded}
                aria-label={
                    expanded
                        ? strings.curriculum.collapseModule(module.title)
                        : strings.curriculum.expandModule(module.title)
                }
                className="flex h-[30px] w-[30px] shrink-0 cursor-pointer items-center justify-center rounded border border-border bg-surface text-fg"
            >
                <Icon icon={expanded ? ChevronDown : ChevronRight} size={14} />
            </button>
            <details className="vy-outline-actions">
                <summary aria-label={studio.moduleActions}>•••</summary>
                <div>
                    <OrderControls
                        upLabel={strings.curriculum.moveModuleUp(module.title)}
                        downLabel={strings.curriculum.moveModuleDown(module.title)}
                        canMoveUp={index > 0}
                        canMoveDown={index < total - 1}
                        onMoveUp={onMoveUp}
                        onMoveDown={onMoveDown}
                    />
                    <Button variant="outline" size="sm" onClick={onEdit}>
                        <span className="sr-only">{strings.curriculum.editModuleNamed(module.title)}</span>
                        <span aria-hidden="true">{strings.curriculum.edit}</span>
                    </Button>
                    <Button variant="outline" size="sm" onClick={onArchive}>
                        <span className="sr-only">
                            {strings.curriculum.archive.archiveModule(module.title)}
                        </span>
                        <span aria-hidden="true">{strings.curriculum.archive.action}</span>
                    </Button>
                </div>
            </details>
        </div>
    );
}

function LessonList({
    moduleId,
    lessons,
    lessonTypes,
    narrow,
    moduleTitle,
    showArchived,
    selectedId,
    search,
    onMove,
    onEdit,
    onAdd,
    onArchive,
    onRestore,
}: {
    moduleId: number;
    lessons: AuthoringLesson[];
    lessonTypes: LessonTypeInfo[];
    narrow: boolean;
    moduleTitle: string;
    showArchived: boolean;
    selectedId?: number;
    search: string;
    onMove: (from: number, to: number) => void;
    onEdit: (lesson: AuthoringLesson) => void;
    onAdd: (type: string) => void;
    onArchive: (lesson: AuthoringLesson) => void;
    onRestore: (lesson: AuthoringLesson) => void;
}) {
    const drag = useListDrag({ listId: `lessons-${moduleId}`, onDrop: onMove });
    const active = lessons.filter(isNotArchived);
    const archived = lessons.filter(isArchived);
    return (
        <div>
            {active.length === 0 ? (
                <div className="px-4 py-4 pl-10">
                    <EmptyState
                        title={strings.curriculum.emptyModule.title}
                        description={strings.curriculum.emptyModule.body}
                    />
                </div>
            ) : (
                <ul>
                    {active.map((lesson, index) => (
                        <li
                            key={lesson.id}
                            hidden={!lesson.title.toLowerCase().includes(search.toLowerCase())}
                            data-selected={lesson.id === selectedId}
                        >
                            <DropTarget
                                over={drag.isOver(index)}
                                dragging={drag.isDragging(index)}
                                className={cn(
                                    'vy-outline-lesson flex items-center gap-3 border-b border-border/60 py-2.5 pl-10 pr-4 text-[13px]',
                                    narrow && 'flex-wrap pl-4',
                                )}
                                {...drag.rowProps(index)}
                            >
                                <DragHandle
                                    label={strings.curriculum.dragLesson(lesson.title)}
                                    canMoveUp={index > 0}
                                    canMoveDown={index < active.length - 1}
                                    onMoveUp={() => onMove(index, index - 1)}
                                    onMoveDown={() => onMove(index, index + 1)}
                                />
                                <LessonTypeChip type={lesson.type} />
                                <span className="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        className="vy-outline-lesson-title"
                                        aria-current={lesson.id === selectedId ? 'true' : undefined}
                                        onClick={() => onEdit(lesson)}
                                    >
                                        {lesson.title}
                                    </button>
                                    {typeof lesson.content.caption_url === 'string' && (
                                        <span className="ml-1.5 text-[10.5px] text-fg-2">
                                            · {strings.curriculum.captions} ✓
                                        </span>
                                    )}
                                    {lesson.quiz !== null && (
                                        <span className="ml-1.5 text-[10.5px] text-fg-2">
                                            ·{' '}
                                            {strings.curriculum.quizMeta(
                                                lesson.quiz.question_count,
                                                lesson.quiz.pass_mark,
                                            )}
                                        </span>
                                    )}
                                </span>
                                <StateText state={lesson.state} />
                                <details className="vy-outline-actions">
                                    <summary aria-label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: studio.lessonActions, p1: lesson.title })}>
                                        •••
                                    </summary>
                                    <div>
                                        <OrderControls
                                            size="sm"
                                            upLabel={strings.curriculum.moveLessonUp(lesson.title)}
                                            downLabel={strings.curriculum.moveLessonDown(lesson.title)}
                                            canMoveUp={index > 0}
                                            canMoveDown={index < active.length - 1}
                                            onMoveUp={() => onMove(index, index - 1)}
                                            onMoveDown={() => onMove(index, index + 1)}
                                        />
                                        <Button variant="outline" size="sm" onClick={() => onEdit(lesson)}>
                                            <span className="sr-only">
                                                {strings.curriculum.editLesson(lesson.title)}
                                            </span>
                                            <span aria-hidden="true">{strings.curriculum.edit}</span>
                                        </Button>
                                        <Button variant="outline" size="sm" onClick={() => onArchive(lesson)}>
                                            <span className="sr-only">
                                                {strings.curriculum.archive.archiveLesson(lesson.title)}
                                            </span>
                                            <span aria-hidden="true">
                                                {strings.curriculum.archive.action}
                                            </span>
                                        </Button>
                                    </div>
                                </details>
                            </DropTarget>
                        </li>
                    ))}
                </ul>
            )}

            {showArchived && archived.length > 0 && (
                <div className="border-b border-border/60 py-2.5 pl-10 pr-4">
                    <p className="mb-1.5 font-display text-[11px] font-semibold text-fg-2">
                        {strings.curriculum.archive.lessonsHeading}
                    </p>
                    <ul className="grid gap-1.5">
                        {archived.map((lesson) => (
                            <li
                                key={lesson.id}
                                className={cn(
                                    'flex items-center gap-3 text-[13px] text-fg-2',
                                    narrow && 'flex-wrap',
                                )}
                            >
                                <LessonTypeChip type={lesson.type} />
                                <span className="min-w-0 flex-1 truncate">{lesson.title}</span>
                                <StateText state={lesson.state} />
                                <Button variant="outline" size="sm" onClick={() => onRestore(lesson)}>
                                    <span className="sr-only">
                                        {strings.curriculum.archive.restoreLesson(lesson.title)}
                                    </span>
                                    <span aria-hidden="true">{strings.curriculum.archive.restore}</span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="px-4 py-3 pl-10">
                <AddLessonMenu types={lessonTypes} moduleTitle={moduleTitle} onSelect={onAdd} />
            </div>
        </div>
    );
}
