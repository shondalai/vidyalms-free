import { afterEach, describe, expect, it } from 'vitest';
import { fireEvent } from '@testing-library/dom';
import { enhancePreferenceForms, startPreferenceForms } from './preferences';

const fixture = (original = 'immediate', selected = 'immediate') => `<div><p data-vy-preferences-saved>Saved</p>
<form data-vy-preferences-form data-vy-clean-label="Ready" data-vy-dirty-label="Unsaved" data-vy-saving-label="Saving">
<select aria-label="Email" name="preference[course_progress|email]" data-vy-original-value="${original}">
<option value="immediate" ${selected === 'immediate' ? 'selected' : ''}>Immediately</option><option value="never" ${selected === 'never' ? 'selected' : ''}>Off</option></select>
<p data-vy-preferences-status role="status"></p><button type="reset" data-vy-preferences-reset hidden>Discard</button><button data-vy-preferences-save>Save</button></form></div>`;
let cleanup = () => {};
afterEach(() => {
    cleanup();
    document.body.innerHTML = '';
});

describe('native notification preference enhancement', () => {
    it('tracks changes, restores persisted selections and does not intercept native saving', async () => {
        document.body.innerHTML = fixture();
        cleanup = enhancePreferenceForms();
        const form = document.querySelector('form')!;
        const field = document.querySelector('select')!;
        const save = document.querySelector<HTMLButtonElement>('[data-vy-preferences-save]')!;
        expect(save.disabled).toBe(true);
        fireEvent.change(field, { target: { value: 'never' } });
        expect(save.disabled).toBe(false);
        expect(document.querySelector('[role=status]')).toHaveTextContent('Unsaved');
        expect(document.querySelector('[data-vy-preferences-saved]')).not.toBeVisible();
        expect(window.dispatchEvent(new Event('vidyalms:check-drafts', { cancelable: true }))).toBe(false);
        form.reset();
        await Promise.resolve();
        expect(field.value).toBe('immediate');
        expect(save.disabled).toBe(true);
        expect(window.dispatchEvent(new Event('vidyalms:check-drafts', { cancelable: true }))).toBe(true);
        fireEvent.change(field, { target: { value: 'never' } });
        const event = new Event('submit', { cancelable: true });
        form.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
        expect(form).toHaveAttribute('aria-busy', 'true');
        expect(save).toHaveTextContent('Saving');
        expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(true);
        window.dispatchEvent(new Event('pageshow'));
        expect(save.disabled).toBe(false);
        expect(form).not.toHaveAttribute('aria-busy');
    });

    it('lets a failed save retry without losing its draft and discards to the server value', async () => {
        document.body.innerHTML = fixture('immediate', 'never');
        cleanup = enhancePreferenceForms();
        expect(document.querySelector('[data-vy-preferences-save]')).not.toBeDisabled();
        expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(false);
        document.querySelector('form')!.reset();
        await Promise.resolve();
        expect(document.querySelector('select')!.value).toBe('immediate');
        expect(document.querySelector('[data-vy-preferences-save]')).toBeDisabled();
    });

    it('enhances newly navigated pages and removes draft handlers when leaving the page', () => {
        document.body.innerHTML = '<div>Catalogue</div>';
        cleanup = startPreferenceForms();
        document.body.innerHTML = fixture();
        window.dispatchEvent(new Event('vidyalms:learner:mount'));
        fireEvent.change(document.querySelector('select')!, { target: { value: 'never' } });
        expect(window.dispatchEvent(new Event('vidyalms:check-drafts', { cancelable: true }))).toBe(false);
        window.dispatchEvent(new Event('vidyalms:learner:unmount'));
        expect(window.dispatchEvent(new Event('vidyalms:check-drafts', { cancelable: true }))).toBe(true);
        document.body.innerHTML = fixture('never', 'never');
        window.dispatchEvent(new Event('vidyalms:learner:mount'));
        expect(document.querySelector('[data-vy-preferences-save]')).toBeDisabled();
        expect(document.querySelector('select')!.value).toBe('never');
    });
});
