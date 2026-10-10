import { describe, expect, it } from 'vitest';
import { vidyaLmsNamespace } from './vidyalms-namespace';
import { installReasonRendererBridge } from '@/availability-reasons/bridge';
import * as barrel from '@/question-types';
import { installQuestionTypeBridge, vidyaLmsBridge } from '@/question-types/bridge';

/*
 * window.vidyalms reached without the question type layer.
 *
 * The availability reason bridge used to find the namespace through the
 * question type barrel, which made the shared and question type chunks import
 * each other. These cases pin what the move must not change: one object per
 * page, extended and never replaced, in whichever order the registries and
 * bundles arrive, and the same names on the question type bridge as before.
 * Each case uses its own window stand-in, because the suite setup has already
 * installed the real one.
 */

function page(initial?: unknown): Window {
    return (initial === undefined ? {} : { vidyalms: initial }) as unknown as Window;
}

describe('the bare window.vidyalms namespace', () => {
    it('creates one empty namespace and hands back the same object every time', () => {
        const target = page();

        const first = vidyaLmsNamespace(target);

        expect(first).toEqual({});
        expect(vidyaLmsNamespace(target)).toBe(first);
        expect(target.vidyalms).toBe(first);
    });

    it('reuses a namespace a bundle created earlier rather than replacing it', () => {
        const earlier = { conditionEditors: { contract: 1 } };
        const target = page(earlier);

        expect(vidyaLmsNamespace(target)).toBe(earlier);
        expect(target.vidyalms).toBe(earlier);
    });

    it('treats a value that cannot carry registries as absent', () => {
        const target = page('not a namespace');

        expect(vidyaLmsNamespace(target)).toEqual({});
        expect(typeof target.vidyalms).toBe('object');
    });

    it('keeps the reason renderers and the question type surface on one object when reasons arrive first', () => {
        const target = page();

        const reasons = installReasonRendererBridge(target);
        const namespace = vidyaLmsNamespace(target);
        installQuestionTypeBridge(target);

        expect(target.vidyalms).toBe(namespace);
        expect(namespace.availabilityReasons).toBe(reasons);
        expect(installReasonRendererBridge(target)).toBe(reasons);
        expect(vidyaLmsBridge(target).questionTypes).toBe(installQuestionTypeBridge(target));
        expect(typeof vidyaLmsBridge(target).react.createElement).toBe('function');
    });

    it('keeps them on one object when the question type surface arrives first, as on a quiz page', () => {
        const target = page();

        const questionTypes = installQuestionTypeBridge(target);
        const namespace = target.vidyalms;
        const reasons = installReasonRendererBridge(target);

        expect(target.vidyalms).toBe(namespace);
        expect(target.vidyalms?.questionTypes).toBe(questionTypes);
        expect(target.vidyalms?.availabilityReasons).toBe(reasons);
    });

    it('installs only the reason registry where no question type surface is loaded, as on a player page', () => {
        const target = page();

        installReasonRendererBridge(target);

        expect(Object.keys(target.vidyalms ?? {})).toEqual(['availabilityReasons']);
    });

    it('is re-exported from the question type bridge and barrel under its own name, beside the unchanged ones', () => {
        expect(barrel.vidyaLmsNamespace).toBe(vidyaLmsNamespace);
        expect(barrel.vidyaLmsBridge).toBe(vidyaLmsBridge);
        expect(barrel.installQuestionTypeBridge).toBe(installQuestionTypeBridge);
        expect(typeof barrel.questionTypeRegistry).toBe('function');
    });
});
