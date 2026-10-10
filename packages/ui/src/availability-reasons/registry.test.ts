import { describe, expect, it, beforeEach } from 'vitest';
import { ReasonRendererRegistry } from './registry';
import { ReasonRendererRegistrationError, REASON_RENDERER_CONTRACT } from './contract';
import { registerFreeReasonRenderers } from './free';
import { reasonText, OPEN_ACCESS, type AvailabilityAccess } from '@/shared/availability';
import { reasonRendererRegistry } from './bridge';

/*
 * The fourth published registry, and the half of the condition extension
 * point that was a closed switch: a Pro or third-party condition could
 * contribute an authoring form the day it installed and could never
 * contribute the sentence its own describe() key was designed to carry.
 */

function blocked(reason: string, parameters: Record<string, unknown> = {}): AvailabilityAccess {
    return { ...OPEN_ACCESS, state: 'blocked', reason, reason_parameters: parameters };
}

describe('the availability reason registry', () => {
    let registry: ReasonRendererRegistry;

    beforeEach(() => {
        registry = new ReasonRendererRegistry();
    });

    it('refuses a second registration on a taken key, so two packages that both believe they own one fail loudly', () => {
        registry.register('acme.reason', { version: 1, render: () => 'first' });

        expect(() => registry.register('acme.reason', { version: 1, render: () => 'second' })).toThrow(
            ReasonRendererRegistrationError,
        );
    });

    it('hands back the displaced module on replace, so an override decorates rather than reimplements', () => {
        const first = { version: 1, render: () => 'first' };
        registry.register('acme.reason', first);

        const displaced = registry.replace('acme.reason', { version: 1, render: () => 'second' });

        expect(displaced).toBe(first);
        expect(registry.get('acme.reason')?.render({ access: OPEN_ACCESS, locale: 'en-GB', opensAtLocal: null })).toBe(
            'second',
        );
    });

    it('refuses a module built against a newer contract than this bundle speaks', () => {
        expect(() =>
            registry.register('acme.reason', { version: REASON_RENDERER_CONTRACT + 1, render: () => 'x' }),
        ).toThrow(ReasonRendererRegistrationError);
    });

    it('registers the eight free keys as contributions rather than as a switch', () => {
        registerFreeReasonRenderers(registry);

        expect(registry.keys()).toContain('availability.date.from');
        expect(registry.keys()).toContain('availability.enrolment_source.required');
        expect(registry.keys()).toHaveLength(8);
    });

    it('is idempotent, because a hot reload and a test file both re-enter module scope', () => {
        registerFreeReasonRenderers(registry);
        expect(() => registerFreeReasonRenderers(registry)).not.toThrow();
    });
});

describe('reasonText through the registry', () => {
    it('still renders every free reason exactly as it did', () => {
        expect(reasonText(blocked('availability.role.required'), 'en-GB')).toBe('Not available with your current role');
        expect(reasonText(blocked('availability.date.until'), 'en-GB')).toBe('No longer available');
        expect(reasonText(blocked('availability.completion.required', { title: 'Week 1' }), 'en-GB')).toBe(
            'Available once you complete Week 1',
        );
    });

    it('renders a key nothing claims as the honest general line rather than a blank or the raw key', () => {
        const line = reasonText(blocked('acme.entirely.unknown'), 'en-GB');

        expect(line).toBe('Not available yet');
        expect(line).not.toContain('acme');
    });

    it('lets a package contribute the sentence for its own condition, which was the whole gap', () => {
        reasonRendererRegistry().register('acme.forum_posts.required', {
            version: REASON_RENDERER_CONTRACT,
            render: ({ access }) =>
                `Available once you have posted ${String(access.reason_parameters?.count ?? 0)} times`,
        });

        expect(reasonText(blocked('acme.forum_posts.required', { count: 3 }), 'en-GB')).toBe(
            'Available once you have posted 3 times',
        );
    });
});
