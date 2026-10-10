import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ProgressBar } from './progress-bar';

describe('ProgressBar', () => {
    it('exposes a named progressbar with value semantics', () => {
        render(<ProgressBar value={58} label="Course progress" />);
        const bar = screen.getByRole('progressbar', { name: 'Course progress' });
        expect(bar).toHaveAttribute('aria-valuemin', '0');
        expect(bar).toHaveAttribute('aria-valuemax', '100');
        expect(bar).toHaveAttribute('aria-valuenow', '58');
    });

    it('clamps out-of-range values', () => {
        render(
            <>
                <ProgressBar value={140} label="Over" />
                <ProgressBar value={-3} label="Under" />
            </>,
        );
        expect(screen.getByRole('progressbar', { name: 'Over' })).toHaveAttribute('aria-valuenow', '100');
        expect(screen.getByRole('progressbar', { name: 'Under' })).toHaveAttribute('aria-valuenow', '0');
    });

    it('shows the meta line of the artboard: detail left, percent right', () => {
        render(<ProgressBar value={58.4} label="Course progress" detail="7 of 12 lessons" showValue />);
        expect(screen.getByText('7 of 12 lessons')).toBeInTheDocument();
        expect(screen.getByText('58%')).toBeInTheDocument();
    });

    it('renders no meta line by default', () => {
        const { container } = render(<ProgressBar value={20} label="Quiet" />);
        expect(container.querySelectorAll('div').length).toBeGreaterThan(0);
        expect(container.textContent).toBe('');
    });
});
