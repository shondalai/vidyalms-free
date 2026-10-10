import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { RegradeDialog } from './tabs/regrade-dialog';
import { ReportsTab } from './tabs/reports-tab';
import { GradebookQueue } from './tabs/gradebook-queue';
import {
    jsonResponse,
    makeAnswerItem,
    makeHarness,
    makeQuestion,
    makeQuiz,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';
import {
    makeItemAnalysis,
    makeItemAnalysisRow,
    makeRegradeResult,
    makeRubricVersion,
} from './testing/assessment-fixtures';

/*
 * Regrade, item analysis and rubric grading.
 *
 * The three share one property that shapes every assertion: none of them may
 * quietly change a mark. A regrade appends and says what moved before it is
 * allowed to run; item analysis withholds a statistic below its response
 * count rather than showing a small number that reads like evidence; and a
 * rubric grade sends the levels a rater chose rather than a total that could
 * disagree with them.
 */

function handler(overrides: Record<string, Response | (() => Response)> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        switch (key) {
            case 'GET /v1/manage/courses/1/quizzes':
                return jsonResponse({ items: [makeQuiz()] });
            case 'GET /v1/manage/courses/1/reports/item-analysis':
                return jsonResponse(makeItemAnalysis());
            case 'POST /v1/manage/quizzes/21/regrade':
                return jsonResponse(makeRegradeResult());
            case 'POST /v1/attempts/71/answers/102/grade':
                return jsonResponse({
                    attempt: {
                        id: 71,
                        quiz_id: 21,
                        attempt_number: 1,
                        status: 'graded',
                        started_at: '2026-09-01 08:00:00',
                        submitted_at: '2026-09-01 08:20:00',
                        expires_at: null,
                        score: 5,
                        max_score: 6,
                        percentage: 83,
                        passed: true,
                    },
                    outcome: {
                        lesson_completed: true,
                        enrolment_completed: false,
                        attempt_finalised: true,
                        pending_manual_count: 0,
                    },
                });
            default:
                return null;
        }
    };
}

function bodyOf(requests: RecordedRequest[], method: string, path: string): Record<string, unknown> {
    const found = requests.find(
        (request) => request.method === method && (request.path.split('?')[0] ?? '') === path,
    );
    if (found === undefined) {
        throw new Error(`No ${method} ${path} was sent.`);
    }
    return (found.body ?? {}) as Record<string, unknown>;
}

describe('regrade', () => {
    function renderRegrade(route = handler()) {
        const harness = makeHarness(route);
        render(
            <RegradeDialog
                courseId={1}
                quiz={makeQuiz()}
                questions={[makeQuestion({ id: 101 })]}
                onClose={() => {}}
            />,
            { wrapper: harness.wrapper },
        );
        return harness;
    }

    it('offers the four policies by the names a migrating teacher already knows', () => {
        renderRegrade();

        const policy = screen.getByLabelText('What to award') as HTMLSelectElement;
        expect([...policy.options].map((option) => option.value)).toEqual([
            'corrected_key_only',
            'both_keys',
            'any_answer',
            'full_credit',
        ]);
    });

    it('states the consequence of the chosen rule before it can be run', async () => {
        const user = userEvent.setup();
        renderRegrade();

        expect(screen.getByText(/No score goes down/)).toBeInTheDocument();
        await user.selectOptions(screen.getByLabelText('What to award'), 'corrected_key_only');
        expect(screen.getByText(/Scores can go down/)).toBeInTheDocument();
    });

    it('does not offer the real regrade until the dry run has said what it would do', async () => {
        const user = userEvent.setup();
        const harness = renderRegrade();

        expect(screen.getByRole('button', { name: 'Regrade now' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Show me what would change' }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Regrade now' })).toBeEnabled());
        expect(bodyOf(harness.requests, 'POST', '/v1/manage/quizzes/21/regrade').dry_run).toBe(true);
    });

    it('answers exactly which attempts would move, and says nothing is written yet', async () => {
        const user = userEvent.setup();
        renderRegrade();

        await user.click(screen.getByRole('button', { name: 'Show me what would change' }));

        expect(await screen.findByRole('heading', { name: 'What would change' })).toBeInTheDocument();
        expect(
            screen.getByText('12 attempts examined · 2 attempts moved · 0 grade rows appended'),
        ).toBeInTheDocument();
        expect(screen.getByText('Nothing has been written yet.')).toBeInTheDocument();
        expect(
            screen.getByText('This changes the recorded mark of 2 attempts learners may already have seen.'),
        ).toBeInTheDocument();
    });

    it('carries the reason and the chosen questions into the write', async () => {
        const user = userEvent.setup();
        const harness = renderRegrade(
            handler({
                'POST /v1/manage/quizzes/21/regrade': () =>
                    jsonResponse(makeRegradeResult({ dry_run: false, grades_appended: 2 })),
            }),
        );

        await user.type(screen.getByLabelText(/Why \(recorded with the new grades\)/), 'Key was wrong.');
        await user.click(screen.getByLabelText('Only the questions I choose'));
        await user.click(await screen.findByLabelText(/Which of these is the primary audience/));
        await user.click(screen.getByRole('button', { name: 'Show me what would change' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Regrade now' })).toBeEnabled());
        await user.click(screen.getByRole('button', { name: 'Regrade now' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'POST', '/v1/manage/quizzes/21/regrade');
            expect(body.reason).toBe('Key was wrong.');
            expect(body.question_ids).toEqual([101]);
        });
        expect(await screen.findByRole('heading', { name: 'What changed' })).toBeInTheDocument();
        expect(screen.getByText('The new grades are recorded. The previous rows are still there.')).toBeInTheDocument();
    });

    it('says plainly when nothing would move', async () => {
        const user = userEvent.setup();
        renderRegrade(
            handler({
                'POST /v1/manage/quizzes/21/regrade': () =>
                    jsonResponse(makeRegradeResult({ attempts_changed: 0, items: [] })),
            }),
        );

        await user.click(screen.getByRole('button', { name: 'Show me what would change' }));

        expect(await screen.findByText('No attempt would move under this rule.')).toBeInTheDocument();
    });

    it('renders the refusal rather than pretending the regrade ran', async () => {
        const user = userEvent.setup();
        renderRegrade(handler({ 'POST /v1/manage/quizzes/21/regrade': problemResponse(403, 'Forbidden') }));

        await user.click(screen.getByRole('button', { name: 'Show me what would change' }));

        /* A refused write is not a failure to retry: the banner names the
           refusal and offers no retry, because retrying a refusal cannot
           succeed. */
        expect(
            await screen.findByText("Not saved. You don't manage this course."),
        ).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'What would change' })).not.toBeInTheDocument();
    });
});

