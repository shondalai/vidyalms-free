import { afterEach, describe, expect, it } from 'vitest';
import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Organisation } from '@vidyalms/api-sdk';
import { jsonResponse, makeHarness, problemResponse } from '../testing/harness';
import { useOrganisation, useUpdateOrganisation } from './api';
import { useAssignSeat, useGrantOrganisationLearning } from './learning-api';
import { useSaveOrganisationUnit } from './unit-api';
import { OrganisationsScreen } from './screen';

afterEach(() => {
    window.history.replaceState(null, '', '/');
});

const org: Organisation = {
    id: 7,
    name: 'Acme',
    slug: 'acme',
    type: 'organisation',
    state: 'active',
    revision: 4,
    parent_id: null,
    root_id: 7,
    depth: 0,
    contact_email: null,
    timezone: null,
    locale: null,
    created_by: 1,
    archived_at: null,
    created_at: '2026-09-05 10:00:00',
    updated_at: '2026-09-05 10:00:00',
};

/** Delay actual response consumption, not invalidateQueries itself, to exercise the shipped SDK and QueryClient. */
function delayedJson() {
    let finish: (value: unknown) => void = () => undefined;
    const response = new Response(
        new ReadableStream({
            start(controller) {
                finish = (value) => {
                    controller.enqueue(new TextEncoder().encode(JSON.stringify(value)));
                    controller.close();
                };
            },
        }),
        { headers: { 'Content-Type': 'application/json' } },
    );
    return { response, finish: (value: unknown) => finish(value) };
}

const cases = [
    {
        label: 'organization/member/invitation shared writes',
        useAction(revision: number) {
            const mutation = useUpdateOrganisation();
            return {
                ...mutation,
                submit: () =>
                    mutation.mutateAsync({
                        id: 7,
                        body: { expected_revision: revision, name: 'Acme revised' },
                        idempotencyKey: 'org-retry',
                    }),
            };
        },
    },
    {
        label: 'learning grants',
        useAction(revision: number) {
            const mutation = useGrantOrganisationLearning();
            return {
                ...mutation,
                submit: () =>
                    mutation.mutateAsync({
                        id: 7,
                        body: { expected_revision: revision, user_id: 20, course_id: 12 },
                        idempotencyKey: 'grant-retry',
                    }),
            };
        },
    },
    {
        label: 'seat assignment/accounting',
        useAction(revision: number) {
            const mutation = useAssignSeat();
            return {
                ...mutation,
                submit: () =>
                    mutation.mutateAsync({
                        id: 7,
                        poolId: 9,
                        userId: 20,
                        revision,
                        idempotencyKey: 'seat-retry',
                    }),
            };
        },
    },
    {
        label: 'organization units',
        useAction(revision: number) {
            const mutation = useSaveOrganisationUnit();
            return {
                ...mutation,
                submit: () =>
                    mutation.mutateAsync({
                        id: 7,
                        unitId: null,
                        body: {
                            expected_revision: revision,
                            name: 'Engineering',
                            parent_id: null,
                            state: 'active',
                        },
                        idempotencyKey: 'unit-retry',
                    }),
            };
        },
    },
];

