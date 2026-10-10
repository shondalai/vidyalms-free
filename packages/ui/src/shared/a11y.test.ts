import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { announce, moveFocus } from './a11y';

describe('moveFocus', () => {
    it('focuses a natively focusable element', () => {
        const button = document.createElement('button');
        document.body.appendChild(button);
        moveFocus(button);
        expect(document.activeElement).toBe(button);
    });

    it('makes a heading programmatically focusable, then focuses it', () => {
        const heading = document.createElement('h2');
        heading.textContent = 'Question 4';
        document.body.appendChild(heading);
        moveFocus(heading);
        expect(heading.getAttribute('tabindex')).toBe('-1');
        expect(document.activeElement).toBe(heading);
    });

    it('ignores null without throwing', () => {
        expect(() => moveFocus(null)).not.toThrow();
    });
});

describe('announce', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('creates one persistent polite region inside the container and speaks through it', () => {
        const container = document.createElement('div');
        document.body.appendChild(container);

        announce(container, 'Saved just now');
        const region = container.querySelector('[aria-live="polite"]');
        expect(region).not.toBeNull();
        expect(region).toHaveAttribute('aria-atomic', 'true');

        vi.advanceTimersByTime(60);
        expect(region?.textContent).toBe('Saved just now');

        announce(container, 'Submitted');
        vi.advanceTimersByTime(60);
        expect(container.querySelectorAll('[aria-live="polite"]').length).toBe(1);
        expect(region?.textContent).toBe('Submitted');
    });

    it('supports assertive announcements in a separate region', () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        announce(container, 'Time is up', 'assertive');
        vi.advanceTimersByTime(60);
        expect(container.querySelector('[aria-live="assertive"]')?.textContent).toBe('Time is up');
    });
});
