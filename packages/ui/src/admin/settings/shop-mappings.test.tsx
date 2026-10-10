import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ShopMappings } from './shop-mappings';
import { makeHarness, jsonResponse } from '../testing/harness';

const product = { id: 10, title: 'Practical baking bundle', sku: 'BAKE', type: 'variable' };
const courses = [
    { id: 7, title: 'Baking essentials', price_mode: 'paid', state: 'published' },
    { id: 8, title: 'Finishing techniques', price_mode: 'paid', state: 'draft' },
];
const mapping = { product_id: 10, variation_id: 0, courses: [7], product, variation: null, course_details: [courses[0]] };

function setup(options: { existing?: boolean; conflict?: boolean; empty?: boolean } = {}) {
    let stored = { revision: 3, mappings: options.existing ? [mapping] : [] };
    const writes: Record<string, unknown>[] = [];
    const reads: URL[] = [];
    const fetcher = vi.fn(async (input: string, init: RequestInit) => {
        expect(init.credentials).toBe('same-origin');
        expect(init.headers).toMatchObject({ 'X-CSRF-Token': 'session-token' });
        const url = new URL(input);
        if (init.method === 'POST') {
            const body = JSON.parse(String(init.body)) as Record<string, unknown>;
            writes.push(body);
            if (options.conflict) return new Response(JSON.stringify({ detail: 'Stale revision' }), { status: 409 });
            const selected = body.courses as number[];
            stored = {
                revision: stored.revision + 1,
                mappings: body.action === 'remove' ? [] : [{ ...mapping, variation_id: body.variation_id as number,
                    courses: selected, course_details: courses.filter((course) => selected.includes(course.id)) }],
            };
            return jsonResponse(stored);
        }
        reads.push(url);
        const action = url.searchParams.get('action');
        if (action === 'products') return jsonResponse({ items: [product], more: false });
        if (action === 'courses') return jsonResponse({ items: options.empty ? [] : courses, more: false });
        if (action === 'variations') return jsonResponse({ items: [{ id: 11, product_id: 10, sku: 'BAKE-SMALL' }], more: false });
        return jsonResponse(stored);
    });
    vi.stubGlobal('fetch', fetcher);
    const harness = makeHarness(() => null);
    render(<ShopMappings endpoint="http://localhost/administrator/index.php?option=com_vidyalms&task=shop.mappings" headers={{ 'X-CSRF-Token': 'session-token' }} />, { wrapper: harness.wrapper });
    return { writes, reads };
}

afterEach(() => vi.unstubAllGlobals());

describe('shop mappings', () => {
    it('connects a product variation to several named courses and persists the selected IDs', async () => {
        const { writes, reads } = setup();
        await screen.findByText('Connect your first product');
        await userEvent.click(screen.getByRole('button', { name: 'Add mapping' }));
        const dialog = within(screen.getByRole('dialog'));
        expect(dialog.getByRole('button', { name: 'Save mapping' })).toBeDisabled();
        await userEvent.click(await dialog.findByRole('button', { name: /Practical baking bundle/ }));
        await userEvent.click(dialog.getByRole('checkbox', { name: 'Apply to one variation only' }));
        await userEvent.click(await dialog.findByRole('button', { name: /BAKE-SMALL/ }));
        await userEvent.click(dialog.getByRole('checkbox', { name: 'Baking essentials' }));
        await userEvent.click(dialog.getByRole('checkbox', { name: 'Finishing techniques' }));
        await userEvent.click(dialog.getByRole('button', { name: 'Save mapping' }));
        await screen.findByText('Product mappings saved.');
        expect(screen.getByText('Variation 11')).toBeInTheDocument();
        expect(writes).toEqual([{ action: 'create', expected_revision: 3, product_id: 10, variation_id: 11, courses: [7, 8] }]);
        expect(reads.some((url) => url.searchParams.get('action') === 'variations' && url.searchParams.get('product_id') === '10')).toBe(true);
    });

    it('edits courses then removes a connection only after confirmation', async () => {
        const { writes } = setup({ existing: true });
        await userEvent.click(await screen.findByRole('button', { name: 'Edit mapping for Practical baking bundle' }));
        const dialog = within(screen.getByRole('dialog'));
        await userEvent.click(await dialog.findByRole('checkbox', { name: 'Finishing techniques' }));
        await userEvent.click(dialog.getByRole('button', { name: 'Save mapping' }));
        await screen.findByText('Product mappings saved.');
        expect(writes[0]).toMatchObject({ action: 'update', expected_revision: 3, courses: [7, 8] });
        await userEvent.click(screen.getByRole('button', { name: /Remove mapping for/ }));
        expect(writes).toHaveLength(1);
        expect(screen.getByText(/Existing purchase access and learning progress are preserved/)).toBeInTheDocument();
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remove mapping' }));
        await screen.findByText('Connect your first product');
        expect(writes[1]).toMatchObject({ action: 'remove', expected_revision: 4, product_id: 10, courses: [] });
    });

    it('keeps course selections when another administrator has changed the mapping', async () => {
        setup({ existing: true, conflict: true });
        await userEvent.click(await screen.findByRole('button', { name: /Edit mapping for/ }));
        const dialog = within(screen.getByRole('dialog'));
        await userEvent.click(await dialog.findByRole('checkbox', { name: 'Finishing techniques' }));
        await userEvent.click(dialog.getByRole('button', { name: 'Save mapping' }));
        expect(await dialog.findByRole('alert')).toHaveTextContent('Mappings have changed');
        expect(dialog.getByRole('checkbox', { name: 'Finishing techniques' })).toBeChecked();
    });

    it('explains how to make a paid course available and debounces product search', async () => {
        const { writes, reads } = setup({ empty: true });
        await screen.findByText('Connect your first product');
        await userEvent.click(screen.getByRole('button', { name: 'Add mapping' }));
        const dialog = within(screen.getByRole('dialog'));
        expect(await dialog.findByText(/Set a course to Paid under Access/)).toBeInTheDocument();
        await userEvent.type(dialog.getByRole('searchbox', { name: 'Search products' }), 'baking');
        await waitFor(() => expect(reads.some((url) => url.searchParams.get('search') === 'baking')).toBe(true));
        expect(dialog.getByRole('button', { name: 'Save mapping' })).toBeDisabled();
        expect(writes).toHaveLength(0);
    });
});
