import { translate as uiText } from '@/shared/i18n';
import { EmptyState } from '@/shared/components';
import type { LessonQuizSummary, LessonView } from '@/shared/api';
import { playerStrings } from '../../strings';
import { presentationStrings as copy } from '../../presentation-strings';
import { Lightbulb } from 'lucide-react';
import { useMountConfig } from '@/shared/context';
import { quizRunnerHref } from '../../lib';

/*
 * Quiz lesson launch panel (artboard 2d): the stats grid (questions, time
 * limit, pass mark, attempts left), the previous-attempt row, and the
 * launch affordance. The Start/Resume action itself renders in the footer
 * bar exactly where the artboard puts it; this pane carries the summary.
 * The contract exposes only the latest attempt and the best score, so the
 * attempts table shows that row rather than a full history.
 */
export interface QuizPaneProps {
    lesson: LessonView;
    /** No enrolment: the overview is the preview, and no attempt can start. */
    previewing?: boolean;
}

function statTiles(quiz: LessonQuizSummary): { value: string; label: string }[] {
    return [
        { value: String(quiz.question_count), label: playerStrings.quiz.statQuestions },
        {
            value:
                quiz.time_limit_seconds !== null && quiz.time_limit_seconds > 0
                    ? playerStrings.meta.minutes(Math.max(1, Math.round(quiz.time_limit_seconds / 60)))
                    : playerStrings.quiz.statNone,
            label: playerStrings.quiz.statTimeLimit,
        },
        {
            value: quiz.pass_mark > 0 ? `${quiz.pass_mark}%` : playerStrings.quiz.statNone,
            label: playerStrings.quiz.statPassMark,
        },
        {
            value:
                quiz.attempts_remaining === null || quiz.max_attempts === null
                    ? playerStrings.quiz.unlimited
                    : playerStrings.quiz.attemptsOf(quiz.attempts_remaining, quiz.max_attempts),
            label: playerStrings.quiz.statAttemptsLeft,
        },
    ];
}

export function QuizPane({ lesson, previewing = false }: QuizPaneProps) {
    const config = useMountConfig();
    const quiz = lesson.quiz;
    if (quiz === null) {
        return <EmptyState title={playerStrings.quiz.noQuiz.title} description={playerStrings.quiz.noQuiz.body} />;
    }

    const last = quiz.last_attempt;
    const lastClosed = last !== null && last.status !== 'in_progress' ? last : null;
    const attemptOpen = last !== null && last.status === 'in_progress';
    const resultsHidden = quiz.show_results === 'never';
    const noAttemptsLeft = quiz.attempts_remaining !== null && quiz.attempts_remaining <= 0 && !attemptOpen;

    return (
        <div className="vy-quiz-intro grid gap-3.5">
            <div className="vy-activity-intro">
                <span className="vy-activity-icon"><Lightbulb size={29} aria-hidden="true" /></span>
                <span className="vy-study-eyebrow">{copy.knowledgeCheck}</span>
                <h4>{copy.quizTitle}</h4>
                <p>{copy.quizDescription}</p>
            </div>
            {quiz.description !== null && quiz.description !== '' && (
                <p className="max-w-[62ch] text-sm leading-relaxed text-fg">{quiz.description}</p>
            )}
            <dl className="vy-quiz-facts">
                {statTiles(quiz).map((tile) => (
                    <div key={tile.label} className="flex flex-col-reverse rounded border border-border px-3 py-2.5">
                        <dt className="text-[11px] text-fg-2">{tile.label}</dt>
                        <dd className="font-display text-base font-bold text-fg">{tile.value}</dd>
                    </div>
                ))}
            </dl>
            {attemptOpen && (
                <p
                    role="status"
                    className="rounded border border-border border-l-[3px] border-l-warn px-3.5 py-2.5 text-[12.5px] text-fg-2"
                >
                    {playerStrings.quiz.inProgressNote}
                </p>
            )}
            {lastClosed !== null && (
                <div className="overflow-hidden rounded border border-border text-[12.5px]">
                    <div className="grid grid-cols-[1fr_90px_120px] gap-2 bg-surface-2 px-3 py-[7px] font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2">
                        <span>{playerStrings.quiz.previousAttempts}</span>
                        <span>{playerStrings.quiz.colScore}</span>
                        <span>{playerStrings.quiz.colResult}</span>
                    </div>
                    <div className="grid grid-cols-[1fr_90px_120px] items-center gap-2 border-t border-surface-2 px-3 py-[9px]">
                        <span className="text-fg">{playerStrings.quiz.attemptRow(quiz.attempts_used)}</span>
                        <span className="text-fg">
                            {lastClosed.percentage !== null
                                ? uiText("vidyalms.ui.d4bdf581de91640f", "{p0}%", { p0: lastClosed.percentage })
                                : playerStrings.quiz.scoreWithheld}
                        </span>
                        {resultsHidden ? (
                            /* show_results "never": the attempt is recorded;
                               no score, no pass or fail, and no fake zero. */
                            <span className="font-semibold text-fg-2">
                                <span aria-hidden="true">− </span>
                                {playerStrings.quiz.recorded}
                            </span>
                        ) : lastClosed.passed === true ? (
                            <span className="font-semibold text-ok">
                                <span aria-hidden="true">✓ </span>
                                {playerStrings.quiz.passed}
                            </span>
                        ) : lastClosed.passed === false ? (
                            <span className="font-semibold text-danger">
                                <span aria-hidden="true">✕ </span>
                                {playerStrings.quiz.notPassed}
                            </span>
                        ) : (
                            <span className="font-semibold text-warn-strong">
                                <span aria-hidden="true">◔ </span>
                                {playerStrings.quiz.pendingGrade}
                            </span>
                        )}
                    </div>
                </div>
            )}
            {resultsHidden && lastClosed !== null && (
                <p className="text-xs text-fg-2">{playerStrings.quiz.resultsHidden}</p>
            )}
            {quiz.best_percentage !== null && (
                <p className="text-xs text-fg-2">{playerStrings.quiz.bestScore(quiz.best_percentage)}</p>
            )}
            {noAttemptsLeft && (
                <p className="rounded border border-border bg-bg px-3.5 py-2.5 text-[12.5px] text-fg-2">
                    {playerStrings.quiz.noAttemptsLeft}
                </p>
            )}
            {previewing && (
                <p className="rounded border border-border bg-bg px-3.5 py-2.5 text-[12.5px] text-fg-2">
                    {playerStrings.preview.quiz}
                </p>
            )}
            {lastClosed !== null && (noAttemptsLeft || lesson.progress?.status === 'completed') && (
                <a className="text-sm text-accent underline" href={quizRunnerHref(config, lesson.id)}>{copy.assessmentDetails}</a>
            )}
        </div>
    );
}