describe('item analysis', () => {
    function renderReports(route = handler()) {
        const harness = makeHarness(route);
        render(<ReportsTab courseId={1} />, { wrapper: harness.wrapper });
        return harness;
    }

    it('renders a placeholder rather than an error when no provider computes the statistics', async () => {
        renderReports(
            handler({
                'GET /v1/manage/courses/1/reports/item-analysis': jsonResponse(
                    makeItemAnalysis({
                        computed: false,
                        computed_by: null,
                        reason: 'No report provider is registered on this site.',
                        items: [],
                        internal_consistency: null,
                        total: 0,
                    }),
                ),
            }),
        );

        expect(await screen.findByText('No statistics on this site yet')).toBeInTheDocument();
        expect(screen.getByText('No report provider is registered on this site.')).toBeInTheDocument();
    });

    it('says what the response threshold withholds and why', async () => {
        renderReports();

        expect(
            await screen.findByText(/Discrimination and option rates are withheld below 30 responses/),
        ).toBeInTheDocument();
    });

    it('names the version and the marking rule the row is keyed on', async () => {
        renderReports();

        expect(await screen.findByText(/v2 · marked by match_correct/)).toBeInTheDocument();
    });

    it('bands the discrimination in the conventional words', async () => {
        renderReports();

        expect(await screen.findByText('Excellent')).toBeInTheDocument();
        expect(screen.getByText(/0.40 and above is excellent/)).toBeInTheDocument();
    });

    it('shows the facility confidence interval beside the figure', async () => {
        renderReports();

        expect(await screen.findByText('62 to 80')).toBeInTheDocument();
    });

    it('says how many responses a row has when it is below the threshold', async () => {
        renderReports(
            handler({
                'GET /v1/manage/courses/1/reports/item-analysis': jsonResponse(
                    makeItemAnalysis({
                        items: [
                            makeItemAnalysisRow({
                                n: 11,
                                below_threshold: true,
                                discrimination: null,
                                discrimination_band: null,
                                distractors: [],
                            }),
                        ],
                    }),
                ),
            }),
        );

        expect(await screen.findByText(/11 responses so far/)).toBeInTheDocument();
        expect(screen.queryByText('Excellent')).not.toBeInTheDocument();
    });

    it('opens the option breakdown and flags a distractor that correlates with the total', async () => {
        const user = userEvent.setup();
        renderReports();

        await user.click(await screen.findByRole('button', { name: /Show the option breakdown/ }));

        expect(await screen.findByText('Level AA')).toBeInTheDocument();
        expect(screen.getByText('Positive correlation with the total score.')).toBeInTheDocument();
        expect(screen.getByText('TOP 27%')).toBeInTheDocument();
    });

    it('renders the empty state when nothing has been answered yet', async () => {
        renderReports(
            handler({
                'GET /v1/manage/courses/1/reports/item-analysis': jsonResponse(
                    makeItemAnalysis({ items: [], total: 0 }),
                ),
            }),
        );

        expect(await screen.findByText('Nothing to report yet')).toBeInTheDocument();
    });

    it('renders the permission state for a caller who may not read the reports', async () => {
        renderReports(
            handler({ 'GET /v1/manage/courses/1/reports/item-analysis': problemResponse(403, 'Forbidden') }),
        );

        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });
});

