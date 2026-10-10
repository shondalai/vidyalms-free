import { describe, expect, it } from 'vitest';
import { PUBLISHED_REACT_MAJOR } from '@/question-types';
import { ConditionEditorRegistrationError } from './contract';
import { ConditionEditorRegistry } from './registry';
import { installConditionEditorBridge } from './bridge';

/*
 * The condition editor registry.
 *
 * The three rules every registry in this codebase has, for the reasons the
 * coding standards give: register() refuses a taken key so two packages that
 * both believe they own one fail at boot rather than by script order,
 * replace() requires the key and returns what it displaced so an override
 * decorates rather than reimplements, and there is no unregister(), because
 * a stored leaf names a key that has to stay resolvable.
 *
 * The one thing that is DIFFERENT here, and is the point of the seam: a miss
 * is not an error. A condition with no registered editor is drawn from the
 * schema the provider declares, so a third-party condition is authorable the
 * day it installs with no editor at all.
 */

function editor() {
    return { version: 1, Editor: () => null };
}

describe('registration', () => {
    it('refuses a key that is already taken', () => {
        const registry = new ConditionEditorRegistry();
        registry.register('date', editor());
        expect(() => registry.register('date', editor())).toThrow(ConditionEditorRegistrationError);
    });

    it('refuses a replacement of a key nothing registered', () => {
        const registry = new ConditionEditorRegistry();
        expect(() => registry.replace('date', editor())).toThrow(ConditionEditorRegistrationError);
    });

    it('hands back the displaced module so an override can delegate to it', () => {
        const registry = new ConditionEditorRegistry();
        const first = editor();
        registry.register('date', first);
        expect(registry.replace('date', editor())).toBe(first);
    });

    it('refuses an editor written against a newer contract than this bundle speaks', () => {
        const registry = new ConditionEditorRegistry();
        expect(() => registry.register('date', { version: 99, Editor: () => null })).toThrow(
            /newer than the VidyaLMS assets/,
        );
    });

    it('refuses an editor built against another React major, at registration rather than at render', () => {
        const registry = new ConditionEditorRegistry();
        expect(() =>
            registry.register('date', { version: 1, reactMajor: PUBLISHED_REACT_MAJOR + 1, Editor: () => null }),
        ).toThrow(/publishes React/);
    });

    it('refuses a key that is not a key', () => {
        const registry = new ConditionEditorRegistry();
        expect(() => registry.register('  ', editor())).toThrow(ConditionEditorRegistrationError);
    });
});

describe('a miss', () => {
    /* The consumer draws the provider's own schema instead, which is the
       direction the registry-miss table publishes for an authoring surface:
       the author still sees what is stored and can still change it. */
    it('answers null rather than throwing, because the fallback is a real path', () => {
        const registry = new ConditionEditorRegistry();
        expect(registry.get('acme.forum_posts')).toBeNull();
        expect(registry.has('acme.forum_posts')).toBe(false);
    });
});

describe('late registration', () => {
    it('tells a subscriber when its key arrives, so a deferred chunk still replaces the fallback', () => {
        const registry = new ConditionEditorRegistry();
        const seen: string[] = [];
        registry.subscribe((event) => seen.push(`${event.kind}:${event.key}`));

        registry.register('date', editor());
        registry.replace('date', editor());

        expect(seen).toEqual(['register:date', 'replace:date']);
    });

    it('stops telling a subscriber that unsubscribed', () => {
        const registry = new ConditionEditorRegistry();
        const seen: string[] = [];
        const off = registry.subscribe((event) => seen.push(event.key));
        off();
        registry.register('date', editor());
        expect(seen).toEqual([]);
    });
});

describe('the bridge', () => {
    it('is idempotent, so whichever bundle arrives first creates the one registry', () => {
        const target = { vidyalms: undefined } as unknown as Window;
        const first = installConditionEditorBridge(target);
        first.register('date', editor());
        const second = installConditionEditorBridge(target);
        expect(second).toBe(first);
        expect(second.has('date')).toBe(true);
    });
});
