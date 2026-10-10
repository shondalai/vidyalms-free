import { useSyncExternalStore } from 'react';
import { vidyaLmsBridge } from '@/question-types';
import { RunnerPanelRegistry } from './registry';
import type { RunnerPanelModule, RunnerPanelRegistryApi, RunnerPanelSlot } from './contract';

/*
 * The second published registry, installed beside window.vidyalms.questionTypes
 * and reached the same documented way. Installing the question type bridge
 * first is deliberate: whichever bundle gets here first creates the one
 * window.vidyalms, and a package that loaded a moment early must not end up
 * writing its panels into an object the free bundle later replaces.
 */

declare global {
    interface Window {
        vidyalmsRunnerPanels?: RunnerPanelRegistryApi;
    }
}

/**
 * Idempotent. Two entries never share a page, but a hot reload, a test file
 * and a host that loads a bundle twice all do, and a second registry would
 * strand every panel registered against the first.
 */
export function installRunnerPanelBridge(target: Window = window): RunnerPanelRegistryApi {
    const global = vidyaLmsBridge(target) as unknown as Record<string, unknown>;
    const existing = global.runnerPanels;
    if (existing !== undefined && existing !== null) {
        return existing as RunnerPanelRegistryApi;
    }
    const registry = new RunnerPanelRegistry();
    global.runnerPanels = registry;
    return registry;
}

/** The registry this page is using; never a module-scope singleton. */
export function runnerPanelRegistry(): RunnerPanelRegistryApi {
    return installRunnerPanelBridge();
}

/**
 * The module registered for a slot, re-read when one registers late.
 *
 * A deferred Pro chunk that lands after the runner mounted would otherwise
 * leave the placeholder up for the rest of the attempt, which is the failure
 * the registry change event exists to prevent; subscribing is what makes the
 * event reach React.
 */
export function useRunnerPanel<S extends RunnerPanelSlot>(slot: S): RunnerPanelModule<S> | null {
    const registry = runnerPanelRegistry();
    return useSyncExternalStore(
        (onChange) => registry.subscribe((event) => {
            if (event.slot === slot) {
                onChange();
            }
        }),
        () => registry.get(slot),
        () => null,
    );
}