describe('rubric grading in the queue', () => {
    function rubricItem() {
        const item = makeAnswerItem();
        (item.quiz_answer as unknown as Record<string, unknown>).rubric = makeRubricVersion();
        return item;
    }

    function renderQueue(item = rubricItem()) {
        const harness = makeHarness(handler());
        render(
            <GradebookQueue
                courseId={1}
                items={[item]}
                offset={0}
                total={1}
                index={0}
                onIndex={() => {}}
                onGraded={() => {}}
                narrow={false}
            />,
            { wrapper: harness.wrapper },
        );
        return harness;
    }

    it('renders the criteria and their levels rather than a bare score box', () => {
        renderQueue();

        expect(screen.getByRole('group', { name: 'Accuracy' })).toBeInTheDocument();
        expect(screen.getByRole('radiogroup', { name: 'Level for Accuracy' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /Right throughout/ })).toBeInTheDocument();
        expect(screen.queryByLabelText('Score')).not.toBeInTheDocument();
    });

    it('keeps a running total as levels are chosen', async () => {
        const user = userEvent.setup();
        renderQueue();

        expect(screen.getAllByText('0 of 6').length).toBeGreaterThan(0);
        await user.click(screen.getByRole('radio', { name: /Right throughout/ }));
        expect(screen.getAllByText('3 of 6').length).toBeGreaterThan(0);
    });

    it('refuses to grade until every criterion has a level', async () => {
        const user = userEvent.setup();
        renderQueue();

        expect(screen.getByRole('button', { name: 'Grade with this rubric' })).toBeDisabled();
        expect(screen.getByText('Choose a level for every criterion before grading.')).toBeInTheDocument();
        await user.click(screen.getByRole('radio', { name: /Right throughout/ }));
        await user.click(screen.getByRole('radio', { name: /Clear/ }));
        expect(screen.getByRole('button', { name: 'Grade with this rubric' })).toBeEnabled();
    });

    it('sends the chosen levels and never a total that could disagree with them', async () => {
        const user = userEvent.setup();
        const harness = renderQueue();

        await user.click(screen.getByRole('radio', { name: /Mostly right/ }));
        await user.click(screen.getByRole('radio', { name: /Clear/ }));
        await user.click(screen.getByRole('button', { name: 'Grade with this rubric' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'POST', '/v1/attempts/71/answers/102/grade');
            expect(body.points_awarded).toBeUndefined();
            expect(body.criteria).toEqual([
                { criterion_id: 81, level_id: 92, points: null, comment: null },
                { criterion_id: 82, level_id: 95, points: null, comment: null },
            ]);
        });
    });

    it('carries a per criterion comment with the level it belongs to', async () => {
        const user = userEvent.setup();
        const harness = renderQueue();

        await user.click(screen.getByRole('radio', { name: /Mostly right/ }));
        await user.click(screen.getByRole('radio', { name: /Clear/ }));
        await user.click(screen.getByRole('button', { name: 'Comment on Accuracy' }));
        await user.type(screen.getByLabelText('Comment on Accuracy'), 'Two of three points landed.');
        await user.click(screen.getByRole('button', { name: 'Grade with this rubric' }));

        await waitFor(() => {
            const criteria = bodyOf(harness.requests, 'POST', '/v1/attempts/71/answers/102/grade')
                .criteria as { criterion_id: number; comment: string | null }[];
            expect(criteria[0]?.comment).toBe('Two of three points landed.');
        });
    });

    it('takes an override inside the chosen level for a rater who wants half of it', async () => {
        const user = userEvent.setup();
        const harness = renderQueue();

        await user.click(screen.getByRole('radio', { name: /Right throughout/ }));
        await user.click(screen.getByRole('radio', { name: /Clear/ }));
        const overrides = screen.getAllByLabelText('Points');
        await user.type(overrides[0] as HTMLElement, '2.5');
        await user.click(screen.getByRole('button', { name: 'Grade with this rubric' }));

        await waitFor(() => {
            const criteria = bodyOf(harness.requests, 'POST', '/v1/attempts/71/answers/102/grade')
                .criteria as { points: number | null }[];
            expect(criteria[0]?.points).toBe(2.5);
        });
    });

    it('leaves a question with no rubric on the plain score box it always had', () => {
        renderQueue(makeAnswerItem());

        expect(screen.getByLabelText('Score')).toBeInTheDocument();
        expect(screen.queryByRole('group', { name: 'Accuracy' })).not.toBeInTheDocument();
    });
});
