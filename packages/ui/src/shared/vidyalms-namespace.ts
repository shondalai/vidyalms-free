/*
 * window.vidyalms itself, the one sanctioned global described in config.ts,
 * reached without reaching any of the registries published on it.
 *
 * Every published registry lives on this one object: questionTypes and the
 * React runtime (src/question-types/bridge.ts), runnerPanels,
 * conditionEditors, availabilityReasons, certificateDesigners and the admin
 * screens. A registry that needs only the namespace takes it from here, so it
 * does not have to import the question type layer to find it.
 *
 * That matters for the build as much as for the design. src/shared is one
 * chunk that every app loads, and the question type layer is another that
 * imports shared. When the availability reason bridge, which shared pulls in,
 * reached the namespace through the question type barrel, the two chunks
 * imported each other, and every entry that loaded shared first died on a
 * temporal dead zone error before it could mount (the empty player page of
 * 2026-09-30). This module imports nothing, so it can never close that loop
 * again; scripts/check-app-entry-imports.mjs now fails the build on any
 * static import cycle between chunks.
 *
 * The merge semantics are the ones installQuestionTypeBridge has always had:
 * an existing namespace is reused and extended, never replaced, whichever
 * bundle arrives first. A package that loaded a moment early and wrote its
 * registry here therefore keeps it when the free bundle installs the
 * question type surface onto the same object afterwards.
 */

/** The namespace as a registry sees it: an open record each registry adds its own key to. */
export type VidyaLmsNamespace = Record<string, unknown>;

interface NamespaceHost {
    vidyalms?: unknown;
}

/**
 * The page's window.vidyalms, created empty when this bundle is the first to
 * need it. Idempotent: every call on the same window returns the same object.
 */
export function vidyaLmsNamespace(target: Window = window): VidyaLmsNamespace {
    const host = target as unknown as NamespaceHost;
    const existing = host.vidyalms;
    if ((typeof existing === 'object' || typeof existing === 'function') && existing !== null) {
        return existing as VidyaLmsNamespace;
    }
    const created: VidyaLmsNamespace = {};
    host.vidyalms = created;
    return created;
}
