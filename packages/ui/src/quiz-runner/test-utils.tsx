import { render, type RenderResult } from '@testing-library/react';
import { MOUNT_CONTRACT } from '@/shared/config';
import type { ReactElement } from 'react';
import { MountConfigContext } from '@/shared/context';
import type { MountConfig } from '@/shared/config';
import type { AttemptDetail, AttemptQuestion, AttemptView, LessonQuizSummary } from '@vidyalms/api-sdk';

/* Test-only fixtures and wrappers for the quiz runner suites. */

export const testConfig: MountConfig = {
    contract: MOUNT_CONTRACT,
    api: { base: '/v1', mode: 'prefix', headers: {} },
    course: { id: 3, slug: 'accessibility-basics' },
    lessonId: 7,
    user: { id: 42, name: 'Priya Nair' },
    locale: 'en-GB',
    idleTimeoutSeconds: 300,
    urls: { course: '/course/accessibility-basics', myLearning: '/my-learning' },
};

export function renderWithConfig(ui: ReactElement): RenderResult {
    return render(<MountConfigContext.Provider value={testConfig}>{ui}</MountConfigContext.Provider>);
}

export function makeQuiz(overrides: Partial<LessonQuizSummary> = {}): LessonQuizSummary {
    return {
        id: 21,
        title: 'Module 2 check',
        description: null,
        pass_mark: 70,
        max_attempts: 3,
        attempts_used: 1,
        attempts_remaining: 2,
        time_limit_seconds: null,
        question_count: 2,
        show_results: 'after_submit',
        best_percentage: null,
        last_attempt: null,
        ...overrides,
    };
}

type QuestionSeed = Pick<AttemptQuestion, 'id' | 'type' | 'position' | 'definition'> & Partial<AttemptQuestion>;

/**
 * A served question exactly as the wire declares it: every field
 * `AttemptQuestion` requires is present. `screen` defaults to one screen per
 * question, which is what a server sends for a quiz with no shared stimulus.
 *
 * Fixtures that leave the served fields out do not model any server the
 * runner will meet, and a fixture that types a stimulus id as a string while
 * the wire sends an integer hides the very mismatch these tests exist to
 * catch. Use `makeLegacyQuestion` when the absence is the point.
 */
export function makeQuestion(seed: QuestionSeed): AttemptQuestion {
    return {
        screen: seed.position - 1,
        points: 1,
        scored: true,
        response_shape: null,
        stimulus_id: null,
        choice_sequence: {},
        answered_status: 'notpresented',
        answer: null,
        ...seed,
    };
}

/**
 * The same question as served by a host that predates the served fields.
 * The runner reads all of them defensively, so their absence is worth a
 * test; this is the one place that models it, and the one cast that says so.
 */
export function makeLegacyQuestion(
    seed: QuestionSeed,
    extras: Record<string, unknown> = {},
): AttemptQuestion {
    return { points: 1, answer: null, ...seed, ...extras } as AttemptQuestion;
}

export function makeAttemptView(
    questions: AttemptQuestion[],
    attemptOverrides: Partial<AttemptDetail> = {},
    stimuli: AttemptView['stimuli'] = [],
): AttemptView {
    return {
        stimuli,
        attempt: {
            id: 71,
            quiz_id: 21,
            attempt_number: 2,
            status: 'in_progress',
            started_at: '2026-09-01 10:00:00',
            submitted_at: null,
            expires_at: null,
            score: null,
            max_score: null,
            percentage: null,
            passed: null,
            ...attemptOverrides,
        },
        questions,
    };
}

/* The definition fixtures live beside the modules that read them. */
export {
    fillBlankDefinition,
    multipleChoiceDefinition,
    orderingDefinition,
    shortAnswerDefinition,
    singleChoiceDefinition,
    trueFalseDefinition,
} from '@/question-types/free/fixtures';
