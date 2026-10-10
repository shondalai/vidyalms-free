import { translate as uiText } from '@/shared/i18n';
import type { ReactNode } from 'react';
import type { VidyaLmsClient } from '@vidyalms/api-sdk';
import type { AdminConfig } from '../config';

export interface AdminScreenRuntime {
    /** Validated mount data owned by the free admin shell. */
    config: AdminConfig;
    /** The shell's authenticated, CSRF-aware client. */
    client: VidyaLmsClient;
    /** Current screen-local hash without the leading #. */
    hash: string;
    navigate: (hash: string) => void;
    replace: (hash: string) => void;
    mountEl: HTMLElement | null;
}

export interface AdminScreenDefinition {
    key: string;
    label: string;
    /** Called by the free shell so an external bundle needs no copied context. */
    render: (runtime: AdminScreenRuntime) => ReactNode;
}

export interface AdminScreenRegistryApi {
    register(definition: AdminScreenDefinition): void;
    replace(definition: AdminScreenDefinition): AdminScreenDefinition;
    get(key: string): AdminScreenDefinition | undefined;
    keys(): string[];
    subscribe(listener: () => void): () => void;
    getSnapshot(): number;
    reset(): void;
}

/** Version of the runtime passed to contributed admin screens. */
export const ADMIN_SCREEN_CONTRACT = 1;

class AdminScreenRegistry implements AdminScreenRegistryApi {
    private readonly screens = new Map<string, AdminScreenDefinition>();
    private readonly listeners = new Set<() => void>();
    private revision = 0;

    register(definition: AdminScreenDefinition): void {
        if (this.screens.has(definition.key)) {
            throw new Error(uiText("vidyalms.ui.8209ad5288be97d4", "The admin screen \"{p0}\" is already registered.", { p0: definition.key }));
        }
        this.screens.set(definition.key, definition);
        this.changed();
    }

    replace(definition: AdminScreenDefinition): AdminScreenDefinition {
        const displaced = this.screens.get(definition.key);
        if (displaced === undefined) {
            throw new Error(uiText("vidyalms.ui.db8b4832269a6c78", "The admin screen \"{p0}\" is not registered, so it cannot be replaced.", { p0: definition.key }));
        }
        this.screens.set(definition.key, definition);
        this.changed();
        return displaced;
    }

    get(key: string): AdminScreenDefinition | undefined {
        return this.screens.get(key);
    }

    keys(): string[] {
        return [...this.screens.keys()];
    }

    subscribe(listener: () => void): () => void {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }

    getSnapshot(): number {
        return this.revision;
    }

    reset(): void {
        this.screens.clear();
        this.changed();
    }

    private changed(): void {
        this.revision += 1;
        for (const listener of [...this.listeners]) {
            listener();
        }
    }
}

type AdminGlobal = Record<string, unknown> & {
    adminScreens?: AdminScreenRegistryApi;
    adminScreenContract?: number;
};
type WindowWithAdminScreens = Window & { vidyalms?: AdminGlobal };

/** One cross-bundle registry, installed before either free or Pro mounts. */
export function installAdminScreenBridge(target: Window = window): AdminScreenRegistryApi {
    const bridgeTarget = target as WindowWithAdminScreens;
    const globalValue = bridgeTarget.vidyalms ?? {};
    if (globalValue.adminScreens !== undefined) {
        if (globalValue.adminScreenContract !== ADMIN_SCREEN_CONTRACT) {
            throw new Error(
                `The admin screen bridge uses contract ${String(globalValue.adminScreenContract)}, ` +
                `but this bundle requires ${ADMIN_SCREEN_CONTRACT}.`,
            );
        }
        return globalValue.adminScreens;
    }
    const registry = new AdminScreenRegistry();
    globalValue.adminScreens = registry;
    globalValue.adminScreenContract = ADMIN_SCREEN_CONTRACT;
    bridgeTarget.vidyalms = globalValue;
    return registry;
}

/** Explicit guard external bundles call before registering their screens. */
export function requireAdminScreenBridge(target: Window = window): AdminScreenRegistryApi {
    return installAdminScreenBridge(target);
}

export function adminScreenRegistry(): AdminScreenRegistryApi {
    return installAdminScreenBridge();
}

export function registerAdminScreen(definition: AdminScreenDefinition): void {
    adminScreenRegistry().register(definition);
}

export function replaceAdminScreen(definition: AdminScreenDefinition): AdminScreenDefinition {
    return adminScreenRegistry().replace(definition);
}

export function adminScreen(key: string): AdminScreenDefinition | undefined {
    return adminScreenRegistry().get(key);
}

export function adminScreenKeys(): string[] {
    return adminScreenRegistry().keys();
}

export function subscribeAdminScreens(listener: () => void): () => void {
    return adminScreenRegistry().subscribe(listener);
}

export function adminScreensSnapshot(): number {
    return adminScreenRegistry().getSnapshot();
}

/** Test-only: the global registry otherwise outlives each rendered tree. */
export function resetAdminScreens(): void {
    adminScreenRegistry().reset();
}
