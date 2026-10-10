import type { ComponentType } from 'react';
import type { components } from '@vidyalms/api-sdk';

/*
 * The availability condition editor registry contract.
 *
 * A condition's payload is opaque to the tree and belongs to the provider
 * that owns it, so the authoring form has exactly two honest options: draw
 * the provider's `params_schema` generically, or let the package that owns
 * the condition ship the editor for it. Both exist here, and the generic
 * form is the fallback rather than an afterthought: a third-party condition
 * that registers no editor is still authorable the day it installs, which is
 * the acceptance criterion the design states ("core files edited: zero").
 *
 * The key space is OPEN, unlike the runner panel slots. A slot is a place in
 * the runner's own DOM; a condition key is a provider key, and the whole
 * point of the registry is that nobody has to have heard of it. So
 * register() takes any string and the palette still comes from the server.
 *
 * Reached the documented way: the free bundle installs
 * window.vidyalms.conditionEditors at module scope, a package registers into
 * it, load order is declared through the adapters' asset dependencies, and
 * the change event means a chunk arriving after the form mounted still
 * replaces the generic editor without a reload.
 */

/** Additive only after 1: a new optional member never raises this number. */
export const CONDITION_EDITOR_CONTRACT = 1;

export type AvailabilityConditionInfo = components['schemas']['AvailabilityConditionInfo'];
export type ScheduleAnchorInfo = components['schemas']['ScheduleAnchorInfo'];

/** Something a leaf can point at, resolved to a name the author recognises. */
export interface ConditionTarget {
    kind: 'lesson' | 'module' | 'quiz' | 'assignment' | 'course';
    id: number;
    title: string;
    /**
     * True when this target's completion is tied to something carrying a
     * pass mark. The `complete_pass` and `complete_fail` states are offered
     * only for those, and the picker says why inline: a leaf naming a pass
     * state against a target with no pass mark can never be satisfied, sits
     * in the denominator forever, and is refused at save. The hint is the
     * friendly duplicate of the check, never the check.
     */
    hasPassMark?: boolean;
}

export interface ConditionEditorProps {
    /** The provider's catalogue entry: label, schema, advisory marker. */
    condition: AvailabilityConditionInfo;
    /** The unwrapped payload as stored; the envelope's version key is not in it. */
    payload: Record<string, unknown>;
    onChange: (payload: Record<string, unknown>) => void;
    /** Registered schedule anchors, for a condition that names one. */
    anchors: readonly ScheduleAnchorInfo[];
    /** What this course offers as a reference, already resolved to titles. */
    targets: readonly ConditionTarget[];
    /** A DOM id unique to this leaf, for labels and error links. */
    controlId: (local: string) => string;
    locale: string;
    /** Field-level messages by payload key, from a 422 or from the editor. */
    errors?: Record<string, string>;
}

export interface ConditionEditorModule {
    /** The contract version the module was written against. */
    version: number;
    /**
     * The React major the editor was built against. A second React breaks
     * hooks silently, so the pairing fails at registration rather than at
     * render, exactly as an authoring block's does.
     */
    reactMajor?: number;
    Editor: ComponentType<ConditionEditorProps>;
    /**
     * A one-line summary of a stored payload for the collapsed row, so the
     * restriction list reads as sentences rather than as JSON. Optional:
     * without it the row falls back to the condition's own label.
     */
    summarise?: (payload: Record<string, unknown>, locale: string) => string | null;
}

export type ConditionEditorListener = (event: { kind: 'register' | 'replace'; key: string }) => void;

export interface ConditionEditorRegistryApi {
    readonly contract: number;
    register(key: string, module: ConditionEditorModule): void;
    /** Decorates: the displaced module is returned so an override can delegate. */
    replace(key: string, module: ConditionEditorModule): ConditionEditorModule;
    has(key: string): boolean;
    get(key: string): ConditionEditorModule | null;
    keys(): readonly string[];
    /** A deferred chunk that registers late still renders without a reload. */
    subscribe(listener: ConditionEditorListener): () => void;
}

export class ConditionEditorRegistrationError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ConditionEditorRegistrationError';
    }
}
