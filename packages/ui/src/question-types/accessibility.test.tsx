import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MountElContext } from '@/shared/context';
import { channelRegistry } from '@/shared/a11y';
import { ErrorSummary } from './error-summary';
import { QuestionHost } from './host';
import { createReactQuestionModule } from './react-module';
import { questionTypeRegistry } from './bridge';
import { renderQuestion } from './testing';
import { orderingDefinition } from './free/fixtures';

/*
 * The accessibility contract of the registry: the parts a module must not
 * own, asserted where they live.
 *
 * The conformance unit is the whole attempt, not the question. WCAG 2.2
 * Conformance Requirement 3 makes every page of a process part of the same
 * unit, so one inaccessible question type breaks conformance of every
 * attempt that contains it, and Requirement 5 makes a focus trap or an
 * autoplay a page-level failure rather than an item-level one. That is why
 * these live in the registry rather than on a review checklist.
 */

describe('the four live region channels', () => {
    it('creates the three announcing channels before any content, with their own roles', () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const registry = channelRegistry(container);

        expect(registry.regions.status.getAttribute('role')).toBe('status');
        expect(registry.regions.alert.getAttribute('role')).toBe('alert');
        expect(registry.regions.log.getAttribute('role')).toBe('log');
        /* Not atomic, so a queued message is announced as an addition
           instead of re-reading the whole region. */
        for (const channel of ['status', 'alert', 'log'] as const) {
            expect(registry.regions[channel].getAttribute('aria-atomic')).toBe('false');
            expect(registry.regions[channel].textContent).toBe('');
        }
        expect(registry.regions.log.getAttribute('aria-relevant')).toBe('additions');
        container.remove();
    });

    it('serialises consecutive messages instead of letting them overwrite each other', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const registry = channelRegistry(container);

        registry.announce('status', 'Picked up boils');
        registry.announce('status', 'boils moved over gap 1');
        registry.announce('status', 'boils dropped into gap 1');

        expect(registry.regions.status.textContent).toBe('Picked up boils');
        await waitFor(() => expect(registry.regions.status.textContent).toBe('boils moved over gap 1'));
        await waitFor(() => expect(registry.regions.status.textContent).toBe('boils dropped into gap 1'));
        container.remove();
    });

    it('appends to the log channel, so a reorder reads incrementally', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const registry = channelRegistry(container);
        registry.announce('log', 'item 1 is now fourth of five');
        await waitFor(() => expect(registry.regions.log.childElementCount).toBe(1));
        registry.announce('log', 'item 2 is now first of five');
        await waitFor(() => expect(registry.regions.log.childElementCount).toBe(2));
        container.remove();
    });

    it('leaves the timer channel out until something writes to it, because the pill is the page timer', () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const registry = channelRegistry(container);
        expect(container.querySelector("[data-vy-channel='timer']")).toBeNull();
        registry.announce('timer', '0:30');
        const timer = container.querySelector("[data-vy-channel='timer']");
        expect(timer?.getAttribute('role')).toBe('timer');
        expect(timer?.getAttribute('aria-live')).toBe('off');
        container.remove();
    });
});

describe('containment during a grab', () => {
    it('makes everything outside the item body inert, and gives it back on release', async () => {
        const user = userEvent.setup();
        const mountEl = document.createElement('div');
        mountEl.id = 'vidyalms-quiz-runner';
        document.body.appendChild(mountEl);

        function Shell() {
            return (
                <MountElContext.Provider value={mountEl}>
                    <div>
                        <header data-testid="chrome">
                            <button type="button">Submit</button>
                        </header>
                        <QuestionHost
                            typeId="ordering"
                            questionId={1}
                            definition={orderingDefinition}
                            response={null}
                            onResponseChange={() => {}}
                            locale="en-GB"
                        />
                    </div>
                </MountElContext.Provider>
            );
        }
        const view = render(<Shell />, { container: mountEl });
        const chrome = view.getByTestId('chrome');

        await user.click(screen.getByRole('button', { name: 'Pick up Run automated checks' }));
        /* The header, the timer, the palette and the navigation are DOM the
           module does not own, so the SHELL hides them, by setting inert
           outside the item body. */
        expect(chrome.hasAttribute('inert')).toBe(true);

        await user.keyboard('{Escape}');
        await waitFor(() => expect(chrome.hasAttribute('inert')).toBe(false));
        mountEl.remove();
    });

    it('never contains the shell surfaces, so the grab vocabulary is still announced', async () => {
        const user = userEvent.setup();
        const mountEl = document.createElement('div');
        mountEl.id = 'vidyalms-quiz-runner';
        document.body.appendChild(mountEl);

        function Shell() {
            return (
                <MountElContext.Provider value={mountEl}>
                    <div>
                        <QuestionHost
                            typeId="ordering"
                            questionId={2}
                            definition={orderingDefinition}
                            response={null}
                            onResponseChange={() => {}}
                            locale="en-GB"
                        />
                    </div>
                </MountElContext.Provider>
            );
        }
        render(<Shell />, { container: mountEl });

        await user.click(screen.getByRole('button', { name: 'Pick up Run automated checks' }));

        /* An inert subtree is out of the accessibility tree, so containing
           the channels would silence every placement announcement for the
           whole duration of the grab, role=alert included. */
        const channels = [...mountEl.querySelectorAll('[data-vy-channel]')];
        expect(channels.length).toBeGreaterThan(0);
        for (const channel of channels) {
            expect(channel.hasAttribute('inert')).toBe(false);
        }
        await user.keyboard('{Escape}');
        mountEl.remove();
    });
});