describe('organization mutations await active aggregate refresh', () => {
    it('keeps the next guarded settings action disabled until its own save refreshes the aggregate', async () => {
        window.history.replaceState(null, '', '/#/organisation/7/settings');
        const delayed = delayedJson();
        let reads = 0;
        let writes = 0;
        const harness = makeHarness((request) => {
            if (request.method !== 'GET') {
                writes++;
                return jsonResponse({ ...org, name: 'Acme revised', revision: 4 + writes });
            }
            if (request.path.endsWith('/7')) {
                reads++;
                return reads === 2 ? delayed.response : jsonResponse({ ...org, revision: 4 + writes });
            }
            if (request.path.endsWith('/roles'))
                return jsonResponse({
                    items: [],
                    access: { site_manager: true, unit_id: null, actions: ['read', 'settings'] },
                });
            return jsonResponse({ items: [], total: 0, page: 1, per_page: 20, missing_contributors: [] });
        });
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        const name = await screen.findByLabelText('Organization name');
        await user.clear(name);
        await user.type(name, 'Acme revised');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(reads).toBe(2));
        const next = screen.getByRole('button', { name: /Lifecycle & cleanup/ });
        expect(next).toBeDisabled();
        expect(name).toBeDisabled();
        await user.click(next);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await act(async () => {
            delayed.finish({ ...org, name: 'Acme revised', revision: 5 });
        });
        await waitFor(() => expect(next).toBeEnabled());
        await user.click(next);
        await user.click(screen.getByRole('button', { name: 'Suspend organization' }));
        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', { name: 'Suspend organization' }),
        );
        await waitFor(() => expect(writes).toBe(2));
        expect(harness.requests.filter((request) => request.method !== 'GET')[1]?.body).toMatchObject({
            expected_revision: 5,
            state: 'suspended',
        });
    });

    it.each(cases)(
        'keeps $label pending until the next action can capture the new revision',
        async ({ useAction }) => {
            const delayed = delayedJson();
            let reads = 0;
            const harness = makeHarness((request) => {
                if (request.method !== 'GET')
                    return jsonResponse({ ...org, revision: 5, organisation_revision: 5 });
                reads++;
                return reads === 2 ? delayed.response : jsonResponse({ ...org, revision: reads > 2 ? 5 : 4 });
            });
            const { result } = renderHook(
                () => {
                    const organisation = useOrganisation(7);
                    return { organisation, mutation: useAction(organisation.data?.revision ?? 0) };
                },
                { wrapper: harness.wrapper },
            );
            await waitFor(() => expect(result.current.organisation.data?.revision).toBe(4));
            let settled = false;
            act(() => {
                void result.current.mutation.submit().then(() => {
                    settled = true;
                });
            });
            await waitFor(() => expect(reads).toBe(2));
            expect(result.current.mutation.isPending).toBe(true);
            expect(settled).toBe(false);
            expect(result.current.organisation.data?.revision).toBe(4);
            await act(async () => {
                delayed.finish({ ...org, revision: 5 });
            });
            await waitFor(() => expect(result.current.mutation.isSuccess).toBe(true));
            expect(settled).toBe(true);
            expect(result.current.organisation.data?.revision).toBe(5);
            await act(async () => {
                await result.current.mutation.submit();
            });
            const writes = harness.requests.filter((request) => request.method !== 'GET');
            expect(writes[0]?.body).toMatchObject({ expected_revision: 4 });
            expect(writes[1]?.body).toMatchObject({ expected_revision: 5 });
        },
    );

    it('does not invalidate or rebase a failed write before its explicit same-key retry', async () => {
        let reads = 0;
        let writes = 0;
        const harness = makeHarness((request) => {
            if (request.method !== 'GET')
                return ++writes === 1
                    ? problemResponse(503, 'Ambiguous save')
                    : jsonResponse({ ...org, revision: 5 });
            reads++;
            return jsonResponse({ ...org, revision: reads > 1 ? 5 : 4 });
        });
        const { result } = renderHook(
            () => ({ organisation: useOrganisation(7), mutation: useUpdateOrganisation() }),
            { wrapper: harness.wrapper },
        );
        await waitFor(() => expect(result.current.organisation.data?.revision).toBe(4));
        const input = {
            id: 7,
            body: { expected_revision: 4, name: 'Acme revised' },
            idempotencyKey: 'captured-before-first-attempt',
        };
        await act(async () => {
            await expect(result.current.mutation.mutateAsync(input)).rejects.toThrow();
        });
        expect(reads).toBe(1);
        expect(result.current.organisation.data?.revision).toBe(4);
        await act(async () => {
            await result.current.mutation.mutateAsync(input);
        });
        const requests = harness.requests.filter((request) => request.method !== 'GET');
        expect(requests[1]?.body).toEqual(requests[0]?.body);
        expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
        expect(result.current.organisation.data?.revision).toBe(5);
    });
});
