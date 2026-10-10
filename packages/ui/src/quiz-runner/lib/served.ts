import type { AttemptQuestion, AttemptView } from '@/shared/api';
import { readResponseShape, type QuestionDefinition, type ResponseShape } from '@/question-types';

/*
 * The served question list.
 *
 * An attempt is graded from what the learner was actually shown, so the
 * attempt view carries the served list rather than the live question rows:
 * the position, the screen, the shared stimulus and the option order
 * actually presented. The runner reads exactly that and never re-derives
 * any of it.
 *
 * The stepper iterates SCREENS, not questions. Consecutive questions sharing
 * a stimulus id form one screen, which is what makes a reading passage with
 * six questions one screen with the passage rendered once instead of six
 * screens none of which can show it. The palette, the answered count and
 * the per-question points stay per question.
 *
 * Every field below is optional on the wire. A host serving the older flat
 * payload gets one question per screen, no stimuli and the authored option
 * order, which is exactly today's behaviour, so the runner does not need to
 * know which host it is talking to.
 */

export interface Stimulus {
    id: string;
    title: string | null;
    body: string;
    altText: string | null;
    longDescription: string | null;
}

export interface ServedQuestion {
    question: AttemptQuestion;
    /** The definition with the served choice order applied. */
    definition: QuestionDefinition;
    shape: ResponseShape | null;
    stimulusId: string | null;
    /** Opaque UI state stored with the answer, when the host keeps one. */
    uiState: string | null;
}

export interface Screen {
    index: number;
    stimulus: Stimulus | null;
    questions: ServedQuestion[];
}

function record(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

function textOrNull(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * A stimulus id normalised to the map key `readStimuli` builds.
 *
 * The wire declares `Stimulus.id` and `AttemptQuestion.stimulus_id` as
 * integers, so reading the reference with a string-only test returns null
 * for every real payload: nothing ever groups onto a shared screen and the
 * passage is never rendered, while a fixture written with string ids passes.
 * Both ends of the reference go through the same normalisation, and a
 * string id keeps working so a host that sends one is still read.
 */
function idOrNull(value: unknown): string | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }
    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * Reorders the keyed lists of a definition into the order the learner was
 * actually shown. Shuffle reorders the DOM, never CSS order, flex-direction
 * or absolute position: a visually correct but DOM-unchanged shuffle breaks
 * reading order for screen readers and tab order for keyboard users while
 * looking fine (SC 1.3.2, SC 2.4.3).
 *
 * Written against the field name rather than the type key, so a third-party
 * type inherits it without a line of code here.
 */
export function applyChoiceSequence(
    definition: QuestionDefinition,
    sequence: Record<string, unknown> | null,
): QuestionDefinition {
    if (sequence === null || Object.keys(sequence).length === 0) {
        return definition;
    }
    const next: QuestionDefinition = { ...definition };
    let changed = false;
    for (const [field, order] of Object.entries(sequence)) {
        const list = definition[field];
        if (!Array.isArray(list) || !Array.isArray(order)) {
            continue;
        }
        const byKey = new Map<string, unknown>();
        for (const entry of list) {
            const key = record(entry).key;
            if (typeof key === 'string') {
                byKey.set(key, entry);
            }
        }
        const ordered: unknown[] = [];
        for (const key of order) {
            if (typeof key === 'string' && byKey.has(key)) {
                ordered.push(byKey.get(key));
                byKey.delete(key);
            }
        }
        /* Anything the sequence did not name keeps its authored order, so a
           stale sequence degrades to a partial shuffle rather than a loss. */
        for (const entry of list) {
            const key = record(entry).key;
            if (typeof key !== 'string' || byKey.has(key)) {
                ordered.push(entry);
                if (typeof key === 'string') {
                    byKey.delete(key);
                }
            }
        }
        next[field] = ordered;
        changed = true;
    }
    return changed ? next : definition;
}

function readStimuli(view: AttemptView): Map<string, Stimulus> {
    const raw = (view as unknown as { stimuli?: unknown }).stimuli;
    const out = new Map<string, Stimulus>();
    if (!Array.isArray(raw)) {
        return out;
    }
    for (const entry of raw) {
        const stimulus = record(entry);
        const id = stimulus.id;
        if (typeof id !== 'string' && typeof id !== 'number') {
            continue;
        }
        out.set(String(id), {
            id: String(id),
            title: textOrNull(stimulus.title),
            body: typeof stimulus.body === 'string' ? stimulus.body : '',
            altText: textOrNull(stimulus.alt_text ?? stimulus.altText),
            longDescription: textOrNull(stimulus.long_description ?? stimulus.longDescription),
        });
    }
    return out;
}

function servedOf(question: AttemptQuestion): ServedQuestion {
    const extra = question as unknown as Record<string, unknown>;
    const sequence = record(extra.choice_sequence);
    return {
        question,
        definition: applyChoiceSequence(question.definition, Object.keys(sequence).length > 0 ? sequence : null),
        shape: readResponseShape(extra.response_shape ?? null),
        stimulusId: idOrNull(extra.stimulus_id),
        uiState: textOrNull(extra.ui_state),
    };
}

/*
 * Memoised on the view object, which react-query keeps stable between
 * fetches. It is not a speed optimisation: the runner reads the screens in
 * its render body, applyChoiceSequence returns a NEW definition object, and
 * the question host remounts a module when the definition identity changes.
 * Without this, a shuffled item would tear its renderer down and rebuild it
 * on every render, which loses focus mid-keystroke and throws away a
 * half-completed placement.
 */
const screenCache = new WeakMap<AttemptView, Screen[]>();

/**
 * The screens of an attempt, in served order. One question per screen unless
 * consecutive questions share a stimulus id, or the host sends an explicit
 * screen index.
 */
export function readServedScreens(view: AttemptView): Screen[] {
    const cached = screenCache.get(view);
    if (cached !== undefined) {
        return cached;
    }
    const built = buildScreens(view);
    screenCache.set(view, built);
    return built;
}

function buildScreens(view: AttemptView): Screen[] {
    const stimuli = readStimuli(view);
    const ordered = [...view.questions].sort((a, b) => a.position - b.position);
    const screens: Screen[] = [];
    for (const question of ordered) {
        const served = servedOf(question);
        const explicit = (question as unknown as Record<string, unknown>).screen;
        const previous = screens.at(-1);
        const sameScreen =
            previous !== undefined &&
            (typeof explicit === 'number'
                ? explicit === previous.index
                : served.stimulusId !== null && previous.questions.at(-1)?.stimulusId === served.stimulusId);
        if (sameScreen && previous !== undefined) {
            previous.questions.push(served);
            continue;
        }
        screens.push({
            index: typeof explicit === 'number' ? explicit : screens.length,
            stimulus: served.stimulusId === null ? null : (stimuli.get(served.stimulusId) ?? null),
            questions: [served],
        });
    }
    return screens;
}

/** The served questions in one flat list, for the palette and the review step. */
export function servedQuestions(screens: readonly Screen[]): ServedQuestion[] {
    return screens.flatMap((screen) => screen.questions);
}

/** Which screen holds a question id, for an error summary link and a jump. */
export function screenOfQuestion(screens: readonly Screen[], questionId: number): number {
    return Math.max(
        0,
        screens.findIndex((screen) => screen.questions.some((served) => served.question.id === questionId)),
    );
}
