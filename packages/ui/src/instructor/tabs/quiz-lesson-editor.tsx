import { useMemo, useState } from 'react';
import { ChevronDown, CircleHelp, Clock3, Plus, Search } from 'lucide-react';
import type { AuthoringLesson, AuthoringQuestion } from '@vidyalms/api-sdk';
import type { QuestionDefinition } from '@/question-types';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    EmptyState,
    ErrorState,
    Icon,
    SaveIndicator,
} from '@/shared/components';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { useAnnounce } from '@/shared/a11y';
import { useAuthoringCurriculum, useQuestionTypes, useQuizAuthoring, useReorderQuestions } from '../api';
import { hasRoute, useInstructorConfig } from '../config';
import { DragHandle, DropTarget, OrderControls, useListDrag } from '../components/order-controls';
import { ListSkeleton, PermissionDenied, SaveError } from '../components/states';
import { Tabs, TabPanel, type TabDefinition } from '../components/tabs';
import { TextInput } from '../components/field';
import { QuestionTypeChip } from '../components/chips';
import { moveItem } from '../lib/reorder';
import { useSaveTracker } from '../lib/save-state';
import { deniedFor } from '../lib/problems';
import { needsManualMarking } from '../lib/question-definitions';
import { pickerGroups, seedDefinition, seedScored, type PickerEntry } from '../lib/question-picker';
import { instructorStrings as strings } from '../strings';
import { builderStrings as builder } from '../builder-strings';
import { quizStudioStrings as s } from '../quiz-studio-strings';
import { assessmentStrings } from '../assessment-strings';
import { flowStrings } from '../flow-strings';
import { QuestionDialog } from './question-dialog';
import { QuizSettingsDialog } from './quiz-settings-dialog';
import { QuizPolicyDialog } from './quiz-policy-dialog';
import { SlotsEditor } from './slots-editor';
import { RubricsPanel } from './rubric-editor';
import { RegradeDialog } from './regrade-dialog';
import { ExportDialog } from './export-dialog';
import { LessonDialog } from './lesson-dialog';
import { SubjectRestrictions } from './availability/subject-restrictions';

type Section = keyof typeof s.tabs;
type TabId = `quiz-lesson-${Section}`;
type Editing = {
    question: AuthoringQuestion | null;
    type: string;
    label?: string;
    seed: QuestionDefinition;
    scored?: boolean;
};
type Props = { courseId: number; lesson: AuthoringLesson; onSaved: (lesson: AuthoringLesson) => void };

/** Bound to one curriculum lesson. No course-wide quiz selection or creation here. */
export function QuizLessonEditor(props: Props) {
    const curriculum = useAuthoringCurriculum(props.courseId);
    const id = props.lesson.quiz?.id ?? props.lesson.content.quiz_id;
    if (typeof id !== 'number' || id <= 0)
        return (
            <EmptyState
                title={s.missingTitle}
                description={s.missingBody}
                action={
                    <Button variant="outline" onClick={() => void curriculum.refetch()}>
                        {s.reload}
                    </Button>
                }
            />
        );
    return <QuizLessonContent key={id} {...props} quizId={id} />;
}

