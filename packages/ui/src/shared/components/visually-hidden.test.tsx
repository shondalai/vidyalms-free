import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { VisuallyHidden } from './visually-hidden';

describe('VisuallyHidden', () => {
    it('keeps the text in the accessibility tree', () => {
        render(<VisuallyHidden>Loading lesson</VisuallyHidden>);
        expect(screen.getByText('Loading lesson')).toBeInTheDocument();
    });

    it('clips itself visually without any stylesheet', () => {
        render(<VisuallyHidden>Hidden</VisuallyHidden>);
        const el = screen.getByText('Hidden');
        expect(el.style.position).toBe('absolute');
        expect(el.style.overflow).toBe('hidden');
        expect(el.style.width).toBe('1px');
    });
});
