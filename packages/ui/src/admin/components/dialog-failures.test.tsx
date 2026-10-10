import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ExtendDialog } from './extend-dialog';
import { RevokeDialog } from './revoke-dialog';
import { GrantAccessDialog } from './grant-access-dialog';
import { NewCourseDialog } from './new-course-dialog';
import { jsonResponse, makeCourse, makeHarness, problemResponse, type RecordedRequest } from '../testing/harness';

/*
 * Every write in the admin app is awaited inside a submit handler and the
 * handler is called with void. A refused write therefore has to be caught
 * where it is awaited, or it leaves the page with an unhandled rejection:
 * the banner still renders from the mutation's own error state, so nothing
 * looks broken, while wp-admin's or Atum's onunhandledrejection reporting
 * collects an error nobody threw at them.
 *
 * These four cases assert that the rejection is handled and the banner is
 * still what the reader gets.
 */

const rejections: unknown[] = [];

function collect(reason: unknown): void {
    rejections.push(reason);
}

/* Node's process is where a rejection nobody caught surfaces under happy-dom. */
beforeEach(() => {
    rejections.length = 0;
    process.on('unhandledRejection', collect);
});

afterEach(() => {
    process.off('unhandledRejection', collect);
});

/** Lets the microtask queue drain so a rejection nobody caught surfaces. */
async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function refuse(paths: string[]) {
    return (request: RecordedRequest): Response | null => {
        if (paths.some((path) => request.path.startsWith(path))) {
            return problemResponse(403, 'Forbidden', 'Not an instructor.');
        }
        if (request.path.startsWith('/v1/manage/courses?')) {
            return jsonResponse({
                items: [makeCourse({ id: 1, title: 'Foundations of Web Accessibility' })],
                total: 1,
                page: 1,
                per_page: 100,
            });
        }
        if (request.path.startsWith('/v1/admin/users')) {
            return jsonResponse({ items: [{ id: 483, name: 'Mira Sato', email: 'mira@example.test' }] });
        }
        return null;
    };
}

describe('a refused admin write', () => {
    it('leaves no unhandled rejection when an extension is refused', async () => {
        const harness = makeHarness(refuse(['/v1/manage/entitlements/901/extend']));
        render(
            <ExtendDialog
                open
                onOpenChange={() => undefined}
                entitlementId={901}
                learnerName="Jonas Berg"
                courseTitle="Foundations of Web Accessibility"
                currentExpiry="2026-10-01 23:59:59"
                locale="en-GB"
                onDone={() => undefined}
            />,
            { wrapper: harness.wrapper },
        );

        await userEvent.click(await screen.findByRole('button', { name: 'Save expiry' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Not an instructor.');
        await settle();
        expect(rejections).toEqual([]);
    });

    it('leaves no unhandled rejection when a revocation is refused', async () => {
        const harness = makeHarness(refuse(['/v1/admin/certificates/55/revoke']));
        render(
            <RevokeDialog
                open
                onOpenChange={() => undefined}
                certificateId={55}
                code="VY-2026-ABCD"
                onDone={() => undefined}
            />,
            { wrapper: harness.wrapper },
        );

        await userEvent.click(await screen.findByRole('button', { name: 'Revoke certificate' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Not an instructor.');
        await settle();
        expect(rejections).toEqual([]);
    });

    it('leaves no unhandled rejection when a grant is refused', async () => {
        const harness = makeHarness(refuse(['/v1/manage/courses/1/learners']));
        render(
            <GrantAccessDialog open onOpenChange={() => undefined} onGranted={() => undefined} />,
            { wrapper: harness.wrapper },
        );

        const dialog = await screen.findByRole('dialog', { name: 'Grant access' });
        await userEvent.type(within(dialog).getByRole('searchbox', { name: 'Learner' }), 'Mira');
        await userEvent.click(await within(dialog).findByRole('button', { name: /Mira Sato/ }));
        await userEvent.click(await within(dialog).findByRole('button', { name: 'Foundations of Web Accessibility' }));
        await userEvent.click(within(dialog).getByRole('button', { name: 'Grant access' }));

        await waitFor(() => expect(within(dialog).getByRole('alert')).toHaveTextContent('Not an instructor.'));
        await settle();
        expect(rejections).toEqual([]);
    });

    it('leaves no unhandled rejection when a new course is refused', async () => {
        const harness = makeHarness(refuse(['/v1/manage/courses']));
        render(
            <NewCourseDialog open onOpenChange={() => undefined} onCreated={() => undefined} />,
            { wrapper: harness.wrapper },
        );

        const dialog = await screen.findByRole('dialog');
        await userEvent.type(within(dialog).getByLabelText('Title'), 'Roasting');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Create draft' }));

        await waitFor(() => expect(within(dialog).getByRole('alert')).toHaveTextContent('Not an instructor.'));
        await settle();
        expect(rejections).toEqual([]);
    });
});
