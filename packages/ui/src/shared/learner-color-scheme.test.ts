import { afterEach, describe, expect, it, vi } from 'vitest';
import { waitFor } from '@testing-library/react';
import { learnerColorScheme, observeLearnerColorScheme } from './learner-color-scheme';

const cleanups: Array<() => void> = [];
afterEach(() => {
    cleanups.splice(0).forEach((stop) => stop());
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('data-color-scheme');
    document.documentElement.removeAttribute('data-bs-theme');
    document.body.removeAttribute('class');
    vi.restoreAllMocks();
});
function surface() {
    document.body.innerHTML = '<section><div class="vidyalms"><div id="vidyalms-player"></div></div></section>';
    return document.querySelector<HTMLElement>('.vidyalms')!;
}

describe('learner theme integration', () => {
    it('uses browser/device preference when the host has no explicit theme', () => {
        const root = surface();
        cleanups.push(observeLearnerColorScheme(root));
        expect(root.dataset.vyColorScheme).toBe('auto');
        expect(document.documentElement.hasAttribute('data-color-scheme')).toBe(false);
    });

    it.each(['data-color-scheme', 'data-bs-theme', 'data-theme'])('follows host %s changes without remounting content', async (attribute) => {
        const root = surface();
        const host = root.parentElement!;
        const player = root.firstElementChild;
        host.setAttribute(attribute, 'dark');
        cleanups.push(observeLearnerColorScheme(root));
        expect(root.dataset.vyColorScheme).toBe('dark');
        host.setAttribute(attribute, 'light');
        await waitFor(() => expect(root.dataset.vyColorScheme).toBe('light'));
        host.removeAttribute(attribute);
        await waitFor(() => expect(root.dataset.vyColorScheme).toBe('auto'));
        expect(root.firstElementChild).toBe(player);
    });

    it('follows class-based WordPress themes', async () => {
        const root = surface();
        document.body.classList.add('dark');
        cleanups.push(observeLearnerColorScheme(root));
        expect(root.dataset.vyColorScheme).toBe('dark');
        document.body.className = 'theme-light';
        await waitFor(() => expect(root.dataset.vyColorScheme).toBe('light'));
    });

    it('gives the nearest explicit choice priority over outer themes', () => {
        const root = surface();
        document.documentElement.setAttribute('data-color-scheme', 'dark');
        root.parentElement!.setAttribute('data-theme', 'light');
        expect(learnerColorScheme(root)).toBe('light');
        root.setAttribute('data-color-scheme', 'dark');
        expect(learnerColorScheme(root)).toBe('dark');
        root.setAttribute('data-color-scheme', 'auto');
        expect(learnerColorScheme(root)).toBe('auto');
    });

    it('accepts a CSS-only host color-scheme without latching its previous result', () => {
        const root = surface();
        const style = { colorScheme: 'only dark' } as CSSStyleDeclaration;
        vi.spyOn(window, 'getComputedStyle').mockReturnValue(style);
        cleanups.push(observeLearnerColorScheme(root));
        expect(root.dataset.vyColorScheme).toBe('dark');
        style.colorScheme = 'light dark';
        expect(learnerColorScheme(root)).toBe('auto');
    });

    it('initializes fresh SPA content from the current host and cleans up observers', async () => {
        const root = surface();
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        const stop = observeLearnerColorScheme(root);
        stop();
        document.documentElement.setAttribute('data-bs-theme', 'light');
        const next = root.cloneNode(true) as HTMLElement;
        root.replaceWith(next);
        cleanups.push(observeLearnerColorScheme(next));
        expect(next.dataset.vyColorScheme).toBe('light');
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        await waitFor(() => expect(next.dataset.vyColorScheme).toBe('dark'));
        expect(root.dataset.vyColorScheme).toBeUndefined();
    });

    it('rechecks CSS-only host themes on browser preference changes and removes its listener', () => {
        const root = surface();
        const media = new EventTarget();
        vi.spyOn(window, 'matchMedia').mockReturnValue(media as MediaQueryList);
        const style = { colorScheme: 'dark' } as CSSStyleDeclaration;
        vi.spyOn(window, 'getComputedStyle').mockReturnValue(style);
        const stop = observeLearnerColorScheme(root);
        expect(root.dataset.vyColorScheme).toBe('dark');
        style.colorScheme = 'light';
        media.dispatchEvent(new Event('change'));
        expect(root.dataset.vyColorScheme).toBe('light');
        stop();
        style.colorScheme = 'dark';
        media.dispatchEvent(new Event('change'));
        expect(root.dataset.vyColorScheme).toBeUndefined();
    });

    it('treats a host that never declared a colour scheme as light', () => {
        const root = surface();
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({ colorScheme: 'normal' } as CSSStyleDeclaration);
        expect(learnerColorScheme(root)).toBe('light');
        cleanups.push(observeLearnerColorScheme(root));
        expect(root.dataset.vyColorScheme).toBe('light');
    });
});
