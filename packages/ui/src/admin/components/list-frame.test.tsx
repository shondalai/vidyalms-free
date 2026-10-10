import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ListFrame } from './list-frame';

function renderFrame(overrides: Partial<Parameters<typeof ListFrame>[0]> = {}) {
    const props: Parameters<typeof ListFrame>[0] = {
        title: 'Courses',
        meta: '42 total',
        search: {
            label: 'Search',
            placeholder: 'Search courses',
            value: '',
            onChange: vi.fn(),
        },
        chips: <button type="button">Published</button>,
        action: <button type="button">New course</button>,
        page: 1,
        perPage: 20,
        total: 42,
        shownCount: 20,
        onPageChange: vi.fn(),
        children: <div>Course rows</div>,
        ...overrides,
    };
    return render(<ListFrame {...props} />);
}

describe('ListFrame', () => {
    it('places list controls in one named, 40px toolbar', () => {
        renderFrame();

        expect(screen.getByRole('region', { name: 'Courses search and filters' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Courses' }).closest('section')).toHaveClass(
            'w-full',
            'min-w-0',
            'max-w-full',
            'overflow-hidden',
        );
        expect(screen.getByRole('searchbox', { name: 'Search' })).toHaveClass('h-10');
        expect(screen.getByText('42 total')).toBeInTheDocument();
        expect(screen.getByText('1 to 20 of 42')).toHaveAttribute('aria-live', 'polite');
    });

    it('does not show a misleading zero-result footer while a list is empty or loading', () => {
        renderFrame({ total: 0, shownCount: 0 });

        expect(screen.queryByText('0 to 0 of 0')).toBeNull();
        expect(screen.queryByRole('navigation', { name: 'Courses pages' })).toBeNull();
    });

    it('keeps recovery pagination for a stale empty page', () => {
        renderFrame({ page: 2, total: 0, shownCount: 0 });

        expect(screen.getByText('0 total')).toHaveAttribute('aria-live', 'polite');
        expect(screen.getByRole('navigation', { name: 'Courses pages' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Page 1' })).toBeEnabled();
    });

    it('omits the toolbar when the list has no controls', () => {
        renderFrame({ search: undefined, chips: undefined, action: undefined });

        expect(screen.queryByRole('region', { name: 'Courses search and filters' })).toBeNull();
        expect(screen.getByText('Course rows')).toBeInTheDocument();
    });
});
