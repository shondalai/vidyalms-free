import { translate as uiText } from '@/shared/i18n';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import type { GradebookItem } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import { useAnnounce } from '@/shared/a11y';
import { Button, SaveIndicator } from '@/shared/components';
import { AttachmentGallery } from '@/shared/components/attachment-gallery';
import { practicalStrings as practical } from '@/shared/practical-strings';
import { useGradeAnswer, useGradeSubmission } from '../api';
import { instructorStrings as strings } from '../strings';
import { dateTimeText, initials, pointsText } from '../lib/format';
import { useSaveTracker } from '../lib/save-state';
import { Field, TextArea, TextInput } from '../components/field';
import { SaveError } from '../components/states';
import { RubricGrid, readRubricGrid } from '../components/rubric-grid';
import { assessmentStrings } from '../assessment-strings';
import type { GradeCriterionInput, RubricVersion } from '../lib/assessment-types';

/*
 * Artboard 3a, the queue: one item at a time, keyboard fast. Up and down
 * move through the queue, G grades, and the grade button says what grading
 * does, because that consequence is the whole point of the screen.
 *
 * The two sources are graded by different routes and answer different
 * outcomes: a submission completes the assignment lesson, an answer
 * finishes the attempt once it was the last one waiting. Both answers say
 * so, and this screen announces exactly what came back rather than what it
 * hoped for.
 */

export interface QueueProps {
    courseId: number;
    items: GradebookItem[];
    /** The item's place in the whole queue, not just this page. */
    offset: number;
    total: number;
    index: number;
    onIndex: (index: number) => void;
    onGraded: () => void;
    narrow: boolean;
}

/**
 * The rubric version this answer is graded against, sent with the queue row
 * so the grading pane renders the grid without a second call.
 *
 * Null for every question with no rubric, which is what makes this an
 * addition to the queue rather than a second screen: the same keyboard flow
 * grades a rubric answer and a plain one.
 */
function rubricOf(item: GradebookItem): RubricVersion | null {
    return (item.quiz_answer as unknown as { rubric?: RubricVersion | null } | null)?.rubric ?? null;
}

function maxPointsOf(item: GradebookItem): number {
    if (item.submission !== null) {
        return item.submission.max_points;
    }
    if (item.quiz_answer !== null) {
        return item.quiz_answer.question.points;
    }
    return 0;
}

/** How many other answers of the same attempt are still waiting on this page. */
function othersWaiting(items: GradebookItem[], item: GradebookItem): number {
    if (item.quiz_answer === null) {
        return 0;
    }
    const attemptId = item.quiz_answer.attempt_id;
    return items.filter(
        (other) =>
            other.key !== item.key && other.status === 'pending' && other.quiz_answer?.attempt_id === attemptId,
    ).length;
}

function answerText(answer: unknown): string {
    if (typeof answer === 'string') {
        return answer;
    }
    if (Array.isArray(answer)) {
        return answer.filter((entry): entry is string => typeof entry === 'string').join(', ');
    }
    if (typeof answer === 'object' && answer !== null) {
        return Object.entries(answer as Record<string, unknown>)
            .map(([key, value]) => `${key}: ${typeof value === 'string' ? value : JSON.stringify(value)}`)
            .join('\n');
    }
    return '';
}

