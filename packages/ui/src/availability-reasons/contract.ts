import type { components } from '@vidyalms/api-sdk';

/*
 * The learner-facing reason registry contract.
 *
 * `describe()` returns a KEY PLUS PARAMETERS and never a rendered sentence,
 * precisely so the React apps translate it. That presumes the apps have
 * somewhere to put the translation, and until this registry existed they did
 * not: the author-facing half of the same extension point was a published
 * seam a package could register an editor into on the day it installed, while
 * the learner-facing half was a closed switch over eight literal keys inside
 * the free bundle. Seventeen conditions ship reason keys and the learner
 * surface could render five of them; every other one degraded to the generic
 * line, including the ones whose whole value is the sentence they carry.
 *
 * The key space is OPEN for the same reason the condition editor key space
 * is: a reason key is a provider's, and nobody has to have heard of it.
 *
 * A MISS IS NOT AN ERROR. The consumer falls back to the honest general line
 * ("Not available yet", or "Available on X" when an instant is named), which
 * is the same fallback that shipped before and is what a blocked row must
 * always be able to say.
 */

/** Additive only after 1: a new optional member never raises this number. */
export const REASON_RENDERER_CONTRACT = 1;

export type AvailabilityAccess = components['schemas']['AvailabilityAccess'];

/** What a renderer is handed: the whole access block plus the viewer's locale. */
export interface ReasonRenderContext {
    access: AvailabilityAccess;
    locale: string;
    /**
     * The viewer's own local reading of `opens_at`, already formatted, or
     * null when no instant was named or it could not be parsed. It is
     * supplied rather than left to each renderer because the two-reading rule
     * (local first, authoritative alongside when they differ) is the free
     * bundle's and must not be reimplemented per package.
     */
    opensAtLocal: string | null;
}

/**
 * A renderer answers the sentence, or null to fall through to the generic
 * line. Null rather than a thrown error, because a package that recognises
 * only some of its own keys is an ordinary state.
 */
export type ReasonRenderer = (context: ReasonRenderContext) => string | null;

export interface ReasonRendererModule {
    /** The contract this module was built against. */
    version: number;
    render: ReasonRenderer;
}

export type ReasonRendererListener = (event: { key: string }) => void;

export interface ReasonRendererRegistryApi {
    readonly contract: number;
    register(key: string, module: ReasonRendererModule): void;
    replace(key: string, module: ReasonRendererModule): ReasonRendererModule;
    has(key: string): boolean;
    get(key: string): ReasonRendererModule | null;
    keys(): string[];
    subscribe(listener: ReasonRendererListener): () => void;
}

export class ReasonRendererRegistrationError extends Error {}
