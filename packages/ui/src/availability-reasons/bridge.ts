import { vidyaLmsNamespace } from '@/shared/vidyalms-namespace';
import { ReasonRendererRegistry } from './registry';
import type { ReasonRendererRegistryApi } from './contract';

/*
 * The fourth published registry, installed beside window.vidyalms.questionTypes,
 * window.vidyalms.runnerPanels and window.vidyalms.conditionEditors, and
 * reached the same documented way: whichever bundle gets here first creates
 * the one window.vidyalms, and nothing that arrives later replaces it.
 *
 * Unlike the other registries it takes the namespace from
 * src/shared/vidyalms-namespace.ts rather than through the question type
 * bridge. It is the only registry the shared chunk pulls in (through
 * src/shared/availability.ts), so reaching the namespace through the question
 * type barrel made the shared and question type chunks import each other, and
 * every learner entry that loaded shared first died at load. A reason renderer
 * returns a sentence and needs neither the React runtime nor the primitives.
 * The merge rule is unchanged: the namespace is extended, never replaced, so
 * the question type surface a quiz, workbench or admin entry installs at
 * module scope lands on the same object as this registry, in either order.
 */

/**
 * Idempotent. Two entries never share a page, but a hot reload, a test file
 * and a host that loads a bundle twice all do, and a second registry would
 * strand every renderer registered against the first.
 */
export function installReasonRendererBridge(target: Window = window): ReasonRendererRegistryApi {
    const global = vidyaLmsNamespace(target);
    const existing = global.availabilityReasons;
    if (existing !== undefined && existing !== null) {
        return existing as ReasonRendererRegistryApi;
    }
    const registry = new ReasonRendererRegistry();
    global.availabilityReasons = registry;
    return registry;
}

/** The registry this page is using; never a module-scope singleton. */
export function reasonRendererRegistry(): ReasonRendererRegistryApi {
    return installReasonRendererBridge();
}
