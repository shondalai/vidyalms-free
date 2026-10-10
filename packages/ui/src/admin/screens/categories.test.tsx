import { afterEach, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { makeHarness, jsonResponse } from '../testing/harness';
import { CategoriesScreen } from './categories';

afterEach(() => window.history.replaceState(null, '', '/'));
it('uses bounded server search and shows native editing only for same-site destinations', async () => {
    const harness = makeHarness(() =>
        jsonResponse({
            items: [
                {
                    id: 9,
                    name: 'Design',
                    label: 'Business / Design',
                    parent_id: 2,
                    state: 'published',
                    edit_url: '/cms/category/9',
                },
                {
                    id: 10,
                    name: 'Unsafe link',
                    label: 'Unsafe link',
                    parent_id: null,
                    state: 'published',
                    edit_url: 'javascript:alert(1)',
                },
            ],
            total: 2400,
            page: 1,
            per_page: 20,
            management_url: '/cms/categories',
        }),
    );
    render(<CategoriesScreen />, { wrapper: harness.wrapper });
    expect(await screen.findByRole('link', { name: 'Edit category' })).toHaveAttribute(
        'href',
        `${window.location.origin}/cms/category/9`,
    );
    expect(screen.getByText('Business / Design')).toBeInTheDocument();
    expect(screen.getByText('View only')).toBeInTheDocument();
    await userEvent.type(screen.getByRole('searchbox', { name: 'Search categories' }), 'Design');
    await waitFor(() =>
        expect(
            harness.requests.some(
                (request) => request.path.includes('q=Design') && request.path.includes('per_page=20'),
            ),
        ).toBe(true),
    );
    expect(window.location.hash).toContain('search=Design');
    expect(document.querySelector('a[href^="javascript:"]')).toBeNull();
});
