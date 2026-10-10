import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CredentialsApp } from './app';
import { jsonResponse, makeConfig, makeHarness, problemResponse, type RecordedRequest } from './testing/harness';

/*
 * The learner's right to know, driven through the same mount the host renders.
 *
 * The assertions that matter are the negative ones. No draft payload appears on
 * this page under any state, because a draft is not reachable by a learner read
 * path anywhere in the contract, and this is the one page somebody might expect
 * it to be. Nothing here shows a score, a confidence or a risk value, because
 * no such field exists in the design and a screen that invented one would be
 * the first place it appeared. And a run with no recorded manifest says so
 * rather than rendering an empty list, because those are different answers.
 */

function entry(overrides: Record<string, unknown> = {}) {
    return {
        run_id: 9,
        occurred_at: '2026-09-08 09:00:00',
        task_key: 'feedback.draft_on_submission',
        task_label: 'Feedback draft',
        placement_key: 'gradebook_feedback',
        model: null,
        bridge_key: 'null',
        invoked_by: 10,
        invoked_by_name: 'Tara Teacher',
        actor_kind: 'person',
        my_role: 'subject',
        fields_sent: ['submission:412', 'assignment:88'],
        state: 'succeeded',
        outcome: 'pending',
        was_preview: false,
        retained_until: '2027-10-13 00:00:00',
        ...overrides,
    };
}

function renderPage(world: { items?: unknown[]; status?: number } = {}) {
    const items = world.items ?? [entry()];
    const harness = makeHarness(
        (request: RecordedRequest) => {
            const path = new URL(request.path, 'http://localhost').pathname.replace('/v1', '');
            if (request.method === 'GET' && path === '/me/ai-activity') {
                if (world.status !== undefined) {
                    return problemResponse(world.status, 'Refused');
                }
                return jsonResponse({
                    items,
                    total: items.length,
                    page: 1,
                    per_page: 10,
                    as_of: '2026-09-08 12:00:00',
                });
            }
            return null;
        },
        makeConfig({ screen: 'ai-activity', user: { id: 50, name: 'Lena Learner' } }),
    );

    return { ...render(<CredentialsApp />, { wrapper: harness.wrapper }), harness };
}

describe('the learner AI activity view', () => {
    it('lists every run that used this person, with the spans that were sent', async () => {
        renderPage();

        expect(await screen.findByRole('heading', { name: 'AI activity on my data' })).toBeInTheDocument();
        expect(screen.getByText('Feedback draft')).toBeInTheDocument();
        expect(screen.getByText('Your data was sent')).toBeInTheDocument();
        expect(screen.getByText('Tara Teacher')).toBeInTheDocument();
        expect(screen.getByText('submission:412')).toBeInTheDocument();
        expect(screen.getByText('assignment:88')).toBeInTheDocument();
        expect(screen.getByText('Kept until 2027-10-13 00:00:00.')).toBeInTheDocument();
    });

    it('says what was done with the output, which the run state does not answer', async () => {
        // A run that finished and whose every draft a reviewer threw away read
        // as "succeeded" and said nothing about the throwing away, which is
        // the last element of what this page is supposed to tell a person.
        await renderPage({ items: [entry({ outcome: 'discarded' })] });

        expect(
            await screen.findByText('A person read it and discarded it. Nothing was written.'),
        ).toBeTruthy();
    });

    it('stamps when the answer was true, because a purge runs daily', async () => {
        renderPage();

        expect(
            await screen.findByText(
                'This answer was true at 2026-09-08 12:00:00. Records are deleted on their own schedule, so it can change.',
            ),
        ).toBeInTheDocument();
    });

    it('says no model was involved rather than naming the null bridge', async () => {
        renderPage();

        expect(
            await screen.findByText('No model was involved. This run was computed on this site.'),
        ).toBeInTheDocument();
    });

    it('marks a preview as a preview, because nothing was sent', async () => {
        renderPage({ items: [entry({ was_preview: true, state: 'previewed' })] });

        expect(await screen.findByText('This was a preview. Nothing was sent to any model.')).toBeInTheDocument();
    });

    it('tells an unrecorded manifest apart from an empty one', async () => {
        renderPage({ items: [entry({ fields_sent: null })] });

        expect(await screen.findByText('This run did not record what was sent.')).toBeInTheDocument();
    });

    it('says nothing left the site when the manifest is empty', async () => {
        renderPage({ items: [entry({ fields_sent: [] })] });

        expect(await screen.findByText('Nothing left the site on this run.')).toBeInTheDocument();
    });

    it('names an automation rather than leaving the starter blank', async () => {
        renderPage({ items: [entry({ actor_kind: 'automation', invoked_by: null, invoked_by_name: null })] });

        expect(await screen.findByText('An automation on this site')).toBeInTheDocument();
    });

    it('keeps a run readable after the package that produced it is removed', async () => {
        renderPage({ items: [entry({ task_label: null })] });

        expect(await screen.findByText('A helper that is no longer installed')).toBeInTheDocument();
    });

    it('says nothing has used this person rather than drawing an empty list', async () => {
        renderPage({ items: [] });

        expect(await screen.findByText('No AI run has used your data.')).toBeInTheDocument();
    });

    it('asks a signed-out reader to sign in rather than showing an error', async () => {
        renderPage({ status: 401 });

        expect(await screen.findByText('Sign in to see your AI activity.')).toBeInTheDocument();
    });

    it('shows a real failure as a failure', async () => {
        renderPage({ status: 500 });

        expect(await screen.findByText('Your AI activity could not load.')).toBeInTheDocument();
    });

    it('never renders a draft payload, a score, a confidence or a risk value', async () => {
        const { container } = renderPage();

        await screen.findByText('Feedback draft');

        const text = container.textContent ?? '';
        for (const forbidden of ['payload', 'confidence', 'risk score', 'Score']) {
            expect(text).not.toContain(forbidden);
        }
    });
});
