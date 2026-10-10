import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProblemError } from '@vidyalms/api-sdk';
import { ErrorState } from './error-state';

describe('ErrorState', () => {
    it('is an alert with the artboard claim and a retry action', async () => {
        const onRetry = vi.fn();
        render(<ErrorState onRetry={onRetry} />);
        const alert = screen.getByRole('alert');
        expect(alert).toHaveTextContent("Couldn't load.");
        await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(onRetry).toHaveBeenCalledTimes(1);
    });

    it('shows the problem detail verbatim when the server sent one', () => {
        const error = new ProblemError({
            type: 'about:blank',
            title: 'Conflict',
            status: 409,
            detail: 'All 3 attempts are used.',
        });
        render(<ErrorState error={error} />);
        expect(screen.getByRole('alert')).toHaveTextContent('All 3 attempts are used.');
    });

    it('maps statuses to plain language when the problem has no detail', () => {
        const error = new ProblemError({ type: 'about:blank', title: 'Unauthorized', status: 401 });
        render(<ErrorState error={error} />);
        expect(screen.getByRole('alert')).toHaveTextContent('Sign in to continue.');
    });

    it('treats a network failure as "no response"', () => {
        render(<ErrorState error={new TypeError('fetch failed')} />);
        expect(screen.getByRole('alert')).toHaveTextContent("The server didn't respond.");
    });

    it('renders without a retry button when no handler is given', () => {
        render(<ErrorState />);
        expect(screen.queryByRole('button')).toBeNull();
    });
});
