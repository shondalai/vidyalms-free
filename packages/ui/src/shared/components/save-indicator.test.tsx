import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SaveIndicator } from './save-indicator';

function liveRegion(container: HTMLElement): HTMLElement | null {
    return container.querySelector('[aria-live="polite"]');
}

function visibleLabel(container: HTMLElement): string {
    return container.querySelector('span[aria-hidden="true"]:not([data-testid])')?.textContent ?? '';
}

describe('SaveIndicator', () => {
    it('keeps a persistent polite live region, empty while idle', () => {
        const { container } = render(<SaveIndicator state="idle" />);
        const region = liveRegion(container);
        expect(region).not.toBeNull();
        expect(region?.textContent).toBe('');
        expect(container.querySelector('[data-testid="save-dot"]')).toBeNull();
    });

    it('shows "just now" right after a save', () => {
        const { container } = render(<SaveIndicator state="saved" savedAt={Date.now()} />);
        expect(visibleLabel(container)).toBe('Saved · just now');
    });

    it('shows the honest age of an older save', () => {
        const { container } = render(<SaveIndicator state="saved" savedAt={Date.now() - 12_000} />);
        expect(visibleLabel(container)).toBe('Saved · 12s ago');
    });

    it('escalates to failed with an inline retry', async () => {
        const onRetry = vi.fn();
        const { container } = render(<SaveIndicator state="failed" onRetry={onRetry} />);
        expect(liveRegion(container)?.textContent).toBe('Not saved · retrying');
        await userEvent.click(screen.getByRole('button', { name: 'retry now' }));
        expect(onRetry).toHaveBeenCalledTimes(1);
    });

    it('colours the dot by state but never relies on colour alone', () => {
        const { container, rerender } = render(<SaveIndicator state="saving" />);
        expect(container.querySelector('[data-testid="save-dot"]')?.className).toContain('bg-warn');
        rerender(<SaveIndicator state="saved" savedAt={Date.now()} />);
        expect(container.querySelector('[data-testid="save-dot"]')?.className).toContain('bg-ok');
        rerender(<SaveIndicator state="failed" />);
        expect(container.querySelector('[data-testid="save-dot"]')?.className).toContain('bg-danger');
        /* the dot is decorative; the words carry the state */
        expect(container.querySelector('[data-testid="save-dot"]')).toHaveAttribute('aria-hidden', 'true');
    });

    describe('live region announces transitions only', () => {
        beforeEach(() => {
            vi.useFakeTimers();
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('the ticking timestamp never changes the live region', () => {
            const savedAt = Date.now();
            const { container } = render(<SaveIndicator state="saved" savedAt={savedAt} />);
            expect(liveRegion(container)?.textContent).toBe('Saved');
            expect(visibleLabel(container)).toBe('Saved · just now');
            act(() => {
                vi.advanceTimersByTime(30_000);
            });
            /* The visible text aged; the live region text is unchanged. */
            expect(visibleLabel(container)).toBe('Saved · 30s ago');
            expect(liveRegion(container)?.textContent).toBe('Saved');
        });

        it('the routine saving->saved heartbeat cycle repeats the same live text', () => {
            const { container, rerender } = render(<SaveIndicator state="saved" savedAt={Date.now()} />);
            expect(liveRegion(container)?.textContent).toBe('Saved');
            /* Next heartbeat starts: the region keeps its stable message. */
            rerender(<SaveIndicator state="saving" savedAt={Date.now()} />);
            expect(liveRegion(container)?.textContent).toBe('Saved');
            expect(visibleLabel(container)).toBe('Saving…');
            rerender(<SaveIndicator state="saved" savedAt={Date.now()} />);
            expect(liveRegion(container)?.textContent).toBe('Saved');
        });

        it('announces the transitions to failed and back to saved', () => {
            const { container, rerender } = render(<SaveIndicator state="saved" savedAt={Date.now()} />);
            rerender(<SaveIndicator state="failed" />);
            expect(liveRegion(container)?.textContent).toBe('Not saved · retrying');
            rerender(<SaveIndicator state="offline" />);
            expect(liveRegion(container)?.textContent).toBe('Sync paused');
            rerender(<SaveIndicator state="saved" savedAt={Date.now()} />);
            expect(liveRegion(container)?.textContent).toBe('Saved');
        });
    });
});
