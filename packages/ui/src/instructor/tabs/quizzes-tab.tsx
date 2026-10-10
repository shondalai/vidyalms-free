import { useEffect, useId, useMemo, useState } from 'react';
import { Plus, Settings2 } from 'lucide-react';
import type { AuthoringQuestion, QuestionTypeInfo, QuizSummary } from '@vidyalms/api-sdk';
import type { QuestionDefinition } from '@/question-types';
import { cn } from '@/shared/cn';
import { useAnnounce } from '@/shared/a11y';
import { Button, EmptyState, ErrorState, Icon, SaveIndicator } from '@/shared/components';
import { useCourseQuizzes, useQuestionTypes, useQuizAuthoring, useReorderQuestions } from '../api';
import { instructorStrings as strings } from '../strings';
import { moveItem } from '../lib/reorder';
import { useSaveTracker } from '../lib/save-state';
import { deniedFor } from '../lib/problems';
import { needsManualMarking } from '../lib/question-definitions';
import {
    findPickerEntry,
    pickerGroups,
    seedDefinition,
    seedScored,
    type PickerGroup,
} from '../lib/question-picker';
import { pointsText } from '../lib/format';
import { QuestionTypeChip } from '../components/chips';
import { DragHandle, DropTarget, OrderControls, useListDrag } from '../components/order-controls';
import { ListSkeleton, PermissionDenied } from '../components/states';
import { Field, Select } from '../components/field';
import { QuizSettingsDialog } from './quiz-settings-dialog';
import { QuestionDialog } from './question-dialog';
import { assessmentStrings } from '../assessment-strings';
import { Tabs, TabPanel, type TabDefinition } from '../components/tabs';
import { QuizPolicyDialog } from './quiz-policy-dialog';
import { RegradeDialog } from './regrade-dialog';
import { SlotsEditor } from './slots-editor';
import { BankManager } from './bank-manager';
import { RubricsPanel } from './rubric-editor';
import { ExportDialog } from './export-dialog';
import { hasRoute, useInstructorConfig } from '../config';
import { flowStrings } from '../flow-strings';

/*
 * Artboard 2a: the quizzes of a course, and for the chosen one its settings
 * summary and its ordered question list. The type picker of "Add question"
 * lists exactly what GET /question-types answers, which is why a free site
 * offers six and nothing here mentions the ones it does not have.
 *
 * Four sections rather than one, because the four are different objects with
 * different lifetimes: the questions of this quiz, the STRUCTURE that serves
 * them (sections, fixed placements and random draws), the BANK they can be
 * drawn from, which belongs to a scope and not to this quiz, and the RUBRICS
 * a marker grades against. Putting the bank inside one quiz's question list
 * is what makes a bank feel like a quiz's private property, which is the
 * ownership model this design exists to avoid.
 */

type AuthoringTab = 'questions' | 'slots' | 'bank' | 'rubrics';

