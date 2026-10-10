import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Curriculum, LessonView, MyProgress } from '@/shared/api';
import { PlayerApp } from './app';
import { CurriculumRail } from './components/curriculum-rail';
import {
    courseFixture,
    jsonResponse,
    makeConfig,
    makeCurriculum,
    makeHarness,
    makeLessonView,
    makeMyProgress,
    OPEN_ACCESS,
    type RecordedRequest,
} from './testing/harness';

/*
 * A blocked item in the learner player.
 *
 * The rules under test are the accessibility ones, because they are the ones
 * a redesign quietly breaks. A blocked row KEEPS ITS TITLE, stays a real
 * button in the tab order, is never aria-disabled, and carries its reason as
 * text rather than as colour or an icon alone. A learner who cannot see why
 * an item is closed cannot plan around it, which is the failure the
 * shown-with-the-reason default exists to prevent.
 *
 * The two states a learner ever sees are AVAILABLE and BLOCKED. An item that
 * will never open and one whose window has closed both read as blocked with
 * a reason: the difference between them is a completion denominator fact,
 * and publishing it would tell the learner which exclusions were
 * administrative.
 */

const BLOCKED_BY_DATE = {
    state: 'blocked' as const,
    reason: 'availability.date.from',
    reason_parameters: { from: '2099-03-03 09:00:00', tz: 'UTC' },
    opens_at: '2099-03-03 09:00:00',
    opens_at_timezone: 'UTC',
};

const CLOSED_WINDOW = {
    state: 'blocked' as const,
    reason: 'availability.date.until',
    reason_parameters: { until: '2020-03-03 09:00:00', tz: 'UTC' },
    opens_at: null,
    opens_at_timezone: null,
};

function blockedCurriculum(): Curriculum {
    const curriculum = makeCurriculum();
    const second = curriculum.modules[1];
    const third = curriculum.modules[2];
    if (second === undefined || third === undefined) {
        throw new Error('fixture shape changed');
    }
    /* One blocked lesson inside an open module, and one whole blocked
       module whose lessons still travel: a blocked module lists its
       children so the learner can see the shape of what is coming. */
    const lesson = second.lessons[1];
    if (lesson === undefined) {
        throw new Error('fixture shape changed');
    }
    lesson.access = { ...BLOCKED_BY_DATE };
    third.access = { ...BLOCKED_BY_DATE };
    return curriculum;
}

function renderApp(curriculum: Curriculum, lessons: Record<number, LessonView>, progress?: MyProgress) {
    const config = makeConfig();
    const harness = makeHarness((request: RecordedRequest) => {
        if (request.method === 'GET' && request.path === '/v1/courses/web-accessibility') {
            return jsonResponse(courseFixture);
        }
        if (request.method === 'GET' && request.path === '/v1/courses/1/curriculum') {
            return jsonResponse(curriculum);
        }
        if (request.method === 'GET' && request.path === '/v1/me/progress?course_id=1') {
            return jsonResponse(progress ?? makeMyProgress());
        }
        const match = request.path.match(/^\/v1\/lessons\/(\d+)$/);
        if (request.method === 'GET' && match !== null) {
            const lesson = lessons[Number(match[1])];
            return lesson !== undefined ? jsonResponse(lesson) : null;
        }
        if (request.method === 'POST') {
            return new Response(null, { status: 204 });
        }
        return null;
    }, config);
    render(<PlayerApp />, { wrapper: harness.wrapper });
    return harness;
}

describe('the curriculum rail', () => {
    it('shows a blocked row with its title, its reason and the date it opens', () => {
        render(
            <CurriculumRail
                modules={blockedCurriculum().modules}
                currentLessonId={4}
                locale="en-GB"
                onSelect={() => undefined}
            />,
        );
        const row = screen.getByRole('button', { name: /Colour and contrast checklist/ });
        expect(row).toHaveTextContent('Colour and contrast checklist');
        expect(row).toHaveTextContent(/Available on 3 March/);
    });

    it('keeps a blocked row focusable and never marks it aria-disabled', async () => {
        const onSelect = vi.fn();
        render(
            <CurriculumRail
                modules={blockedCurriculum().modules}
                currentLessonId={4}
                locale="en-GB"
                onSelect={onSelect}
            />,
        );
        const row = screen.getByRole('button', { name: /Colour and contrast checklist/ });
        expect(row).not.toHaveAttribute('aria-disabled');
        expect(row).not.toBeDisabled();

        row.focus();
        expect(row).toHaveFocus();
        await userEvent.keyboard('{Enter}');
        /* Selecting it opens its blocked state, which is a state and not a
           refusal: the reason belongs where the learner went looking. */
        expect(onSelect).toHaveBeenCalledWith(5);
    });

    it('conveys the state with a hidden text label and a shape, never colour alone', () => {
        render(
            <CurriculumRail
                modules={blockedCurriculum().modules}
                currentLessonId={4}
                locale="en-GB"
                onSelect={() => undefined}
            />,
        );
        const row = screen.getByRole('button', { name: /Colour and contrast checklist/ });
        expect(row).toHaveTextContent('Not available yet');
        expect(row.querySelector('[data-state="blocked"]')).not.toBeNull();
    });

    it('lists the lessons of a blocked module and names the reason on the module too', () => {
        render(
            <CurriculumRail
                modules={blockedCurriculum().modules}
                currentLessonId={4}
                locale="en-GB"
                onSelect={() => undefined}
            />,
        );
        /* The contract sends a blocked module with its lessons; hiding them
           here would invent a visibility rule the server did not make. */
        expect(screen.getByRole('button', { name: /Focus management/ })).toBeInTheDocument();
        const heading = screen.getByRole('heading', { name: /Operable interfaces/ });
        expect(heading.parentElement?.textContent).toContain('Available on 3 March');
    });
});

