import { cn } from '@/shared/cn';
import type { AttemptQuestion } from '@/shared/api';
import { quizRunnerStrings as strings } from '../strings';
import { answerKey, isAnswered, type AnswerMap } from '../lib/answers';

/**
 * Numbered question navigation with visible answered/current states.
 * Linear attempts retain the position indicators without offering jumps.
 */
export function SegmentedProgress({
    questions,
    answers,
    currentIndex,
    onJump,
    canJump = true,
    label,
    className,
}: {
    questions: AttemptQuestion[];
    answers: AnswerMap;
    currentIndex: number;
    onJump: (index: number) => void;
    /**
     * False under linear navigation, where the candidate may not return to a
     * screen already left. The bar then stays as a position indicator rather
     * than becoming a set of controls that refuse: a disabled jump link and
     * no jump link say different things, and only one of them is honest
     * about a quiz that has no jump at all.
     */
    canJump?: boolean;
    /** Replaces the group name where the bar is not a set of links. */
    label?: string;
    className?: string;
}) {
    return (
        <div role="group" aria-label={label ?? strings.attempt.progressLabel} className={cn('vy-question-palette', className)}>
            {questions.map((question, index) => {
                const answered = isAnswered(question.type, answers[answerKey(question.id)], question.definition);
                const current = index === currentIndex;
                const state = current
                    ? strings.attempt.paletteCurrent
                    : answered ? strings.attempt.paletteAnswered : strings.attempt.paletteUnanswered;
                const face = <span aria-hidden="true">{index + 1}</span>;
                const props = {
                    'aria-label': strings.attempt.paletteItem(index + 1, state),
                    'aria-current': current ? 'true' as const : undefined,
                    'data-answered': answered,
                    className: 'vy-question-number',
                };
                return canJump ? (
                    <button key={question.id} type="button" {...props} onClick={() => onJump(index)}>{face}</button>
                ) : (
                    <span key={question.id} {...props} role="img">{face}</span>
                );
            })}
        </div>
    );
}
