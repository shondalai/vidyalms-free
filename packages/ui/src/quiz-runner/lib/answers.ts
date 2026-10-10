/*
 * Answer state and serialization. The local answer map is keyed by the
 * question id as a string and holds exactly the wire shape of
 * PUT /attempts/{id}/answers, so saving is a passthrough.
 *
 * What counts as answered is no longer a switch here. It is
 * answeredStatus() on the registered module, resolved through the client
 * renderer registry, because the old switch returned false for every key it
 * did not know: a question with a working third-party renderer showed as
 * permanently unanswered in the progress palette and tripped the submit
 * warning. A key with no module resolves to the shape-keyed fallback, whose
 * status is derived from the response itself.
 *
 * Correctness never exists client-side; nothing here decides whether an
 * answer is right.
 */

import {
    questionTypeRegistry,
    resolveQuestionModule,
    type AnsweredStatus,
    type QuestionDefinition,
    type QuestionError,
    type ResponseShape,
} from '@/question-types';
import { quizRunnerStrings as strings } from '../strings';

export type AnswerMap = Record<string, unknown>;

/** Opaque, resumable UI state per question, keyed the same way answers are. */
export type UiStateMap = Record<string, string>;

export function answerKey(questionId: number): string {
    return String(questionId);
}

export interface AnsweredOptions {
    definition?: QuestionDefinition;
    /** False before the learner has been shown the item at all. */
    presented?: boolean;
    shape?: ResponseShape | null;
    /** The shape errors the mounted module reported, if it is mounted. */
    errors?: readonly QuestionError[];
}

/**
 * The four values the reporting model needs. They cannot be reconstructed
 * later, which is why the client reports the one it observed rather than a
 * boolean the server would have to guess back.
 */
export function answeredStatus(type: string, answer: unknown, options: AnsweredOptions = {}): AnsweredStatus {
    const definition = options.definition ?? {};
    const resolved = resolveQuestionModule(questionTypeRegistry(), type, definition, options.shape ?? null);
    return resolved.module.answeredStatus(answer, {
        definition,
        presented: options.presented ?? true,
        errors: options.errors ?? [],
    });
}

export function isAnswered(type: string, answer: unknown, definition: QuestionDefinition = {}): boolean {
    return answeredStatus(type, answer, { definition }) === 'answered';
}

/** "7.8" not "7.80", "9" not "9.0": points read like the artboard writes them. */
export function formatPoints(value: number): string {
    const rounded = Math.round(value * 100) / 100;
    return String(rounded);
}

/**
 * "Q1-Q3, Q5-Q7, Q9, Q10": consecutive runs of three or more compress to a
 * range; shorter runs stay individual, matching the result artboard.
 */
export function compressQuestionNumbers(numbers: number[]): string {
    const sorted = [...numbers].sort((a, b) => a - b);
    const parts: string[] = [];
    let runStart: number | null = null;
    let previous: number | null = null;
    const flush = () => {
        if (runStart === null || previous === null) {
            return;
        }
        const length = previous - runStart + 1;
        if (length >= 3) {
            parts.push(`${strings.result.questionShort(runStart)}-${strings.result.questionShort(previous)}`);
        } else {
            for (let n = runStart; n <= previous; n++) {
                parts.push(strings.result.questionShort(n));
            }
        }
    };
    for (const n of sorted) {
        if (previous !== null && n === previous) {
            continue;
        }
        if (previous !== null && n === previous + 1) {
            previous = n;
            continue;
        }
        flush();
        runStart = n;
        previous = n;
    }
    flush();
    return parts.join(', ');
}

/* The narrowing readers moved beside the modules that use them; re-exported
   so the runner's own call sites keep one import path. */
export { asBoolean, asString, asStringArray, asStringRecord } from '@/question-types/free/values';
