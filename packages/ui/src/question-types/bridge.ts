import * as React from 'react';
import * as ReactDom from 'react-dom';
import * as ReactDomClient from 'react-dom/client';
import * as JsxRuntime from 'react/jsx-runtime';
import { QuestionTypeRegistry } from './registry';
import { PUBLISHED_REACT_MAJOR, QUESTION_TYPE_CONTRACT, type QuestionTypeRegistryApi } from './contract';
import * as Primitives from './primitives';
import { createReactQuestionModule } from './react-module';

/*
 * The single documented carve-out to the mount contract in
 * src/shared/config.ts, which otherwise says every app reads only the
 * data-vy-config JSON on its mount element.
 *
 * The free bundle creates window.vidyalms.questionTypes at module scope
 * before it mounts anything, in BOTH the quiz-runner and the instructor
 * entries, because they are separate bundles that never share a page. A
 * third-party bundle calls
 *
 *   window.vidyalms.questionTypes.register('x_acme_molecule', module)
 *
 * at its own module scope; load order is declarative (a joomla.asset.json
 * dependency, a wp_enqueue_script dependency), and the runner subscribes to
 * the registry change event so a deferred chunk that registers late still
 * renders without a reload.
 *
 * The React runtime is published for the same reason. The learner side is
 * framework neutral (mount(el, ctx)) but the authoring side is a
 * ComponentType, so without these a third party would ship a second React,
 * which breaks hooks and doubles the payload, or ship no authoring UI.
 */

export interface VidyaLmsGlobal {
    readonly questionTypes: QuestionTypeRegistryApi;
    readonly react: typeof React;
    readonly reactDom: typeof ReactDom & typeof ReactDomClient;
    readonly jsxRuntime: typeof JsxRuntime;
    /**
     * The eight audited client interaction primitives.
     *
     * ADR 0011 states the accessibility consequence as a property of the
     * extension point: a type that composes these gets keyboard operation,
     * non-drag pointer operation, announcements and focus management without
     * re-litigating them. That was true for a package built inside this
     * repository, which reaches them through a build-time alias into
     * packages/ui/src, and false for everybody else, who was handed a bare
     * element and had to reimplement select-then-place from scratch. They are
     * published on the same documented global as the registry so the claim is
     * true for the audience it was made about.
     */
    readonly primitives: typeof Primitives;
    /**
     * The React wrapper the free six are built with, so a third-party module
     * is the same object rather than a hand-rolled imitation of one.
     */
    readonly createReactQuestionModule: typeof createReactQuestionModule;
    /** The question type contract version this bundle speaks. */
    readonly questionTypeContract: number;
    /** The React major a third-party build must mark external against. */
    readonly reactMajor: number;
}

declare global {
    interface Window {
        vidyalms?: Partial<VidyaLmsGlobal> & Record<string, unknown>;
    }
}

/**
 * Idempotent. Two entries never share a page, but a hot reload, a test file
 * and a host that loads a bundle twice all do, and a second registry would
 * silently strand every renderer registered against the first.
 */
export function installQuestionTypeBridge(target: Window = window): QuestionTypeRegistryApi {
    const existing = target.vidyalms?.questionTypes;
    if (existing !== undefined) {
        return existing;
    }
    const registry = new QuestionTypeRegistry();
    const bridge: VidyaLmsGlobal = {
        questionTypes: registry,
        react: React,
        // Add-ons externalize both entry points to this shared namespace.
        // Portals and synchronous event flushing belong to react-dom, not
        // react-dom/client; publishing only createRoot breaks modal dialogs.
        reactDom: { ...ReactDom, ...ReactDomClient },
        jsxRuntime: JsxRuntime,
        primitives: Primitives,
        createReactQuestionModule,
        questionTypeContract: QUESTION_TYPE_CONTRACT,
        reactMajor: PUBLISHED_REACT_MAJOR,
    };
    target.vidyalms = Object.assign(target.vidyalms ?? {}, bridge);
    return registry;
}

/**
 * The registry this page is using. Every consumer goes through here rather
 * than holding a module-scope singleton, so a renderer registered on the
 * global by a third-party bundle and one registered by the free bundle land
 * in the same place.
 */
export function questionTypeRegistry(): QuestionTypeRegistryApi {
    return installQuestionTypeBridge();
}

/**
 * The whole published surface, installing it first if this bundle got here
 * before the one that usually creates it.
 *
 * This is the route a renderer package uses to reach the audited primitives,
 * and it is deliberately the same object a third party reads off
 * window.vidyalms rather than a private import: a claim that composing the
 * primitives buys accessibility is only true for whoever can reach them.
 */
export function vidyaLmsBridge(target: Window = window): VidyaLmsGlobal {
    installQuestionTypeBridge(target);

    return target.vidyalms as VidyaLmsGlobal;
}

/*
 * The bare namespace, for a registry that needs window.vidyalms but not the
 * question type surface. It lives in src/shared so that shared code can reach
 * it without importing this layer (see that module for why), and is
 * re-exported here so the question type bridge stays the one documented
 * import for everything published on window.vidyalms.
 */
export { vidyaLmsNamespace, type VidyaLmsNamespace } from '@/shared/vidyalms-namespace';
