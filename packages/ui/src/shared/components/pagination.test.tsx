import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Pagination, pageWindow } from './pagination';

function renderPagination(props: Partial<Parameters<typeof Pagination>[0]> = {}) {
    const onPageChange = vi.fn();
    render(
        <Pagination page={1} perPage={20} total={100} onPageChange={onPageChange} label="Courses pages" {...props} />,
    );
    return { onPageChange };
}

describe('pageWindow', () => {
    it('lists every page while they fit', () => {
        expect(pageWindow(1, 3)).toEqual([1, 2, 3]);
    });

    it('keeps first, last and the current neighbours, with one gap per jump', () => {
        expect(pageWindow(6, 12)).toEqual([1, '…', 5, 6, 7, '…', 12]);
    });

    it('never repeats a page at the edges', () => {
        expect(pageWindow(1, 12)).toEqual([1, 2, '…', 12]);
        expect(pageWindow(12, 12)).toEqual([1, '…', 11, 12]);
    });
});

describe('Pagination', () => {
    it('is a navigation landmark named by its label', () => {
        renderPagination();
        expect(screen.getByRole('navigation', { name: 'Courses pages' })).toBeInTheDocument();
    });

    it('renders nothing when everything fits on one page', () => {
        const { container } = render(
            <Pagination page={1} perPage={20} total={17} onPageChange={vi.fn()} label="Courses pages" />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('offers a route back when a bookmarked page is beyond the final page', async () => {
        const { onPageChange } = renderPagination({ page: 2, total: 17 });

        expect(screen.getByRole('navigation', { name: 'Courses pages' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Page 1' }));
        expect(onPageChange).toHaveBeenCalledWith(1);
    });

    it('marks the current page and disables it', () => {
        renderPagination({ page: 3 });
        const current = screen.getByRole('button', { name: 'Page 3' });
        expect(current).toHaveAttribute('aria-current', 'page');
        expect(current).toBeDisabled();
    });

    it('moves a page at a time from the step buttons', async () => {
        const { onPageChange } = renderPagination({ page: 3 });
        await userEvent.click(screen.getByRole('button', { name: 'Next ›' }));
        expect(onPageChange).toHaveBeenCalledWith(4);
        await userEvent.click(screen.getByRole('button', { name: '‹ Prev' }));
        expect(onPageChange).toHaveBeenCalledWith(2);
    });

    it('jumps to a numbered page', async () => {
        const { onPageChange } = renderPagination({ page: 1 });
        await userEvent.click(screen.getByRole('button', { name: 'Page 5' }));
        expect(onPageChange).toHaveBeenCalledWith(5);
    });

    it('disables Prev on the first page and Next on the last', () => {
        const { unmount } = render(
            <Pagination page={1} perPage={20} total={100} onPageChange={vi.fn()} label="First" />,
        );
        expect(screen.getByRole('button', { name: '‹ Prev' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Next ›' })).toBeEnabled();
        unmount();

        render(<Pagination page={5} perPage={20} total={100} onPageChange={vi.fn()} label="Last" />);
        expect(screen.getByRole('button', { name: '‹ Prev' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Next ›' })).toBeDisabled();
    });
});
