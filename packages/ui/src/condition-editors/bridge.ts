import { useSyncExternalStore } from 'react';
import { vidyaLmsBridge } from '@/question-types';
import { ConditionEditorRegistry } from './registry';
import type { ConditionEditorModule, ConditionEditorRegistryApi } from './contract';

/*
 * The third published registry, installed beside window.vidyalms.questionTypes
 * and window.vidyalms.runnerPanels and reached the same documented way.
 * Installing the question type bridge first is deliberate: whichever bundle
 * gets here first creates the one window.vidyalms, and a package that loaded
 * a moment early must not end up writing its editors into an object the free
 * bundle later replaces.
 */

/**
 * Idempotent. Two entries never share a page, but a hot reload, a test file
 * and a host that loads a bundle twice all do, and a second registry would
 * strand every editor registered against the first.
 */
export function installConditionEditorBridge(target: Window = window): ConditionEditorRegistryApi {
    const global = vidyaLmsBridge(target) as unknown as Record<string, unknown>;
    const existing = global.conditionEditors;
    if (existing !== undefined && existing !== null) {
        return existing as ConditionEditorRegistryApi;
    }
    const registry = new ConditionEditorRegistry();
    global.conditionEditors = registry;
    return registry;
}

/** The registry this page is using; never a module-scope singleton. */
export function conditionEditorRegistry(): ConditionEditorRegistryApi {
    return installConditionEditorBridge();
}

/**
 * The editor registered for a condition key, re-read when one registers
 * late. Null means the caller draws the provider's params_schema instead,
 * which is a normal path and not a failure.
 */
export function useConditionEditor(key: string): ConditionEditorModule | null {
    const registry = conditionEditorRegistry();
    return useSyncExternalStore(
        (onChange) =>
            registry.subscribe((event) => {
                if (event.key === key) {
                    onChange();
                }
            }),
        () => registry.get(key),
        () => null,
    );
}