export function QuizzesTab({
    courseId,
    narrow,
    initialQuizId = null,
}: {
    courseId: number;
    narrow: boolean;
    initialQuizId?: number | null;
}) {
    const quizzesQuery = useCourseQuizzes(courseId);
    const [selectedId, setSelectedId] = useState<number | null>(initialQuizId);
    const [settingsFor, setSettingsFor] = useState<{ quiz: QuizSummary | null } | null>(null);

    const quizzes = useMemo(() => quizzesQuery.data ?? [], [quizzesQuery.data]);

    useEffect(() => {
        if (quizzes.length === 0) {
            setSelectedId(null);
            return;
        }
        setSelectedId((current) =>
            current !== null && quizzes.some((quiz) => quiz.id === current)
                ? current
                : (quizzes[0]?.id ?? null),
        );
    }, [quizzes]);

    if (quizzesQuery.isPending) {
        return <ListSkeleton label={strings.states.loading} rows={3} />;
    }

    if (quizzesQuery.isError) {
        if (deniedFor(quizzesQuery.error)) {
            return <PermissionDenied />;
        }
        return (
            <ErrorState
                error={quizzesQuery.error}
                title={strings.quizzes.error.title}
                onRetry={() => void quizzesQuery.refetch()}
            />
        );
    }

    if (quizzes.length === 0) {
        return (
            <>
                <EmptyState
                    title={strings.quizzes.empty.title}
                    description={strings.quizzes.empty.body}
                    action={
                        <Button size="sm" onClick={() => setSettingsFor({ quiz: null })}>
                            {strings.quizzes.empty.action}
                        </Button>
                    }
                />
                {settingsFor !== null && (
                    <QuizSettingsDialog
                        courseId={courseId}
                        quiz={settingsFor.quiz}
                        onCreated={setSelectedId}
                        onClose={() => setSettingsFor(null)}
                    />
                )}
            </>
        );
    }

    const selected = quizzes.find((quiz) => quiz.id === selectedId) ?? null;

    return (
        <div
            className={cn('grid gap-4', !narrow && quizzes.length > 1 && 'grid-cols-[260px_1fr] items-start')}
        >
            {quizzes.length > 1 &&
                (narrow ? (
                    <Field label={strings.quizzes.selectQuiz}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={selectedId === null ? '' : String(selectedId)}
                                onChange={(event) => setSelectedId(Number(event.target.value))}
                            >
                                {quizzes.map((quiz) => (
                                    <option key={quiz.id} value={String(quiz.id)}>
                                        {quiz.title}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                ) : (
                    <nav
                        aria-label={strings.quizzes.heading}
                        className="rounded-lg border border-border bg-surface"
                    >
                        <ul>
                            {quizzes.map((quiz) => (
                                <li key={quiz.id} className="border-b border-border/60 last:border-b-0">
                                    <button
                                        type="button"
                                        aria-current={quiz.id === selectedId ? 'true' : undefined}
                                        onClick={() => setSelectedId(quiz.id)}
                                        className={cn(
                                            'w-full cursor-pointer px-3.5 py-2.5 text-left text-[12.5px]',
                                            quiz.id === selectedId
                                                ? 'bg-accent-soft font-semibold text-fg'
                                                : 'text-fg-2 hover:text-fg',
                                        )}
                                    >
                                        <span className="block truncate">{quiz.title}</span>
                                        <span className="block truncate text-[11px] text-fg-2">
                                            {quiz.lesson === null
                                                ? strings.quizzes.unlinked
                                                : strings.quizzes.inModule(quiz.lesson.module_title)}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        <div className="border-t border-border p-2.5">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setSettingsFor({ quiz: null })}
                            >
                                <Icon icon={Plus} size={13} />
                                {strings.quizzes.addQuiz}
                            </Button>
                        </div>
                    </nav>
                ))}

            <div className="min-w-0">
                {selected !== null && (
                    <QuizPanel
                        key={selected.id}
                        courseId={courseId}
                        quiz={selected}
                        narrow={narrow}
                        onlyQuiz={quizzes.length === 1}
                        onSettings={() => setSettingsFor({ quiz: selected })}
                        onAddQuiz={() => setSettingsFor({ quiz: null })}
                    />
                )}
            </div>

            {settingsFor !== null && (
                <QuizSettingsDialog
                    courseId={courseId}
                    quiz={settingsFor.quiz}
                    onCreated={setSelectedId}
                    onClose={() => setSettingsFor(null)}
                />
            )}
        </div>
    );
}

function settingChips(quiz: QuizSummary): string[] {
    const chips = [strings.quizzes.passMark(quiz.pass_mark)];
    chips.push(
        quiz.time_limit_seconds === null
            ? strings.quizzes.untimed
            : strings.quizzes.timeLimit(Math.max(1, Math.round(quiz.time_limit_seconds / 60))),
    );
    chips.push(
        quiz.max_attempts === null
            ? strings.quizzes.unlimitedAttempts
            : strings.quizzes.attempts(quiz.max_attempts),
    );
    chips.push(strings.quizzes.showResults[quiz.show_results]);
    if (quiz.is_final) {
        chips.push(strings.quizzes.isFinal);
    }
    return chips;
}

function QuizPanel({
    courseId,
    quiz,
    narrow,
    onlyQuiz,
    onSettings,
    onAddQuiz,
}: {
    courseId: number;
    quiz: QuizSummary;
    narrow: boolean;
    onlyQuiz: boolean;
    onSettings: () => void;
    onAddQuiz: () => void;
}) {
    const authoringQuery = useQuizAuthoring(quiz.id);
    const questionTypesQuery = useQuestionTypes();
    const reorderQuestions = useReorderQuestions();
    const save = useSaveTracker();
    const announce = useAnnounce();
    const [pendingOrder, setPendingOrder] = useState<number[] | null>(null);
    const [editing, setEditing] = useState<{
        question: AuthoringQuestion | null;
        type: string;
        seed: QuestionDefinition;
        /** Whether a new question counts; set from the picker entry, absent on an edit. */
        scored?: boolean;
    } | null>(null);
    const [addType, setAddType] = useState('');
    const [section, setSection] = useState<AuthoringTab>('questions');
    const [policyOpen, setPolicyOpen] = useState(false);
    const [regradeOpen, setRegradeOpen] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);
    const config = useInstructorConfig();

    const serverQuestions = useMemo(() => authoringQuery.data?.questions ?? [], [authoringQuery.data]);
    const questions = useMemo(() => {
        if (pendingOrder === null) {
            return serverQuestions;
        }
        const byId = new Map(serverQuestions.map((question) => [question.id, question] as const));
        const ordered = pendingOrder.flatMap((id) => {
            const question = byId.get(id);
            return question === undefined ? [] : [question];
        });
        return ordered.length === serverQuestions.length ? ordered : serverQuestions;
    }, [serverQuestions, pendingOrder]);

    // Cleared either way: a refused order left on screen is the order the
    // reader was told took, and the server never accepted it. The mutation
    // invalidates the course on both outcomes, so the list comes back from
    // the server and the retry keeps its key.
    const move = (from: number, to: number) => {
        const next = moveItem(questions, from, to);
        const ids = next.map((question) => question.id);
        setPendingOrder(ids);
        announce(strings.quizzes.movedQuestion(to + 1, next.length));
        void save
            .run((idempotencyKey) =>
                reorderQuestions.mutateAsync({ courseId, quizId: quiz.id, questionIds: ids, idempotencyKey }),
            )
            .then((ok) => {
                setPendingOrder(null);
                if (!ok) {
                    announce(strings.quizzes.moveRefused);
                }
            });
    };

    const drag = useListDrag({ listId: `questions-${quiz.id}`, onDrop: move });
    const registry = questionTypesQuery.data;
    const questionTypes: QuestionTypeInfo[] = useMemo(() => registry?.types ?? [], [registry]);
    const groups = useMemo(
        () => pickerGroups(questionTypes, registry?.presets ?? []),
        [questionTypes, registry],
    );
    const entries = useMemo(() => groups.flatMap((group) => group.entries), [groups]);
    const labelOf = (type: string) => questionTypes.find((entry) => entry.key === type)?.label ?? type;
    const slotsAvailable = hasRoute(config, '/manage/quizzes/{id}/slots');
    const bankAvailable =
        hasRoute(config, '/manage/banks/categories') && hasRoute(config, '/manage/banks/entries');
    const rubricsAvailable = hasRoute(config, '/manage/rubrics');
    const authoringTabs: TabDefinition<AuthoringTab>[] = [
        { id: 'questions', label: assessmentStrings.tabs.questions, badge: questions.length },
        ...(slotsAvailable ? [{ id: 'slots' as const, label: assessmentStrings.tabs.slots }] : []),
        ...(bankAvailable ? [{ id: 'bank' as const, label: assessmentStrings.tabs.bank }] : []),
        ...(rubricsAvailable ? [{ id: 'rubrics' as const, label: assessmentStrings.tabs.rubrics }] : []),
    ];

    return (
        <section className="rounded-lg border border-border bg-surface" aria-label={quiz.title}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-5 py-3.5">
                <div className="min-w-0">
                    <h3 className="truncate font-display text-[15px] font-bold text-fg">{quiz.title}</h3>
                    <p className="truncate text-[11.5px] text-fg-2">
                        {quiz.lesson === null
                            ? strings.quizzes.unlinked
                            : strings.quizzes.inModule(`${quiz.lesson.module_title} · ${quiz.lesson.title}`)}
                    </p>
                </div>
                <div className={narrow ? 'flex w-full flex-wrap gap-2' : 'ml-auto flex flex-wrap gap-2'}>
                    {onlyQuiz && (
                        <Button variant="outline" size="sm" onClick={onAddQuiz}>
                            <Icon icon={Plus} size={13} />
                            {strings.quizzes.addQuiz}
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={onSettings}>
                        <Icon icon={Settings2} size={13} />
                        {strings.quizzes.settings}
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setPolicyOpen(true)}>
                        {assessmentStrings.policy.button}
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setRegradeOpen(true)}>
                        {assessmentStrings.regrade.button}
                    </Button>
                    {/* Only where the host says it routes the export. A
                        control that would 404 is worse than no control, and
                        the bundle and the extension ship separately. */}
                    {hasRoute(config, '/manage/quizzes/{id}/export') && (
                        <Button variant="outline" size="sm" onClick={() => setExportOpen(true)}>
                            {flowStrings.export.button}
                        </Button>
                    )}
                    {section === 'questions' && (
                        <AddQuestionControl
                            groups={groups}
                            value={addType}
                            onChange={setAddType}
                            onAdd={(key) => {
                                const entry = findPickerEntry(entries, key);
                                if (entry !== null) {
                                    setEditing({
                                        question: null,
                                        type: entry.type,
                                        seed: seedDefinition(entry),
                                        scored: seedScored(entry),
                                    });
                                }
                            }}
                        />
                    )}
                </div>
            </div>

            <div className="flex flex-wrap gap-2 px-5 py-3">
                {settingChips(quiz).map((chip) => (
                    <span
                        key={chip}
                        className="rounded-full border border-border px-2.5 py-1 text-[11.5px] font-semibold text-fg-2"
                    >
                        {chip}
                    </span>
                ))}
                <SaveIndicator
                    className="ml-auto"
                    state={save.state}
                    savedAt={save.savedAt}
                    onRetry={save.retry}
                />
            </div>

            <Tabs
                className="px-5"
                label={assessmentStrings.tabs.subTabsLabel}
                tabs={authoringTabs}
                current={section}
                onSelect={setSection}
            />

            {section === 'slots' ? (
                <TabPanel id="quiz-slots">
                    <div className="p-4">
                        <SlotsEditor courseId={courseId} quizId={quiz.id} />
                    </div>
                </TabPanel>
            ) : section === 'bank' ? (
                <TabPanel id="quiz-bank">
                    <div className="p-4">
                        <BankManager courseId={courseId} narrow={narrow} />
                    </div>
                </TabPanel>
            ) : section === 'rubrics' ? (
                <TabPanel id="quiz-rubrics">
                    <div className="p-4">
                        <RubricsPanel courseId={courseId} />
                    </div>
                </TabPanel>
            ) : authoringQuery.isPending ? (
                <ListSkeleton label={strings.states.loading} rows={3} />
            ) : authoringQuery.isError ? (
                <div className="p-4">
                    {deniedFor(authoringQuery.error) ? (
                        <PermissionDenied />
                    ) : (
                        <ErrorState
                            error={authoringQuery.error}
                            title={strings.quizzes.error.title}
                            onRetry={() => void authoringQuery.refetch()}
                        />
                    )}
                </div>
            ) : questions.length === 0 ? (
                <div className="p-4">
                    <EmptyState
                        title={strings.quizzes.emptyQuestions.title}
                        description={strings.quizzes.emptyQuestions.body}
                    />
                </div>
            ) : (
                <ul className="border-t border-border">
                    {questions.map((question, index) => {
                        const manual = needsManualMarking(question.type, question.definition);
                        return (
                            <li key={question.id}>
                                <DropTarget
                                    over={drag.isOver(index)}
                                    dragging={drag.isDragging(index)}
                                    className={cn(
                                        'flex items-center gap-2.5 border-b border-border/60 px-4 py-2.5 text-[13px]',
                                        manual && 'bg-warn-soft',
                                        narrow && 'flex-wrap',
                                    )}
                                    {...drag.rowProps(index)}
                                >
                                    <DragHandle
                                        label={strings.quizzes.dragQuestion(index + 1)}
                                        canMoveUp={index > 0}
                                        canMoveDown={index < questions.length - 1}
                                        onMoveUp={() => move(index, index - 1)}
                                        onMoveDown={() => move(index, index + 1)}
                                    />
                                    <span
                                        aria-hidden="true"
                                        className="w-4 shrink-0 font-mono text-[11px] font-bold text-fg-2"
                                    >
                                        {index + 1}
                                    </span>
                                    <QuestionTypeChip type={question.type} />
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-1">
                                            {typeof question.definition.prompt === 'string'
                                                ? question.definition.prompt
                                                : labelOf(question.type)}
                                        </span>
                                        {manual && (
                                            <span className="text-[10.5px] font-semibold text-warn-strong">
                                                {strings.quizzes.needsMarking}
                                            </span>
                                        )}
                                    </span>
                                    <span className="shrink-0 text-[11px] text-fg-2">
                                        {strings.quizzes.points(Number(pointsText(question.points)))}
                                    </span>
                                    <OrderControls
                                        size="sm"
                                        upLabel={strings.quizzes.moveQuestionUp(index + 1)}
                                        downLabel={strings.quizzes.moveQuestionDown(index + 1)}
                                        canMoveUp={index > 0}
                                        canMoveDown={index < questions.length - 1}
                                        onMoveUp={() => move(index, index - 1)}
                                        onMoveDown={() => move(index, index + 1)}
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setEditing({ question, type: question.type, seed: {} })
                                        }
                                    >
                                        <span className="sr-only">
                                            {strings.quizzes.editQuestion(index + 1)}
                                        </span>
                                        <span aria-hidden="true">{strings.quizzes.edit}</span>
                                    </Button>
                                </DropTarget>
                            </li>
                        );
                    })}
                </ul>
            )}

            {editing !== null && (
                <QuestionDialog
                    courseId={courseId}
                    quizId={quiz.id}
                    question={editing.question}
                    type={editing.type}
                    seed={editing.seed}
                    scored={editing.scored}
                    typeLabel={labelOf(editing.type)}
                    position={
                        editing.question === null
                            ? questions.length + 1
                            : questions.findIndex((entry) => entry.id === editing.question?.id) + 1
                    }
                    onClose={() => setEditing(null)}
                />
            )}

            {policyOpen && (
                <QuizPolicyDialog courseId={courseId} quiz={quiz} onClose={() => setPolicyOpen(false)} />
            )}

            {regradeOpen && (
                <RegradeDialog
                    courseId={courseId}
                    quiz={quiz}
                    questions={questions}
                    onClose={() => setRegradeOpen(false)}
                />
            )}

            {exportOpen && <ExportDialog quizId={quiz.id} onClose={() => setExportOpen(false)} />}
        </section>
    );
}

/**
 * The type picker of "Add question": a select of exactly what the registry
 * answered, grouped by family, plus the button that opens the editor for the
 * chosen entry.
 *
 * The families are real `optgroup` elements rather than styled headings,
 * which is the whole of the accessibility work here: the grouping is
 * announced, the group is not selectable, and the control keeps native
 * keyboard, touch and voice control behaviour with no ARIA of its own
 * (SC 1.3.1, SC 4.1.2). A single family renders as a plain list, because a
 * heading over the only group is noise.
 */
function AddQuestionControl({
    groups,
    value,
    onChange,
    onAdd,
}: {
    groups: PickerGroup[];
    value: string;
    onChange: (key: string) => void;
    onAdd: (key: string) => void;
}) {
    const selectId = useId();
    const entries = groups.flatMap((group) => group.entries);
    const chosen = value === '' ? (entries[0]?.key ?? '') : value;
    const options = (group: PickerGroup) =>
        group.entries.map((entry) => (
            <option key={entry.key} value={entry.key}>
                {entry.label}
            </option>
        ));
    return (
        <span className="flex items-center gap-2">
            <label className="sr-only" htmlFor={selectId}>
                {strings.question.typeLabel}
            </label>
            <Select
                id={selectId}
                className="h-9 w-[190px]"
                value={chosen}
                onChange={(event) => onChange(event.target.value)}
                disabled={entries.length === 0}
            >
                {groups.length === 1 && groups[0] !== undefined
                    ? options(groups[0])
                    : groups.map((group) => (
                          <optgroup key={group.family} label={familyLabel(group.family)}>
                              {options(group)}
                          </optgroup>
                      ))}
            </Select>
            <Button size="sm" disabled={chosen === ''} onClick={() => onAdd(chosen)}>
                <Icon icon={Plus} size={13} />
                {strings.quizzes.addQuestion}
            </Button>
        </span>
    );
}

/** A family a later package invented keeps its key rather than going nameless. */
function familyLabel(family: string): string {
    return strings.question.families[family] ?? family;
}
