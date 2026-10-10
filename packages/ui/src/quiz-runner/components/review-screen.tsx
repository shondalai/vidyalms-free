import { useEffect, useRef, type ReactNode } from 'react';
import { cn } from '@/shared/cn';
import { Button, Spinner } from '@/shared/components';
import type { AttemptQuestion, LessonQuizSummary } from '@/shared/api';
import { moveFocus } from '@/shared/a11y';
import { quizRunnerStrings as strings } from '../strings';
import { answerKey, isAnswered, type AnswerMap } from '../lib/answers';
import { formatClock } from '../lib/time';
import { assessmentCopy as copy } from '../presentation-strings';

/**
 * Artboard 1b: the pause before the irreversible step. Unanswered
 * questions are surfaced with a jump-back palette; submitting is one
 * deliberate press, and while offline the button waits instead of failing.
 */
export function ReviewScreen({
    quiz,
    attemptNumber,
    questions,
    answers,
    remaining,
    onJump,
    canJump,
    onKeepAnswering,
    onSubmit,
    submitting,
    offline,
    failureSlot,
}: {
    quiz: LessonQuizSummary;
    attemptNumber: number;
    questions: AttemptQuestion[];
    answers: AnswerMap;
    remaining: number | null;
    onJump: (index: number) => void;
    /**
     * Whether this question can still be returned to: false under linear
     * navigation, and false for an item whose session control forbids
     * review. The entry stays in the palette either way, because an
     * unanswered question the candidate cannot return to is exactly the
     * thing they most need to know before they submit.
     */
    canJump?: (index: number) => boolean;
    onKeepAnswering: () => void;
    onSubmit: () => void;
    submitting: boolean;
    offline: boolean;
    failureSlot?: ReactNode;
}) {
    const headingRef = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        moveFocus(headingRef.current);
    }, []);

    const answered = questions.map((question) => isAnswered(question.type, answers[answerKey(question.id)], question.definition));
    const unanswered = answered.filter((a) => !a).length;

    const meta = [strings.review.meta(quiz.title, attemptNumber, quiz.max_attempts)];
    if (remaining !== null) {
        meta.push(strings.review.timeLeft(formatClock(remaining)));
    }

    return (
        <div className="vy-assessment-review">
            <p className="vy-study-eyebrow">{copy.reviewKicker}</p>
            <h3 ref={headingRef} tabIndex={-1} className="mb-1 font-display text-base font-bold">
                {strings.review.title}
            </h3>
            <p className="vy-assessment-lead">{copy.reviewDescription}</p>
            <div className="mb-4 text-[12.5px] text-fg-2">{meta.join(' · ')}</div>
            <div className="vy-review-palette">
                {questions.map((question, index) => {
                    const done = answered[index] === true;
                    const jumpable = canJump === undefined || canJump(index);
                    const face = (
                        <>
                            {index + 1}
                            <span
                                className={cn(
                                    'block text-[9.5px] font-semibold',
                                    done ? 'text-ok-strong' : 'font-bold text-warn-strong',
                                )}
                            >
                                {done ? strings.review.answered : strings.review.skipped}
                            </span>
                        </>
                    );
                    const shape = cn(
                        'min-h-11 rounded border py-1 text-center font-display text-[13px] font-semibold',
                        done ? 'border-border bg-surface' : 'border-2 border-warn bg-warn-soft',
                    );
                    if (!jumpable) {
                        return (
                            <span key={question.id} className={shape}>
                                <span className="sr-only">
                                    {strings.review.goToQuestion(
                                        index + 1,
                                        done ? strings.review.answered : strings.review.skipped,
                                    )}
                                </span>
                                <span aria-hidden="true">{face}</span>
                            </span>
                        );
                    }
                    return (
                        <button
                            key={question.id}
                            type="button"
                            onClick={() => onJump(index)}
                            aria-label={strings.review.goToQuestion(
                                index + 1,
                                done ? strings.review.answered : strings.review.skipped,
                            )}
                            className={cn('cursor-pointer', shape)}
                        >
                            {face}
                        </button>
                    );
                })}
            </div>
            {unanswered > 0 && (
                <div className="mb-4 flex gap-2.5 rounded border border-border border-l-[3px] border-l-warn px-3.5 py-[11px] text-[12.5px] text-fg-2">
                    <span>
                        <strong className="font-semibold text-warn-strong">
                            {strings.review.unansweredWarning(unanswered)}
                        </strong>{' '}
                        {strings.review.unansweredBody}
                    </span>
                </div>
            )}
            {failureSlot}
            {offline && (
                <p role="status" className="mb-3 text-xs text-fg-2">
                    {strings.offline.lostBody}
                </p>
            )}
            <div className="flex flex-wrap justify-end gap-2">
                <Button variant="outline" onClick={onKeepAnswering}>
                    {strings.review.keepAnswering}
                </Button>
                <Button onClick={onSubmit} disabled={submitting || offline}>
                    {submitting && <Spinner label={strings.review.submitting} size={14} />}
                    {offline
                        ? strings.offline.reconnecting
                        : submitting
                          ? strings.review.submitting
                          : strings.review.submit}
                </Button>
            </div>
        </div>
    );
}
