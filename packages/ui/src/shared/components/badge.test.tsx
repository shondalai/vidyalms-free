import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Badge } from './badge';

describe('Badge', () => {
    it('always pairs colour with a glyph and a word', () => {
        render(<Badge variant="ok">Passed</Badge>);
        const badge = screen.getByText('Passed').closest('span');
        expect(badge).not.toBeNull();
        const glyph = badge?.querySelector('[aria-hidden="true"]');
        expect(glyph).not.toBeNull();
        expect(glyph?.textContent).toBe('✓');
    });

    it('renders the artboard state set with distinct glyphs', () => {
        const { container } = render(
            <>
                <Badge variant="ok">Passed</Badge>
                <Badge variant="danger">Not passed</Badge>
                <Badge variant="warn">Pending grade</Badge>
                <Badge variant="neutral">Suspended</Badge>
            </>,
        );
        const glyphs = Array.from(container.querySelectorAll('[aria-hidden="true"]')).map(
            (el) => el.textContent,
        );
        expect(glyphs).toEqual(['✓', '✕', '◔', '−']);
    });

    it('drops the glyph when explicitly passed null', () => {
        render(<Badge variant="ok" glyph={null}>Active</Badge>);
        const badge = screen.getByText('Active').closest('span');
        expect(badge?.querySelector('[aria-hidden="true"]')).toBeNull();
    });

    it('applies the variant classes', () => {
        render(<Badge variant="danger">Not passed</Badge>);
        expect(screen.getByText('Not passed').closest('span')?.className).toContain('text-danger');
    });
});
