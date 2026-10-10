import { afterEach, describe, expect, it, vi } from 'vitest';
import { registerFreeAdminScreens } from './index';
import {
    ADMIN_SCREEN_CONTRACT,
    adminScreen,
    installAdminScreenBridge,
    registerAdminScreen,
    resetAdminScreens,
} from './registry';

afterEach(() => resetAdminScreens());

describe('the cross-bundle admin screen bridge', () => {
    it('registers learning and notification screens without native orders', () => {
        registerFreeAdminScreens();
        expect(adminScreen('orders')).toBeUndefined();
        expect(adminScreen('notifications')).toBeDefined();
        expect(adminScreen('automations')).toBeDefined();
        expect(adminScreen('enrolments')).toBeDefined();
    });
    it('installs once and publishes its contract version', () => {
        const first = installAdminScreenBridge();
        const second = installAdminScreenBridge();
        expect(second).toBe(first);
        expect((window.vidyalms as Record<string, unknown>).adminScreenContract).toBe(ADMIN_SCREEN_CONTRACT);
    });

    it('notifies a mounted shell when a bundle registers later', () => {
        const registry = installAdminScreenBridge();
        const listener = vi.fn();
        const unsubscribe = registry.subscribe(listener);
        const before = registry.getSnapshot();
        registry.register({ key: 'reports', label: 'Reports', render: () => null });
        expect(listener).toHaveBeenCalledOnce();
        expect(registry.getSnapshot()).toBeGreaterThan(before);
        expect(registry.get('reports')?.label).toBe('Reports');
        unsubscribe();
    });

    it('refuses duplicate owners', () => {
        registerAdminScreen({ key: 'reports', label: 'Reports', render: () => null });
        expect(() => registerAdminScreen({ key: 'reports', label: 'Other', render: () => null })).toThrow(/already registered/);
        expect(adminScreen('reports')?.label).toBe('Reports');
    });

    it('refuses an incompatible existing contract', () => {
        const fake = { vidyalms: { adminScreens: installAdminScreenBridge(), adminScreenContract: 99 } } as unknown as Window;
        expect(() => installAdminScreenBridge(fake)).toThrow(/requires 1/);
    });
});