export function GradebookQueue({ courseId, items, offset, total, index, onIndex, onGraded, narrow }: QueueProps) {
    const gradeSubmission = useGradeSubmission();
    const gradeAnswer = useGradeAnswer();
    const save = useSaveTracker();
    const announce = useAnnounce();
    const headingRef = useRef<HTMLParagraphElement>(null);
    const scoreRef = useRef<HTMLInputElement>(null);
    const [score, setScore] = useState('');
    const [feedback, setFeedback] = useState('');
    /* The levels the rater chose, one per criterion, for a rubric answer. */
    const [criteria, setCriteria] = useState<GradeCriterionInput[]>([]);
    const [practicalScores, setPracticalScores] = useState<Record<string, string>>({});
    const [practicalFeedback, setPracticalFeedback] = useState<Record<string, string>>({});
    const [decision, setDecision] = useState<'auto' | 'approved' | 'failed' | 'resubmit'>('auto');

    const item = items[index] ?? null;
    const itemKey = item?.key ?? '';
    const resetSave = save.reset;

    /* A new item is a new form and a new place to be: reset the fields and
       move focus onto the heading so a screen reader lands on the change. */
    useEffect(() => {
        setScore('');
        setFeedback('');
        setCriteria([]);
        setPracticalScores({});
        setPracticalFeedback({});
        setDecision('auto');
        resetSave();
        headingRef.current?.focus();
    }, [itemKey, resetSave]);

    if (item === null) {
        return null;
    }

    const rubric = rubricOf(item);
    const practicalRubric = item.submission?.assessment?.rubric ?? [];
    const practicalTotal = practicalRubric.reduce((sum, criterion) => sum + (Number(practicalScores[criterion.id]) || 0), 0);
    const practicalReady = practicalRubric.every(criterion => practicalScores[criterion.id]?.trim() && Number.isFinite(Number(practicalScores[criterion.id])) && Number(practicalScores[criterion.id]) >= 0 && Number(practicalScores[criterion.id]) <= criterion.max_points);
    /* Exactly one of points_awarded and criteria is sent: a points value
       that disagrees with the level sum is a 422, not a silent overwrite. */
    const grid = rubric === null ? null : readRubricGrid(rubric, criteria);
    const maxPoints = rubric === null ? maxPointsOf(item) : grid?.max ?? maxPointsOf(item);
    const readyToGrade = item.grade === null && (decision !== 'resubmit' || feedback.trim() !== '') && (practicalRubric.length > 0 ? practicalReady : grid === null ? score.trim() !== '' && Number.isFinite(Number(score)) && Number(score) >= 0 && Number(score) <= maxPoints : grid.complete);
    const waiting = othersWaiting(items, item);
    const gradeLabel =
        item.type === 'submission'
            ? strings.gradebook.gradeAssignment
            : waiting === 0
              ? strings.gradebook.gradeAnswer
              : strings.gradebook.gradeAnswerPending(waiting);

    const submit = () => {
        if (!readyToGrade) {
            return;
        }
        const points = Number(score);
        const trimmed = feedback.trim();
        void save
            .run(async (idempotencyKey) => {
                if (item.submission !== null) {
                    const graded = await gradeSubmission.mutateAsync({
                        courseId,
                        submissionId: item.submission.id,
                        body: { ...(practicalRubric.length === 0 ? { points_awarded: points } : { criteria: practicalRubric.map(criterion => ({ id: criterion.id, points: Number(practicalScores[criterion.id]), feedback: practicalFeedback[criterion.id] || null })) }), feedback: trimmed === '' ? null : trimmed, decision },
                        idempotencyKey,
                    });
                    announce(
                        graded.outcome.enrolment_completed
                            ? strings.gradebook.outcome.enrolmentCompleted
                            : graded.outcome.lesson_completed
                              ? strings.gradebook.outcome.lessonCompleted
                              : strings.gradebook.outcome.recorded,
                    );
                    return graded;
                }
                if (item.quiz_answer !== null) {
                    const graded = await gradeAnswer.mutateAsync({
                        courseId,
                        attemptId: item.quiz_answer.attempt_id,
                        questionId: item.quiz_answer.question.id,
                        body:
                            grid === null
                                ? { points_awarded: points, feedback: trimmed === '' ? null : trimmed }
                                : { criteria: grid.criteria, feedback: trimmed === '' ? null : trimmed },
                        idempotencyKey,
                    });
                    announce(
                        graded.outcome.attempt_finalised
                            ? strings.gradebook.outcome.attemptFinalised
                            : strings.gradebook.outcome.stillWaiting(graded.outcome.pending_manual_count),
                    );
                    return graded;
                }
                throw new Error(uiText("vidyalms.ui.d43db0e6deefe48d", "This gradebook row carries neither a submission nor a quiz answer."));
            })
            .then((ok) => {
                if (ok) {
                    onGraded();
                }
            });
    };

    /* Arrow keys move through the queue and G grades, but only while the
       person is not typing into a field. */
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const target = event.target;
        const typing =
            target instanceof HTMLInputElement ||
            target instanceof HTMLTextAreaElement ||
            target instanceof HTMLSelectElement;
        if (typing) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            onIndex(index + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            onIndex(index - 1);
        } else if (event.key === 'g' || event.key === 'G') {
            event.preventDefault();
            /* G grades when there is something to grade with, and otherwise
               puts the cursor where the score goes: the shortcut always
               moves the work forward instead of hitting a disabled button.
               On a rubric answer the score box is derived, so there is
               nowhere to send the cursor and the grid says what is missing. */
            if (readyToGrade) {
                submit();
            } else if (grid === null) {
                scoreRef.current?.focus();
            }
        }
    };

    return (
        <div className="p-5" onKeyDown={onKeyDown}>
            <div className="mb-3.5 flex flex-wrap items-center gap-3">
                <span
                    aria-hidden="true"
                    className="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-surface-2 font-display text-[11px] font-bold text-accent"
                >
                    {initials(item.learner.name)}
                </span>
                <div className="min-w-0">
                    <p
                        ref={headingRef}
                        tabIndex={-1}
                        className="font-display text-[13.5px] font-semibold text-fg"
                    >
                        {item.learner.name ?? strings.gradebook.unknownLearner} · {item.lesson_title}
                    </p>
                    <p className="text-[11.5px] text-fg-2">
                        {item.type === 'submission' ? strings.gradebook.assignment : strings.gradebook.quiz} ·{' '}
                        {strings.gradebook.submittedAt(dateTimeText(item.waiting_since))} ·{' '}
                        {strings.gradebook.itemOf(offset + index + 1, total)}
                    </p>
                </div>
                <span className={cn('text-[11.5px] text-fg-2', narrow ? 'w-full' : 'ml-auto')}>
                    {strings.gradebook.keyboardHint}
                </span>
            </div>

            {item.submission !== null && (
                <div className="mb-3.5 grid gap-2.5">
                    {item.submission.text_response !== null && item.submission.text_response !== '' && (
                        <p className="whitespace-pre-wrap rounded-lg border border-border p-3.5 text-[13px]">
                            {item.submission.text_response}
                        </p>
                    )}
                    <AttachmentGallery files={item.submission.files} />
                </div>
            )}

            {item.quiz_answer !== null && (
                <div className="mb-3.5 grid gap-2.5">
                    <p className="rounded-lg border border-border p-3.5 text-[13px] font-medium">
                        {typeof item.quiz_answer.question.presentation.prompt === 'string'
                            ? item.quiz_answer.question.presentation.prompt
                            : item.quiz_answer.quiz_title}
                    </p>
                    <div className="rounded-lg border border-border bg-bg p-3.5">
                        <p className="mb-1 font-display text-[11.5px] font-semibold text-fg-2">
                            {strings.gradebook.learnerAnswer}
                        </p>
                        <p className="whitespace-pre-wrap text-[13px]">
                            {answerText(item.quiz_answer.answer) === ''
                                ? strings.gradebook.noAnswer
                                : answerText(item.quiz_answer.answer)}
                        </p>
                    </div>
                </div>
            )}

            {item.grade !== null && (
                <p className="mb-3.5 text-[11.5px] text-fg-2">
                    {strings.gradebook.gradedBadge} ·{' '}
                    {strings.gradebook.gradedAt(
                        dateTimeText(item.grade.graded_at),
                        Number(pointsText(item.grade.points_awarded)),
                        Number(pointsText(item.grade.max_points)),
                    )}
                </p>
            )}

            {item.grade !== null && <div className="mb-4 grid gap-3 rounded-lg border border-border p-4">
                {item.submission?.assessment?.decision && <strong>{practical[item.submission.assessment.decision]}</strong>}
                <p className="whitespace-pre-wrap text-sm">{item.grade.feedback}</p>
                {item.submission?.assessment?.criteria?.map(criterion => <div key={criterion.id} className="text-sm"><strong>{criterion.label}</strong> · {criterion.points}/{criterion.max_points}<p>{criterion.feedback}</p></div>)}
            </div>}
            {item.grade === null && <>
            {practicalRubric.length > 0 && <section className="mb-4 grid gap-3" aria-label={practical.rubric}>
                <h4 className="font-semibold">{practical.rubric}</h4>
                {practicalRubric.map(criterion => <div key={criterion.id} className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-2">
                    <Field label={uiText("vidyalms.ui.668208a1a52e3886", "{p0} (0 to {p1})", { p0: criterion.label, p1: criterion.max_points })}>{({ id }) => <TextInput id={id} type="number" min={0} max={criterion.max_points} step="0.01" value={practicalScores[criterion.id] ?? ''} onChange={event => setPracticalScores({ ...practicalScores, [criterion.id]: event.target.value })} />}</Field>
                    <Field label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: practical.feedback, p1: criterion.label })}>{({ id }) => <TextArea id={id} rows={2} value={practicalFeedback[criterion.id] ?? ''} onChange={event => setPracticalFeedback({ ...practicalFeedback, [criterion.id]: event.target.value })} />}</Field>
                </div>)}
                <p className="font-semibold">{practical.total}: {practicalTotal} / {maxPoints}</p>
            </section>}
            {item.submission !== null && <div className="mb-4 grid gap-2">
                <label className="grid gap-2 text-sm font-semibold">{practical.decision}<select className="rounded border border-border bg-surface p-2" value={decision} onChange={event => setDecision(event.target.value as typeof decision)}>
                    <option value="auto">{practical.automatic}</option><option value="approved">{practical.approveAction}</option><option value="failed">{practical.failAction}</option><option value="resubmit">{practical.resubmitAction}</option>
                </select></label>
                <p className="text-xs text-fg-2">{practical.gradeHint}</p>
                {item.submission.assessment?.pass_percentage != null && <p className="text-xs">{practical.pass}: {item.submission.assessment.pass_percentage}%</p>}
            </div>}
            {rubric !== null && (
                <div className="mb-3.5">
                    <RubricGrid
                        version={rubric}
                        value={criteria}
                        disabled={save.state === 'saving'}
                        onChange={setCriteria}
                    />
                </div>
            )}

            <div className={cn('mb-3.5 grid gap-3.5', !narrow && 'grid-cols-[140px_1fr]')}>
                {/* A rubric answer has no score box: the score is the sum of
                    the levels, and a second number beside it invites one that
                    disagrees. The running total lives inside the grid. */}
                {rubric === null && practicalRubric.length === 0 ? (
                    <Field label={strings.gradebook.score}>
                        {({ id }) => (
                            <div className="flex items-center gap-2">
                                <TextInput
                                    id={id}
                                    ref={scoreRef}
                                    inputMode="decimal"
                                    value={score}
                                    onChange={(event) => setScore(event.target.value)}
                                    className="w-20 text-center font-display text-sm font-semibold"
                                />
                                <span className="text-[12.5px] text-fg-2">
                                    {strings.gradebook.outOf(Number(pointsText(maxPoints)))}
                                </span>
                            </div>
                        )}
                    </Field>
                ) : practicalRubric.length === 0 ? (
                    <p className="self-end text-[12.5px] text-fg-2">
                        {assessmentStrings.grading.runningTotal(
                            Number(pointsText(grid?.awarded ?? 0)),
                            Number(pointsText(maxPoints)),
                        )}
                    </p>
                ) : null}
                <Field label={strings.gradebook.feedback}>
                    {({ id }) => (
                        <TextArea
                            id={id}
                            rows={2}
                            value={feedback}
                            onChange={(event) => setFeedback(event.target.value)}
                        />
                    )}
                </Field>
            </div>

            {save.state === 'failed' && <SaveError className="mb-3" error={save.error} onRetry={save.retry} />}

            <div className="flex flex-wrap items-center gap-2">
                <Button onClick={submit} disabled={!readyToGrade || save.state === 'saving'}>
                    {item.submission !== null ? practical.saveGrade : item.status === 'graded'
                        ? strings.gradebook.regrade
                        : rubric === null
                          ? gradeLabel
                          : assessmentStrings.grading.gradeWithRubric}
                </Button>
                <Button variant="outline" onClick={() => onIndex(index + 1)} disabled={offset + index + 1 >= total}>
                    {strings.gradebook.next}
                </Button>
                <SaveIndicator className="ml-auto" state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
                <span className="text-[11.5px] text-fg-2">{strings.gradebook.gradeNote}</span>
            </div>
            </>}
        </div>
    );
}
