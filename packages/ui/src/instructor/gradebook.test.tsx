import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { GradebookTab } from './tabs/gradebook-tab';
import {
    jsonResponse,
    makeAnswerItem,
    makeGradebookPage,
    makeHarness,
    makeSubmissionItem,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * Artboard 3: the queue for doing the work and the table for seeing the
 * whole. The grade button states its consequence, and what it announces
 * afterwards is what the server said the grade did, not what the screen
 * assumed.
 */

function handler(page = makeGradebookPage(), overrides: Record<string, Response | (() => Response)> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        if (key === 'GET /v1/manage/courses/1/gradebook') {
            return jsonResponse(page);
        }
        return null;
    };
}

function renderTab(route: (request: RecordedRequest) => Response | null = handler()) {
    const harness = makeHarness(route);
    render(<GradebookTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
    return harness;
}

const gradedSubmission = {
    id: 31,
    assignment_id: 5,
    enrolment_id: 3,
    lesson_id: 9,
    user_id: 41,
    attempt_number: 1,
    status: 'graded',
    text_response: null,
    files: [],
    submitted_at: '2026-08-28 14:32:00',
    graded_at: '2026-09-02 09:00:00',
    grade: { points_awarded: 85, max_points: 100, feedback: 'Thorough pass.', graded_at: '2026-09-02 09:00:00', grader_id: 7 },
    outcome: { lesson_completed: true, enrolment_completed: false },
};

describe('gradebook queue', () => {
    it('asks for the pending queue oldest first, which is the contract default', async () => {
        const harness = renderTab();
        await screen.findByText(/Accessibility audit exercise/);
        const request = harness.requests[0];
        expect(request?.path).toContain('status=pending');
        expect(request?.path).toContain('sort=waiting_since');
        expect(request?.path).toContain('order=asc');
    });

    it('shows one item with its learner, its source and its place in the queue', async () => {
        renderTab();
        expect(await screen.findByText(/Priya Nair · Accessibility audit exercise/)).toBeInTheDocument();
        expect(screen.getByText(/Assignment · submitted 2026-08-28 14:32 · item 1 of 2/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Download audit-report-priya.zip' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Download audit-report-priya.zip' })).toHaveAttribute(
            'href',
            'https://example.test/signed/audit.zip',
        );
    });

    it('states the consequence on the grade button of an assignment', async () => {
        renderTab();
        expect(await screen.findByRole('button', { name: 'Save assessment' })).toBeInTheDocument();
    });

    it('grades a submission and says what the grade did', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(makeGradebookPage({ items: [makeSubmissionItem()] }), {
                'POST /v1/submissions/31/grade': jsonResponse(gradedSubmission),
            }),
        );
        await screen.findByText(/Priya Nair/);
        await user.type(screen.getByLabelText('Score'), '85');
        await user.type(screen.getByLabelText('Feedback to the learner'), 'Thorough pass.');
        await user.click(screen.getByRole('button', { name: 'Save assessment' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/submissions/31/grade');
            expect(write?.body).toEqual({ points_awarded: 85, feedback: 'Thorough pass.', decision: 'auto' });
        });
        await waitFor(() => {
            expect(document.querySelector("[data-vy-live='polite']")?.textContent).toBe(
                'Graded. The lesson is complete for this learner.',
            );
        });
    });

    it('will not grade without a score, so an empty field never posts a zero', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler(makeGradebookPage({ items: [makeSubmissionItem()] })));
        await screen.findByText(/Priya Nair/);
        const grade = screen.getByRole('button', { name: 'Save assessment' });
        expect(grade).toBeDisabled();
        await user.click(grade);
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('requires every practical criterion and revision feedback, then submits the rubric decision', async () => {
        const user = userEvent.setup();
        const item = makeSubmissionItem();
        item.submission!.assessment = {
            max_points: 100,
            pass_percentage: 70,
            rubric: [
                { id: 'hygiene', label: 'Hygiene', max_points: 30 },
                { id: 'technique', label: 'Technique', max_points: 70 },
            ],
        };
        const harness = renderTab(handler(makeGradebookPage({ items: [item] }), {
            'POST /v1/submissions/31/grade': jsonResponse({ ...gradedSubmission, outcome: { lesson_completed: false, enrolment_completed: false } }),
        }));
        await screen.findByText('Assessment criteria');
        const save = screen.getByRole('button', { name: 'Save assessment' });
        expect(save).toBeDisabled();
        await user.type(screen.getByLabelText('Hygiene (0 to 30)'), '10');
        expect(save).toBeDisabled();
        await user.type(screen.getByLabelText('Technique (0 to 70)'), '30');
        await user.type(screen.getByLabelText('Feedback: Hygiene'), 'Clean the workbench.');
        await user.selectOptions(screen.getByLabelText('Assessment decision'), 'resubmit');
        expect(save).toBeDisabled();
        await user.type(screen.getByLabelText('Feedback to the learner'), 'Please submit improved photos.');
        await user.click(save);
        await waitFor(() => expect(harness.requests.find(request => request.path === '/v1/submissions/31/grade')?.body).toEqual({
            decision: 'resubmit', feedback: 'Please submit improved photos.',
            criteria: [{ id: 'hygiene', points: 10, feedback: 'Clean the workbench.' }, { id: 'technique', points: 30, feedback: null }],
        }));
    });

    it('names how many answers of an attempt still wait when grading one of several', async () => {
        renderTab(
            handler(
                makeGradebookPage({
                    items: [
                        makeAnswerItem(),
                        makeAnswerItem({ key: 'answer:71:103', lesson_title: 'Module 2 check' }),
                    ],
                }),
            ),
        );
        expect(await screen.findByRole('button', { name: 'Grade, 1 answer still waiting' })).toBeInTheDocument();
    });

    it('grades a quiz answer through the attempt route and reports the attempt outcome', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(makeGradebookPage({ items: [makeAnswerItem()] }), {
                'POST /v1/attempts/71/answers/102/grade': jsonResponse({
                    attempt: {
                        id: 71,
                        quiz_id: 21,
                        attempt_number: 1,
                        status: 'graded',
                        started_at: '2026-09-01 07:00:00',
                        submitted_at: '2026-09-01 08:00:00',
                        expires_at: null,
                        score: 8,
                        max_score: 10,
                        percentage: 80,
                        passed: true,
                    },
                    outcome: {
                        lesson_completed: true,
                        enrolment_completed: false,
                        attempt_finalised: true,
                        pending_manual_count: 0,
                    },
                }),
            }),
        );
        await screen.findByText(/Tom Weiss/);
        await user.type(screen.getByLabelText('Score'), '1');
        await user.click(screen.getByRole('button', { name: 'Grade and finish the attempt' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.path === '/v1/attempts/71/answers/102/grade',
            );
            expect(write?.body).toEqual({ points_awarded: 1, feedback: null });
        });
        await waitFor(() => {
            expect(document.querySelector("[data-vy-live='polite']")?.textContent).toContain(
                'The attempt is finished',
            );
        });
    });

    it('shows the learner answer of a quiz item beside its prompt', async () => {
        renderTab(handler(makeGradebookPage({ items: [makeAnswerItem()] })));
        expect(await screen.findByText('Why should focus return to the trigger?')).toBeInTheDocument();
        expect(screen.getByText('So the keyboard does not lose its place.')).toBeInTheDocument();
    });

    it('moves through the queue with the arrow keys', async () => {
        const user = userEvent.setup();
        renderTab();
        await screen.findByText(/Priya Nair/);
        await user.keyboard('{ArrowDown}');
        expect(await screen.findByText(/Tom Weiss/)).toBeInTheDocument();
        await user.keyboard('{ArrowUp}');
        expect(await screen.findByText(/Priya Nair/)).toBeInTheDocument();
    });

    it('leaves the arrow keys alone while the grader is typing', async () => {
        const user = userEvent.setup();
        renderTab();
        await screen.findByText(/Priya Nair/);
        await user.click(screen.getByLabelText('Feedback to the learner'));
        await user.keyboard('{ArrowDown}');
        expect(screen.getByText(/Priya Nair/)).toBeInTheDocument();
    });

    it('puts the cursor on the score when G is pressed with nothing to grade with', async () => {
        const user = userEvent.setup();
        renderTab();
        await screen.findByText(/Priya Nair/);
        await user.keyboard('g');
        expect(screen.getByLabelText('Score')).toHaveFocus();
    });

    it('grades from the keyboard once a score is there', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(makeGradebookPage({ items: [makeSubmissionItem()] }), {
                'POST /v1/submissions/31/grade': jsonResponse(gradedSubmission),
            }),
        );
        await screen.findByText(/Priya Nair/);
        await user.type(screen.getByLabelText('Score'), '85');
        /* Leave the field: the shortcut belongs to the queue, not to typing. */
        screen.getByText(/Priya Nair · Accessibility audit exercise/).focus();
        await user.keyboard('g');
        await waitFor(() => {
            expect(harness.requests.some((request) => request.path === '/v1/submissions/31/grade')).toBe(true);
        });
    });

    it('says the gradebook is clear when nothing waits', async () => {
        renderTab(handler(makeGradebookPage({ items: [], pending_total: 0 })));
        expect(await screen.findByText('Gradebook is clear.')).toBeInTheDocument();
        expect(screen.getByText(/New submissions appear here and by email/)).toBeInTheDocument();
    });

    it('renders the load error with a retry', async () => {
        renderTab(handler(makeGradebookPage(), { 'GET /v1/manage/courses/1/gradebook': problemResponse(500) }));
        expect(await screen.findByText("Couldn't load the gradebook.")).toBeInTheDocument();
    });

    it('renders the permission state when the caller may not grade the course', async () => {
        renderTab(handler(makeGradebookPage(), { 'GET /v1/manage/courses/1/gradebook': problemResponse(403) }));
        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });

    /*
     * lesson_id is nullable by contract: a lesson can leave the curriculum
     * while the work submitted against it still waits to be graded. The
     * row names the lesson it belongs to and links to nothing, so nothing
     * on this screen depends on that id being there.
     */
    it('grades an item whose lesson is gone, with no link to it', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(makeGradebookPage({ items: [makeSubmissionItem({ lesson_id: null })] }), {
                'POST /v1/submissions/31/grade': jsonResponse(gradedSubmission),
            }),
        );

        expect(await screen.findByText(/Priya Nair · Accessibility audit exercise/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Accessibility audit exercise/ })).not.toBeInTheDocument();

        await user.type(screen.getByLabelText('Score'), '85');
        await user.click(screen.getByRole('button', { name: 'Save assessment' }));
        await waitFor(() => {
            expect(harness.requests.some((request) => request.path === '/v1/submissions/31/grade')).toBe(true);
        });
    });
});

