import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { jsonResponse, makeHarness, type RecordedRequest } from '@/admin/testing/harness';
import { CourseCategoryPicker } from './course-category-picker';

const leadership = {
    id: 17,
    name: 'Leadership',
    parent_id: 3,
    label: 'Professional development / Leadership',
    state: 'published' as const,
    edit_url: '/categories/17/edit',
};

function categories(request: RecordedRequest): Response | null {
    if (request.method === 'GET' && request.path.startsWith('/v1/manage/course-categories?')) {
        return jsonResponse({
            items: [
                leadership,
                {
                    id: 18,
                    name: 'Retired',
                    parent_id: null,
                    label: 'Retired',
                    state: 'trashed',
                    edit_url: null,
                },
            ],
            total: 2,
            page: 1,
            per_page: 20,
            management_url: '/categories',
        });
    }
    if (request.method === 'GET' && request.path === '/v1/manage/course-categories/17') {
        return jsonResponse(leadership);
    }
    return null;
}

describe('CourseCategoryPicker', () => {
    it('resolves the stored id outside a list page and exposes native category links', async () => {
        const harness = makeHarness(categories);
        render(
            <CourseCategoryPicker label="Course category" value={17} onChange={() => undefined} />,
            { wrapper: harness.wrapper },
        );

        expect(await screen.findByText(leadership.label)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Edit category/ })).toHaveAttribute(
            'href',
            '/categories/17/edit',
        );
        expect(screen.getByRole('link', { name: /Edit category/ })).toHaveAttribute('target', '_blank');
        expect(screen.getByRole('link', { name: /Edit category/ })).toHaveAttribute('rel', 'noopener noreferrer');
        expect(screen.getByRole('link', { name: /Manage categories/ })).toHaveAttribute('href', '/categories');
        expect(screen.getByRole('link', { name: /Manage categories/ })).toHaveAccessibleName(
            'Manage categories (opens in a new tab)',
        );
        expect(screen.getByText(leadership.label)).not.toHaveClass('truncate');
        expect(screen.getByText(leadership.label)).toHaveAttribute('title', leadership.label);
    });

    it('makes a removed stored category explicit and recoverable', async () => {
        const harness = makeHarness((request) => {
            if (request.path.startsWith('/v1/manage/course-categories?')) {
                return categories(request);
            }
            return null;
        });
        const onChange = vi.fn();
        render(<CourseCategoryPicker label="Course category" value={99} onChange={onChange} />, {
            wrapper: harness.wrapper,
        });

        expect(await screen.findByRole('alert')).toHaveTextContent('Category 99 is no longer available');
        await userEvent.click(screen.getByRole('button', { name: 'Clear' }));
        expect(onChange).toHaveBeenCalledWith(null);
    });

    it('searches one bounded page, disables trashed choices and returns the selected id', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const harness = makeHarness(categories);
        render(<CourseCategoryPicker label="Course category" value={null} onChange={onChange} />, {
            wrapper: harness.wrapper,
        });

        await user.click(screen.getByRole('button', { name: 'Choose category' }));
        await user.type(screen.getByRole('searchbox', { name: 'Course category' }), 'lead');
        await waitFor(() => {
            const request = harness.requests.find(
                (candidate) => candidate.path.includes('q=lead') && candidate.path.includes('per_page=20'),
            );
            expect(request).toBeDefined();
        });
        expect(screen.getByRole('button', { name: /Retired Trashed/ })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: leadership.label }));
        expect(onChange).toHaveBeenCalledWith(17);
        expect(screen.getByRole('button', { name: 'Choose category' })).toHaveFocus();
    });

    it('requests later pages so a category beyond the first twenty remains reachable', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const late = {
            ...leadership,
            id: 41,
            name: 'Zoology',
            label: 'Academics / Zoology',
        };
        const harness = makeHarness((request) => {
            if (request.method !== 'GET' || !request.path.startsWith('/v1/manage/course-categories?')) {
                return null;
            }

            const url = new URL(request.path, 'http://localhost');
            const page = Number(url.searchParams.get('page'));
            return jsonResponse({
                items: page === 2
                    ? [late]
                    : [{ ...leadership, id: 40, name: 'Removed', label: 'Removed', state: 'trashed' }],
                total: 21,
                page,
                per_page: 20,
                management_url: null,
            });
        });

        render(<CourseCategoryPicker label="Course category" value={null} onChange={onChange} />, {
            wrapper: harness.wrapper,
        });

        await user.click(screen.getByRole('button', { name: 'Choose category' }));
        expect(await screen.findByRole('button', { name: /Removed Trashed/ })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Page 2' }));
        await user.click(await screen.findByRole('button', { name: late.label }));

        expect(onChange).toHaveBeenCalledWith(late.id);
        expect(harness.requests.some((request) => request.path.includes('page=2'))).toBe(true);
    });

    it('makes stale rows non-selectable while a debounced search is changing', async () => {
        const user = userEvent.setup();
        const current = { ...leadership, id: 51, name: 'Current', label: 'Current' };
        const replacement = { ...leadership, id: 52, name: 'Replacement', label: 'Replacement' };
        const harness = makeHarness((request) => {
            if (request.method !== 'GET' || !request.path.startsWith('/v1/manage/course-categories?')) {
                return null;
            }

            const url = new URL(request.path, 'http://localhost');
            const searching = url.searchParams.get('q') === 'replace';
            return jsonResponse({
                items: [searching ? replacement : current],
                total: 1,
                page: 1,
                per_page: 20,
                management_url: null,
            });
        });

        render(<CourseCategoryPicker label="Course category" value={null} onChange={() => undefined} />, {
            wrapper: harness.wrapper,
        });

        await user.click(screen.getByRole('button', { name: 'Choose category' }));
        const search = screen.getByRole('searchbox', { name: 'Course category' });
        expect(search).toHaveFocus();
        expect(await screen.findByRole('button', { name: current.label })).toBeEnabled();

        await user.type(search, 'replace');
        expect(screen.getByRole('button', { name: current.label })).toBeDisabled();
        expect(await screen.findByRole('button', { name: replacement.label })).toBeEnabled();
    });

    it('restores focus after closing and withholds an empty native manager URL', async () => {
        const user = userEvent.setup();
        const harness = makeHarness((request) => {
            if (request.method === 'GET' && request.path.startsWith('/v1/manage/course-categories?')) {
                return jsonResponse({ items: [], total: 0, page: 1, per_page: 20, management_url: '' });
            }
            return null;
        });

        const { rerender } = render(
            <CourseCategoryPicker label="Course category" value={null} onChange={() => undefined} />,
            { wrapper: harness.wrapper },
        );

        const trigger = screen.getByRole('button', { name: 'Choose category' });
        expect(screen.getByRole('group', { name: 'Course category' })).toBeInTheDocument();
        await user.click(trigger);
        expect(screen.getByRole('searchbox', { name: 'Course category' })).toHaveFocus();
        await user.click(screen.getByRole('button', { name: 'Close' }));
        expect(screen.getByRole('button', { name: 'Choose category' })).toHaveFocus();
        expect(screen.queryByRole('link', { name: /Manage categories/ })).not.toBeInTheDocument();

        rerender(<CourseCategoryPicker label="Course category" value={null} onChange={() => undefined} disabled />);
        expect(screen.getByRole('button', { name: 'Choose category' })).toBeDisabled();
    });

    it('removes native links from the tab order while its course form is pending', async () => {
        const harness = makeHarness(categories);
        render(
            <CourseCategoryPicker label="Course category" value={17} onChange={() => undefined} disabled />,
            { wrapper: harness.wrapper },
        );

        const edit = await screen.findByRole('link', { name: /Edit category/ });
        const manage = screen.getByRole('link', { name: /Manage categories/ });
        expect(edit).toHaveAttribute('aria-disabled', 'true');
        expect(edit).toHaveAttribute('tabindex', '-1');
        expect(manage).toHaveAttribute('aria-disabled', 'true');
        expect(manage).toHaveAttribute('tabindex', '-1');
    });
});
