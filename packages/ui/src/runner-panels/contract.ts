import type { ComponentType } from 'react';
import type { AnnounceFn } from '@/question-types';

/*
 * The runner panel registry contract.
 *
 * The question type registry answers "who renders this item". It cannot
 * answer "who renders the confidence scale beside every item" or "who
 * renders the feedback panel between two tries", because neither belongs to
 * a type: both belong to the attempt BEHAVIOUR, which is a quiz-level
 * policy provider and never reaches a question type by construction (see
 * docs/technical/12-assessment-types.md, "Quiz-level policy objects").
 *
 * Without a seam of its own, certainty capture and the multi-try panel would
 * have to be switches in the free runner keyed on a behaviour key the free
 * build does not register, which is the fork ADR 0011 forbids and which
 * would put Pro-only UI in the free bundle. So the shell declares the slots
 * it has and a package registers the renderer for one.
 *
 * The slot set is closed on purpose and the renderers are open. A slot is a
 * place in the runner's own DOM with its own props, so a new slot is a
 * runner change whatever registry it went through; a new RENDERER for an
 * existing slot, which is what a new behaviour actually needs, is a
 * registration and nothing else.
 *
 * Reached exactly as the question type registry is: the free bundle installs
 * window.vidyalms.runnerPanels at module scope and a package registers into
 * it, with load order declared through the adapters' asset dependencies and
 * a change event so a chunk that arrives late still renders.
 */

/** Additive only after 1: a new optional member never raises this number. */
export const RUNNER_PANEL_CONTRACT = 1;

/**
 * The confidence scale the server declared for this attempt, read from the
 * frozen policy snapshot. It is data, not a provider decision, so the panel
 * renders whatever levels arrive rather than knowing a tariff.
 */
export interface CertaintyScale {
    levels: readonly { value: number; label: string; description?: string | null }[];
    /** Whether an answer with no declared confidence is invalid. */
    required: boolean;
}

export interface CertaintyPanelProps {
    scale: CertaintyScale;
    /** The declared confidence, or null when the learner has not said. */
    value: number | null;
    onChange: (value: number | null) => void;
    /** A DOM id unique to this question, for labels and error summary links. */
    controlId: (local: string) => string;
    /** True once the item session has closed; the panel renders read only. */
    readOnly: boolean;
    locale: string;
    /** The shell's live region service; no panel creates a region of its own. */
    announce: AnnounceFn;
}

export interface ItemFeedbackPanelProps {
    /** The attempt behaviour in force, from the frozen snapshot. */
    behaviour: string;
    /** Tries already spent on this item, from the append-only event history. */
    triesUsed: number;
    /** Tries per item; 0 means unlimited. */
    maxAttempts: number;
    /** Whether applicable feedback may be shown for this item. */
    showFeedback: boolean;
    showSolution: boolean;
    /**
     * The resolved feedback the server sent for this item, once its session
     * closed. Correctness never reaches the client before that, so this is
     * null for every try that is still open.
     */
    feedback: string | null;
    /** True while a check is in flight. */
    checking: boolean;
    /** Ask the server to process this item now. Absent when no try is left. */
    onCheck: (() => void) | null;
    locale: string;
    announce: AnnounceFn;
}

/** The slots the runner offers, with the props each one is handed. */
export interface RunnerPanelPropsBySlot {
    certainty: CertaintyPanelProps;
    item_feedback: ItemFeedbackPanelProps;
}

export type RunnerPanelSlot = keyof RunnerPanelPropsBySlot;

export const RUNNER_PANEL_SLOTS = ['certainty', 'item_feedback'] as const;

export function isRunnerPanelSlot(slot: string): slot is RunnerPanelSlot {
    return (RUNNER_PANEL_SLOTS as readonly string[]).includes(slot);
}

export interface RunnerPanelModule<S extends RunnerPanelSlot = RunnerPanelSlot> {
    /** The contract version the module was written against. */
    version: number;
    /**
     * The React major the panel was built against. A second React breaks
     * hooks silently, so the pairing fails at registration rather than at
     * render, exactly as an authoring block's does.
     */
    reactMajor?: number;
    Panel: ComponentType<RunnerPanelPropsBySlot[S]>;
    /**
     * The attempt behaviour keys this panel was written for. The shell uses
     * it for the placeholder sentence when a stored behaviour has a panel
     * registered by nobody; it never gates rendering, because a behaviour
     * the shell has never heard of is exactly the case the seam exists for.
     */
    behaviours?: readonly string[];
}

export type RunnerPanelListener = (event: { kind: 'register' | 'replace'; slot: RunnerPanelSlot }) => void;

export interface RunnerPanelRegistryApi {
    readonly contract: number;
    register<S extends RunnerPanelSlot>(slot: S, module: RunnerPanelModule<S>): void;
    /** Decorates: the displaced module is returned so an override can delegate. */
    replace<S extends RunnerPanelSlot>(slot: S, module: RunnerPanelModule<S>): RunnerPanelModule<S>;
    has(slot: string): boolean;
    get<S extends RunnerPanelSlot>(slot: S): RunnerPanelModule<S> | null;
    slots(): readonly RunnerPanelSlot[];
    /** A deferred chunk that registers late still renders without a reload. */
    subscribe(listener: RunnerPanelListener): () => void;
}

export class RunnerPanelRegistrationError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'RunnerPanelRegistrationError';
    }
}
