import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Spinner } from './spinner';

describe('Spinner', () => {
    it('is a status region with a default accessible label', () => {
        render(<Spinner />);
        const status = screen.getByRole('status');
        expect(status).toHaveTextContent('Loading');
    });

    it('accepts a specific label', () => {
        render(<Spinner label="Submitting attempt" />);
        expect(screen.getByRole('status')).toHaveTextContent('Submitting attempt');
    });

    it('hides the icon from assistive tech and stills under reduced motion', () => {
        const { container } = render(<Spinner />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveAttribute('aria-hidden', 'true');
        expect(svg?.getAttribute('class')).toContain('motion-reduce:animate-none');
    });
});