describe('gradebook table', () => {
    async function openTable(user: ReturnType<typeof userEvent.setup>) {
        await screen.findByText(/Priya Nair/);
        await user.click(screen.getByRole('button', { name: 'Table' }));
        return screen.findByRole('table', { name: 'Gradebook' });
    }

    it('shows both totals so the header needs no second request', async () => {
        renderTab();
        expect(await screen.findByText('2 pending · 41 graded')).toBeInTheDocument();
    });

    it('lists the pending work with how long each item has waited', async () => {
        const user = userEvent.setup();
        renderTab();
        const table = await openTable(user);
        expect(table).toHaveTextContent('Priya Nair');
        expect(table).toHaveTextContent('Tom Weiss');
        expect(table).toHaveTextContent('attempt 1 of Module 2 check');
    });

    it('switches to the graded history with the contract sort of that view', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await screen.findByText(/Priya Nair/);
        await user.click(screen.getByRole('button', { name: /Graded/ }));
        await waitFor(() => {
            const last = harness.requests[harness.requests.length - 1];
            expect(last?.path).toContain('status=graded');
            expect(last?.path).toContain('sort=graded_at');
            expect(last?.path).toContain('order=desc');
        });
    });

    it('narrows to one source through the type filter, server side', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await screen.findByText(/Priya Nair/);
        await user.click(screen.getByRole('button', { name: 'Assignments' }));
        await waitFor(() => {
            expect(harness.requests[harness.requests.length - 1]?.path).toContain('type=submission');
        });
    });

    it('sorts by learner through the server, never in the browser', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        const table = await openTable(user);
        await user.click(screen.getByRole('button', { name: /LEARNER/ }));
        expect(table).toBeInTheDocument();
        await waitFor(() => {
            expect(harness.requests[harness.requests.length - 1]?.path).toContain('sort=learner');
        });
    });

    it('opens the queue at the row whose Grade button was pressed', async () => {
        const user = userEvent.setup();
        renderTab();
        await openTable(user);
        await user.click(screen.getByRole('button', { name: 'Grade the work of Tom Weiss' }));
        expect(await screen.findByText(/Tom Weiss · Module 2 check/)).toBeInTheDocument();
    });
});
