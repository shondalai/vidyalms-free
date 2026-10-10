import { createContext, useContext } from 'react';
import type { VidyaLmsClient } from '@vidyalms/api-sdk';
import type { MountConfig } from './config';

/**
 * The learner apps' validated mount contract, provided once by mountApp.
 * An app with a different contract declares its own context beside its own
 * schema and hands both to mountApp (see src/instructor/config.ts), so this
 * one stays exactly as narrow as the player and the quiz runner need.
 */
export const MountConfigContext = createContext<MountConfig | null>(null);

/**
 * The mount element itself. Portalled layers (Dialog, Tooltip) render into
 * it so tokens keep inheriting and the scoped utilities keep applying.
 */
export const MountElContext = createContext<HTMLElement | null>(null);

/** The typed API client, built once per mount from config.api. */
export const ApiClientContext = createContext<VidyaLmsClient | null>(null);

export function useMountConfig(): MountConfig {
    const config = useContext(MountConfigContext);
    if (config === null) {
        throw new Error('useMountConfig must be used inside mountApp');
    }
    return config;
}

/** Null outside a mount (tests); layers then fall back to document.body. */
export function useMountEl(): HTMLElement | null {
    return useContext(MountElContext);
}

export function useApiClient(): VidyaLmsClient {
    const client = useContext(ApiClientContext);
    if (client === null) {
        throw new Error('useApiClient must be used inside mountApp');
    }
    return client;
}
