import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FilterChips } from './filter-chips';

const CHIPS = [
    { value: 'all', label: 'All' },
    { value: 'published', label: 'Published', count: 14 },
    { value: 'draft', label: 'Draft', count: 3 },
];

describe('FilterChips', () => {
    it('is a named group whose active chip is pressed', () => {
        render(<FilterChips label="Filters" chips={CHIPS} value="published" onChange={vi.fn()} />);
        expect(screen.getByRole('group', { name: 'Filters' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Published (14)' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'All' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('carries the count into the accessible name when there is one', () => {
        render(<FilterChips label="Filters" chips={CHIPS} value="all" onChange={vi.fn()} />);
        expect(screen.getByRole('button', { name: 'Draft (3)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'All' })).toBeInTheDocument();
    });

    it('reports the chosen value', async () => {
        const onChange = vi.fn();
        render(<FilterChips label="Filters" chips={CHIPS} value="all" onChange={onChange} />);
        await userEvent.click(screen.getByRole('button', { name: 'Draft (3)' }));
        expect(onChange).toHaveBeenCalledWith('draft');
    });
});
