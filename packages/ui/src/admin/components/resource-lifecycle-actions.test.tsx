import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ResourceLifecycleActions } from './resource-lifecycle-actions';
import { jsonResponse, makeHarness, problemResponse } from '../testing/harness';

describe('course and bank lifecycle actions', () => {
    it('only deletes a course after confirmation, and keeps a replay key when retrying', async () => {
        let fail = true;
        const onChanged = vi.fn();
        const harness = makeHarness((request) => request.method === 'DELETE'
            ? fail ? problemResponse(409, 'Please try again') : new Response(null, { status: 204 }) : null);
        render(<ResourceLifecycleActions kind="course" id={7} name="Leadership" state="published" onChanged={onChanged} />, { wrapper: harness.wrapper });
        await userEvent.click(screen.getByRole('button', { name: 'Delete Leadership' }));
        expect(harness.requests).toHaveLength(0);
        await userEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Cancel' }));
        expect(harness.requests).toHaveLength(0);
        await userEvent.click(screen.getByRole('button', { name: 'Delete Leadership' }));
        await userEvent.click(screen.getByRole('button', { name: 'Move to Trash' }));
        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(onChanged).not.toHaveBeenCalled();
        fail = false;
        await userEvent.click(screen.getByRole('button', { name: 'Move to Trash' }));
        await waitFor(() => expect(onChanged).toHaveBeenCalledWith('Leadership moved to Trash.'));
        expect(harness.requests).toHaveLength(2);
        expect(harness.requests[0]?.path).toBe('/v1/manage/courses/7');
        expect(harness.requests[0]?.headers['idempotency-key']).toBe(harness.requests[1]?.headers['idempotency-key']);
        expect(harness.requests[0]?.headers['x-csrf-token']).toBe('test');
    });

    it('unpublishes banks through their state endpoint', async () => {
        const harness = makeHarness(() => jsonResponse({ id: 11, state: 'archived' }));
        render(<ResourceLifecycleActions kind="bank" id={11} name="Physics" state="active" onChanged={vi.fn()} />, { wrapper: harness.wrapper });
        await userEvent.click(screen.getByRole('button', { name: 'Unpublish Physics' }));
        await userEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Unpublish' }));
        await waitFor(() => expect(harness.requests).toHaveLength(1));
        expect(harness.requests[0]).toMatchObject({ method: 'PATCH', path: '/v1/manage/banks/11', body: { state: 'archived' } });
    });

    it('offers restore in Trash and restores banks as unpublished', async () => {
        const harness = makeHarness(() => jsonResponse({ id: 11, state: 'archived' }));
        render(<ResourceLifecycleActions kind="bank" id={11} name="Physics" state="trashed" onChanged={vi.fn()} />, { wrapper: harness.wrapper });
        expect(screen.queryByRole('button', { name: 'Delete Physics' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Publish Physics' })).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Restore Physics' }));
        await userEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Restore' }));
        await waitFor(() => expect(harness.requests).toHaveLength(1));
        expect(harness.requests[0]?.body).toEqual({ state: 'archived' });
    });
});