describe('the lesson pane', () => {
    it('replaces the body with the reason, what is kept and one forward action', async () => {
        const curriculum = blockedCurriculum();
        renderApp(curriculum, {
            5: makeLessonView({ id: 5, title: 'Colour and contrast checklist', type: 'resource', access: { ...BLOCKED_BY_DATE } }),
            4: makeLessonView({ id: 4, access: { ...OPEN_ACCESS } }),
        });

        await userEvent.click(await screen.findByRole('button', { name: /Colour and contrast checklist/ }));

        expect(await screen.findByText(/Everything you have done so far is saved/)).toBeInTheDocument();
        expect(screen.getAllByText(/Available on 3 March/).length).toBeGreaterThan(1);
        /* The title is not withheld: withholding it is what HIDDEN means,
           and a blocked item is deliberately not hidden. */
        expect(screen.getAllByText('Colour and contrast checklist').length).toBeGreaterThan(0);
    });

    it('says a closed window is no longer available and promises no date', async () => {
        const curriculum = makeCurriculum();
        const lesson = curriculum.modules[1]?.lessons[1];
        if (lesson === undefined) {
            throw new Error('fixture shape changed');
        }
        lesson.access = { ...CLOSED_WINDOW };
        renderApp(curriculum, {
            5: makeLessonView({ id: 5, title: 'Colour and contrast checklist', type: 'resource', access: { ...CLOSED_WINDOW } }),
            4: makeLessonView({ id: 4, access: { ...OPEN_ACCESS } }),
        });

        await userEvent.click(await screen.findByRole('button', { name: /Colour and contrast checklist/ }));
        /* Once in the rail row and once in the pane. */
        expect((await screen.findAllByText('No longer available')).length).toBeGreaterThan(1);
        expect(screen.queryByText(/Available on/)).not.toBeInTheDocument();
    });
});

describe('the completion effect', () => {
    it('says some items are not counted when the outline carries more than the total', async () => {
        const curriculum = blockedCurriculum();
        /* Eight rows in the outline, six counted: the two excluded ones are
           not named, because which exclusions were administrative is not a
           learner's business. */
        renderApp(curriculum, { 4: makeLessonView({ id: 4, access: { ...OPEN_ACCESS } }) }, makeMyProgress({
            summary: {
                lessons_total: 6,
                lessons_completed: 2,
                percent: 33,
                quiz_average: null,
                final_quiz_passed: null,
                computed_at: '2026-09-01 10:00:00',
            },
        }));

        expect(await screen.findByText(/Some items are not counted towards completion/)).toBeInTheDocument();
        expect(screen.getByText('2 of 6 lessons complete')).toBeInTheDocument();
    });
});

describe('an unlock that passes while the page is open', () => {
    /*
     * Announced politely and NEVER navigated to. The bundle's own rule
     * against surprise navigation and SC 3.2.5 say the same thing, and the
     * passed instant is a hint rather than the answer: a leaf can name an
     * instant while the node above it names none, so the server is asked
     * again and its refreshed outline decides what the row says.
     */
    it('announces the item and refetches the outline, and moves nobody', async () => {
        vi.useFakeTimers();
        try {
            const curriculum = makeCurriculum();
            const lesson = curriculum.modules[1]?.lessons[1];
            if (lesson === undefined) {
                throw new Error('fixture shape changed');
            }
            const soon = new Date(Date.now() + 5_000);
            const pad = (value: number) => String(value).padStart(2, '0');
            lesson.access = {
                state: 'blocked',
                reason: 'availability.date.from',
                reason_parameters: {},
                opens_at:
                    `${soon.getUTCFullYear()}-${pad(soon.getUTCMonth() + 1)}-${pad(soon.getUTCDate())} `
                    + `${pad(soon.getUTCHours())}:${pad(soon.getUTCMinutes())}:${pad(soon.getUTCSeconds())}`,
                opens_at_timezone: 'UTC',
            };

            const harness = renderApp(curriculum, { 4: makeLessonView({ id: 4, access: { ...OPEN_ACCESS } }) });
            await vi.waitFor(() =>
                expect(harness.requests.some((request) => request.path === '/v1/courses/1/curriculum')).toBe(true),
            );
            const before = harness.requests.filter((request) => request.path === '/v1/courses/1/curriculum').length;

            await vi.advanceTimersByTimeAsync(7_000);

            await vi.waitFor(() =>
                expect(
                    harness.requests.filter((request) => request.path === '/v1/courses/1/curriculum').length,
                ).toBeGreaterThan(before),
            );
            /* The selected lesson is untouched: an unlock is news, never a
               command to move somebody. */
            expect(screen.getByRole('button', { current: true })).toHaveTextContent('Captions and transcripts');
        } finally {
            vi.useRealTimers();
        }
    });
});