function QuizLessonContent({ courseId, lesson, quizId, onSaved }: Props & { quizId: number }) {
    const query = useQuizAuthoring(quizId);
    const registry = useQuestionTypes();
    const reorder = useReorderQuestions();
    const config = useInstructorConfig();
    const announce = useAnnounce();
    const save = useSaveTracker();
    const [section, setSection] = useState<Section>('questions');
    const [grading, setGrading] = useState<'policy' | 'rubrics'>('policy');
    const [pending, setPending] = useState<(() => void) | null>(null);
    const [search, setSearch] = useState('');
    const [typeSearch, setTypeSearch] = useState('');
    const [choosing, setChoosing] = useState(false);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [exporting, setExporting] = useState(false);
    const [regrading, setRegrading] = useState(false);
    const [order, setOrder] = useState<number[] | null>(null);
    const questions = useMemo(() => {
        const stored = query.data?.questions ?? [];
        if (order === null) return stored;
        const map = new Map(stored.map((question) => [question.id, question]));
        return order.flatMap((id) => {
            const question = map.get(id);
            return question ? [question] : [];
        });
    }, [query.data, order]);
    const request = (action: () => void) => {
        if (hasUnsavedWorkspaceChanges()) setPending(() => action);
        else action();
    };
    const move = (from: number, to: number) => {
        if (search.trim() !== '' || save.state === 'saving') return;
        const ids = moveItem(questions, from, to).map((question) => question.id);
        setOrder(ids);
        void save
            .run((idempotencyKey) =>
                reorder.mutateAsync({ courseId, quizId, questionIds: ids, idempotencyKey }),
            )
            .then((ok) => {
                setOrder(null);
                announce(
                    ok ? strings.quizzes.movedQuestion(to + 1, ids.length) : strings.quizzes.moveRefused,
                );
            });
    };
    const drag = useListDrag({ listId: `quiz-lesson-${quizId}`, onDrop: move });
    const groups = pickerGroups(registry.data?.types ?? [], registry.data?.presets ?? []);
    const questionTypeLabel = (type: string) =>
        registry.data?.types.find((entry) => entry.key === type)?.label ?? type;
    const choose = (entry: PickerEntry) => {
        setChoosing(false);
        setEditing({
            question: null,
            type: entry.type,
            label: entry.label,
            seed: seedDefinition(entry),
            scored: seedScored(entry),
        });
    };
    const slots = hasRoute(config, '/manage/quizzes/{id}/slots');
    const tabs: TabDefinition<TabId>[] = (Object.entries(s.tabs) as [Section, string][])
        .filter(
            ([id]) =>
                (id !== 'selection' || slots) &&
                (id !== 'grading' || hasRoute(config, '/manage/quizzes/{id}/settings-schema')),
        )
        .map(([id, label]) => ({ id: `quiz-lesson-${id}`, label }));
    const visible = questions.filter((question) =>
        String(question.definition.prompt ?? '')
            .toLowerCase()
            .includes(search.trim().toLowerCase()),
    );

    if (query.isPending) return <ListSkeleton label={strings.states.loading} rows={4} />;
    if (query.isError)
        return deniedFor(query.error) ? (
            <PermissionDenied />
        ) : (
            <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        );
    const quiz = query.data;
    if (quiz.course_id !== courseId || quiz.lesson_id !== lesson.id)
        return <EmptyState title={s.missingTitle} description={s.missingBody} />;

    return (
        <section className="vy-quiz-studio" aria-label={s.region}>
            <header className="vy-quiz-studio-heading">
                <div>
                    <p className="vy-studio-eyebrow">{s.eyebrow}</p>
                    <h3>{lesson.title}</h3>
                    <p>{s.saved}</p>
                </div>
                <details className="vy-quiz-tools">
                    <summary>
                        {s.more}
                        <Icon icon={ChevronDown} size={14} />
                    </summary>
                    <div>
                        <button type="button" onClick={() => setRegrading(true)}>
                            {assessmentStrings.regrade.button}
                        </button>
                        {hasRoute(config, '/manage/quizzes/{id}/export') && (
                            <button type="button" onClick={() => setExporting(true)}>
                                {flowStrings.export.button}
                            </button>
                        )}
                    </div>
                </details>
            </header>
            <div className="vy-quiz-summary">
                <span>
                    <Icon icon={CircleHelp} size={15} />
                    {s.questionCount(questions.length)}
                </span>
                <span>{s.points(questions.reduce((sum, question) => sum + Number(question.points), 0))}</span>
                <span>
                    <Icon icon={Clock3} size={15} />
                    {quiz.time_limit_seconds === null ? s.untimed : s.time(quiz.time_limit_seconds)}
                </span>
                <span>{s.pass(quiz.pass_mark)}</span>
            </div>
            <Tabs
                className="vy-quiz-studio-tabs"
                label={s.sections}
                current={`quiz-lesson-${section}` as TabId}
                tabs={tabs}
                onSelect={(id) => request(() => setSection(id.replace('quiz-lesson-', '') as Section))}
            />
            <div className="vy-quiz-studio-panel">
                <TabPanel id={`quiz-lesson-${section}`}>
                    {section === 'questions' && (
                        <>
                            <div className="vy-quiz-question-toolbar">
                                <p>{s.questionHint}</p>
                                <div>
                                    {slots && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setSection('selection')}
                                        >
                                            {s.bank}
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        onClick={() => {
                                            setTypeSearch('');
                                            setChoosing(true);
                                        }}
                                    >
                                        <Icon icon={Plus} size={15} />
                                        {s.add}
                                    </Button>
                                </div>
                            </div>
                            {questions.length > 0 && (
                                <div className="vy-quiz-search">
                                    <Icon icon={Search} size={15} />
                                    <TextInput
                                        type="search"
                                        aria-label={s.search}
                                        placeholder={s.search}
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                    />
                                </div>
                            )}
                            <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
                            {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                            {questions.length === 0 ? (
                                <div className="vy-quiz-empty">
                                    <Icon icon={CircleHelp} size={36} />
                                    <h4>{s.emptyTitle}</h4>
                                    <p>{s.emptyIntro}</p>
                                </div>
                            ) : visible.length === 0 ? (
                                <EmptyState
                                    title={s.noMatches}
                                    action={
                                        <Button variant="outline" onClick={() => setSearch('')}>
                                            {s.clearSearch}
                                        </Button>
                                    }
                                />
                            ) : (
                                <ol className="vy-quiz-question-list">
                                    {visible.map((question) => {
                                        const index = questions.findIndex(
                                            (entry) => entry.id === question.id,
                                        );
                                        const canOrder = search.trim() === '' && save.state !== 'saving';
                                        return (
                                            <li key={question.id}>
                                                <DropTarget
                                                    className="vy-quiz-question-card"
                                                    over={drag.isOver(index)}
                                                    dragging={drag.isDragging(index)}
                                                    {...drag.rowProps(index)}
                                                >
                                                    <DragHandle
                                                        label={strings.quizzes.dragQuestion(index + 1)}
                                                        canMoveUp={canOrder && index > 0}
                                                        canMoveDown={canOrder && index < questions.length - 1}
                                                        onMoveUp={() => move(index, index - 1)}
                                                        onMoveDown={() => move(index, index + 1)}
                                                    />
                                                    <span className="vy-quiz-question-number">
                                                        {String(index + 1).padStart(2, '0')}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        className="vy-quiz-question-title"
                                                        aria-label={s.edit(index + 1)}
                                                        onClick={() =>
                                                            setEditing({
                                                                question,
                                                                type: question.type,
                                                                seed: {},
                                                            })
                                                        }
                                                    >
                                                        <span>
                                                            {String(
                                                                question.definition.prompt ??
                                                                    questionTypeLabel(question.type),
                                                            )}
                                                        </span>
                                                        <small>
                                                            <QuestionTypeChip type={question.type} />
                                                            {s.points(Number(question.points))}
                                                            {needsManualMarking(
                                                                question.type,
                                                                question.definition,
                                                            ) && <em>{s.manual}</em>}
                                                        </small>
                                                    </button>
                                                    <details className="vy-outline-actions">
                                                        <summary aria-label={s.actions(index + 1)}>
                                                            •••
                                                        </summary>
                                                        <div>
                                                            <OrderControls
                                                                size="sm"
                                                                upLabel={strings.quizzes.moveQuestionUp(
                                                                    index + 1,
                                                                )}
                                                                downLabel={strings.quizzes.moveQuestionDown(
                                                                    index + 1,
                                                                )}
                                                                canMoveUp={canOrder && index > 0}
                                                                canMoveDown={
                                                                    canOrder && index < questions.length - 1
                                                                }
                                                                onMoveUp={() => move(index, index - 1)}
                                                                onMoveDown={() => move(index, index + 1)}
                                                            />
                                                            <Button
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setEditing({
                                                                        question,
                                                                        type: question.type,
                                                                        seed: {},
                                                                    })
                                                                }
                                                            >
                                                                {strings.quizzes.edit}
                                                            </Button>
                                                        </div>
                                                    </details>
                                                </DropTarget>
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}
                        </>
                    )}
                    {section === 'selection' && (
                        <>
                            <div className="vy-quiz-panel-intro">
                                <h4>{s.selectionTitle}</h4>
                                <p>{s.selectionIntro}</p>
                            </div>
                            <SlotsEditor courseId={courseId} quizId={quizId} />
                        </>
                    )}
                    {section === 'settings' && (
                        <QuizSettingsDialog
                            inline
                            courseId={courseId}
                            quiz={quiz}
                            onClose={() => undefined}
                        />
                    )}
                    {section === 'grading' && (
                        <>
                            <nav className="vy-quiz-grading-nav" aria-label={s.gradingSections}>
                                <Button
                                    variant="outline"
                                    aria-pressed={grading === 'policy'}
                                    onClick={() => request(() => setGrading('policy'))}
                                >
                                    {s.policy}
                                </Button>
                                {hasRoute(config, '/manage/rubrics') && (
                                    <Button
                                        variant="outline"
                                        aria-pressed={grading === 'rubrics'}
                                        onClick={() => request(() => setGrading('rubrics'))}
                                    >
                                        {s.rubrics}
                                    </Button>
                                )}
                            </nav>
                            {grading === 'policy' ? (
                                <QuizPolicyDialog
                                    inline
                                    courseId={courseId}
                                    quiz={quiz}
                                    onClose={() => undefined}
                                />
                            ) : (
                                <RubricsPanel courseId={courseId} />
                            )}
                        </>
                    )}
                    {section === 'details' && (
                        <LessonDialog
                            inline
                            keepOpen
                            metadataOnly
                            courseId={courseId}
                            moduleId={lesson.module_id}
                            lesson={lesson}
                            type="quiz"
                            onSaved={onSaved}
                            onClose={() => undefined}
                        />
                    )}
                    {section === 'access' && (
                        <SubjectRestrictions subjectType="lesson" subjectId={lesson.id} courseId={courseId} />
                    )}
                </TabPanel>
            </div>
            <Dialog open={choosing} onOpenChange={setChoosing}>
                <DialogContent className="vy-question-type-dialog max-w-[800px]">
                    <DialogTitle>{s.typeTitle}</DialogTitle>
                    <DialogDescription>{s.typeIntro}</DialogDescription>
                    <TextInput
                        type="search"
                        autoFocus
                        aria-label={s.typeSearch}
                        placeholder={s.typeSearch}
                        value={typeSearch}
                        onChange={(event) => setTypeSearch(event.target.value)}
                    />
                    {registry.isPending ? (
                        <ListSkeleton label={strings.states.loading} rows={3} />
                    ) : registry.isError ? (
                        <ErrorState
                            title={s.typesError}
                            error={registry.error}
                            onRetry={() => void registry.refetch()}
                        />
                    ) : (
                        <div className="vy-question-type-groups">
                            {groups.map((group) => {
                                const entries = group.entries.filter((entry) =>
                                    `${entry.label} ${strings.question.families[group.family] ?? group.family}`
                                        .toLowerCase()
                                        .includes(typeSearch.trim().toLowerCase()),
                                );
                                return entries.length === 0 ? null : (
                                    <section key={group.family}>
                                        <h4>{strings.question.families[group.family] ?? group.family}</h4>
                                        <div>
                                            {entries.map((entry) => (
                                                <button
                                                    key={entry.key}
                                                    aria-label={entry.label}
                                                    type="button"
                                                    onClick={() => choose(entry)}
                                                >
                                                    <QuestionTypeChip type={entry.type} />
                                                    <span>{entry.label}</span>
                                                    <Icon icon={Plus} size={14} />
                                                </button>
                                            ))}
                                        </div>
                                    </section>
                                );
                            })}
                            {!groups.some((group) =>
                                group.entries.some((entry) =>
                                    `${entry.label} ${strings.question.families[group.family] ?? group.family}`
                                        .toLowerCase()
                                        .includes(typeSearch.trim().toLowerCase()),
                                ),
                            ) && <p>{s.noTypes}</p>}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            {editing !== null && (
                <QuestionDialog
                    courseId={courseId}
                    quizId={quizId}
                    question={editing.question}
                    type={editing.type}
                    seed={editing.seed}
                    scored={editing.scored}
                    typeLabel={editing.label ?? questionTypeLabel(editing.type)}
                    position={
                        editing.question === null
                            ? questions.length + 1
                            : questions.findIndex((entry) => entry.id === editing.question?.id) + 1
                    }
                    onClose={() => setEditing(null)}
                />
            )}
            {regrading && (
                <RegradeDialog
                    courseId={courseId}
                    quiz={quiz}
                    questions={questions}
                    onClose={() => setRegrading(false)}
                />
            )}
            {exporting && <ExportDialog quizId={quizId} onClose={() => setExporting(false)} />}
            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) setPending(null);
                }}
            >
                <DialogContent>
                    <DialogTitle>{builder.unsavedTitle}</DialogTitle>
                    <DialogDescription>{builder.unsavedBody}</DialogDescription>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPending(null)}>
                            {strings.lesson.keepEditing}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                pending?.();
                                setPending(null);
                            }}
                        >
                            {strings.lesson.discard}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