describe('the error summary', () => {
    it('is one alert above the content, links each error to its control, and takes focus', async () => {
        const user = userEvent.setup();
        document.title = 'Module 2 check';
        let jumped = 0;
        render(
            <div>
                <ErrorSummary
                    groups={[
                        {
                            label: 'Question 4 of 12',
                            onNavigate: () => {
                                jumped += 1;
                            },
                            errors: [
                                { controlId: 'vy-q-4-group', kind: 'TOO_MANY_SELECTED', message: 'Choose no more than 2.' },
                                {
                                    controlId: 'vy-q-4-gap2-field',
                                    kind: 'REQUIRED_GAP_EMPTY',
                                    message: 'Blank 2 is empty.',
                                    partId: 'gap2',
                                },
                            ],
                        },
                    ]}
                />
                <input id="vy-q-4-group" aria-label="group" />
                <input id="vy-q-4-gap2-field" aria-label="gap" />
            </div>,
        );

        const summary = screen.getByRole('alert');
        expect(summary).toHaveTextContent('There is a problem');
        await waitFor(() => expect(document.activeElement).toBe(summary));
        expect(document.title).toBe('Error: Module 2 check');

        /* The link wording is the inline wording, verbatim; a composite's
           per-part errors carry the part so one summary can group them. */
        const link = screen.getByRole('link', { name: 'Question 4 of 12: Choose no more than 2.' });
        expect(screen.getByRole('link', { name: /Part gap2: Blank 2 is empty\./ })).toBeInTheDocument();

        await user.click(link);
        expect(jumped).toBe(1);
        await waitFor(() => expect(document.activeElement?.id).toBe('vy-q-4-group'));
    });

    /**
     * A summary link lands on the control that failed, never on the sentence
     * describing it.
     *
     * Three types report the id of the InlineError paragraph the primitives
     * render, which resolves, so the summary focused a paragraph sitting
     * after the control and the learner had to shift-tab back to fix the
     * thing they had just been sent to. The control points at that paragraph
     * through aria-describedby, so the association already exists.
     */
    it('follows aria-describedby when the reported id names the message, not the control', async () => {
        const user = userEvent.setup();
        render(
            <div>
                <ErrorSummary
                    groups={[
                        {
                            label: 'Question 2 of 4',
                            errors: [
                                { controlId: 'vy-q-2-scale-error', kind: 'OUT_OF_RANGE', message: 'Enter 0 to 14.' },
                            ],
                        },
                    ]}
                />
                <input id="vy-q-2-scale-value" aria-label="Your value" aria-describedby="vy-q-2-scale-error" />
                <p id="vy-q-2-scale-error">Enter 0 to 14.</p>
            </div>,
        );

        await user.click(screen.getByRole('link', { name: /Enter 0 to 14\./ }));
        await waitFor(() => expect(document.activeElement?.id).toBe('vy-q-2-scale-value'));
    });

    it('renders nothing at all when no module contributed an error', () => {
        const { container } = render(<ErrorSummary groups={[{ label: 'Question 1 of 2', errors: [] }]} />);
        expect(container.textContent).toBe('');
    });
});

describe('the module seam', () => {
    it('hands a module its own element and never lets it see the app React tree', () => {
        const registry = questionTypeRegistry();
        let seenParentTag = '';
        if (!registry.has('x_seam_probe')) {
            registry.register(
                'x_seam_probe',
                createReactQuestionModule({
                    Renderer: ({ controlId }) => {
                        seenParentTag = controlId('probe');
                        return <p>probe</p>;
                    },
                    answeredStatus: () => 'presented',
                    describeResponse: () => null,
                }),
            );
        }
        const harness = renderQuestion({ typeId: 'x_seam_probe', definition: {}, questionId: 77 });
        expect(harness.mountEl.querySelector('[data-vy-question-type="x_seam_probe"]')).not.toBeNull();
        expect(seenParentTag).toBe('vy-q-77-probe');
    });

    it('restores UI state a module wrote, so a half-completed placement survives navigation', () => {
        const registry = questionTypeRegistry();
        if (!registry.has('x_state_probe')) {
            registry.register(
                'x_state_probe',
                createReactQuestionModule({
                    Renderer: ({ state, onState }) => (
                        <button type="button" onClick={() => onState('half-placed')}>
                            {state ?? 'nothing yet'}
                        </button>
                    ),
                    answeredStatus: () => 'presented',
                    describeResponse: () => null,
                }),
            );
        }
        renderQuestion({ typeId: 'x_state_probe', definition: {}, state: 'half-placed' });
        expect(screen.getByRole('button', { name: 'half-placed' })).toBeInTheDocument();
    });
});
