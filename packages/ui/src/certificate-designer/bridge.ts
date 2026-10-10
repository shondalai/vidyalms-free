import { useSyncExternalStore } from 'react';
import { vidyaLmsBridge } from '@/question-types';
import { CertificateDesignerRegistry } from './registry';
import type {
    CertificateDesignerModule,
    CertificateDesignerRegistryApi,
    CertificateDesignerSlot,
} from './contract';

/*
 * The fifth published registry, installed beside window.vidyalms.questionTypes,
 * .runnerPanels, .conditionEditors and .reasonRenderers, and reached the same
 * documented way. Installing the question type bridge first is deliberate:
 * whichever bundle gets here first creates the one window.vidyalms, and a
 * package that loaded a moment early must not end up writing its designer
 * into an object the free bundle later replaces.
 */

/**
 * Idempotent. Two entries never share a page, but a hot reload, a test file
 * and a host that loads a bundle twice all do, and a second registry would
 * strand a designer registered against the first.
 */
export function installCertificateDesignerBridge(target: Window = window): CertificateDesignerRegistryApi {
    const global = vidyaLmsBridge(target) as unknown as Record<string, unknown>;
    const existing = global.certificateDesigners;
    if (existing !== undefined && existing !== null) {
        return existing as CertificateDesignerRegistryApi;
    }
    const registry = new CertificateDesignerRegistry();
    global.certificateDesigners = registry;
    return registry;
}

/** The registry this page is using; never a module-scope singleton. */
export function certificateDesignerRegistry(): CertificateDesignerRegistryApi {
    return installCertificateDesignerBridge();
}

/**
 * The module registered for a slot, re-read when one registers late.
 *
 * A deferred Pro chunk that lands after the template editor mounted would
 * otherwise leave the form editor up for the rest of the session, which is
 * the failure the registry change event exists to prevent; subscribing is
 * what makes the event reach React.
 */
export function useCertificateDesigner<S extends CertificateDesignerSlot>(
    slot: S,
): CertificateDesignerModule<S> | null {
    const registry = certificateDesignerRegistry();
    return useSyncExternalStore(
        (onChange) =>
            registry.subscribe((event) => {
                if (event.slot === slot) {
                    onChange();
                }
            }),
        () => registry.get(slot),
        () => null,
    );
}
