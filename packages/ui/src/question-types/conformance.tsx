import { describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent, { type UserEvent } from '@testing-library/user-event';
import { questionTypeRegistry } from './bridge';
import { renderQuestion, type HarnessOptions } from './testing';
import { DROP_TARGET_ATTR, HELD_ATTR } from './primitives/select-then-place';
import type { InteractionMode, QuestionDefinition } from './contract';

/*
 * The conformance kit.
 *
 * Five automatable checks every registered type must pass, published so a
 * third-party author can run them against its own renderer before shipping,
 * plus the containment rule and the four hard prohibitions, which are cheap
 * to assert and expensive to discover in production.
 *
 * It is a REGISTRATION GATE rather than a checklist. A type declares the
 * identifier of a kit result through conformanceResult(); a type with none
 * registers in a visibly flagged state and the authoring UI surfaces the
 * flag, so a site can decide whether to publish with it. Composing the eight
 * primitives is what makes passing this straightforward; it is not what
 * makes passing it automatic, because mount(el, ctx) hands a module a bare
 * element it may fill with anything.
 *
 * What a DOM without layout cannot do is stated rather than faked. happy-dom
 * computes no boxes and composites no colours, so the reflow check reads
 * declared widths and the contrast check reads declared tokens and the
 * non-colour channel beside them. Both catch the mistakes that actually
 * ship: a fixed pixel width wider than a phone, and a state told by colour
 * alone. Neither replaces a rendered audit, and the kit says so.
 */

export interface ConformanceFixture extends HarnessOptions {
    /** A human name for the reports. */
    name?: string;
    /** Completes the item using the keyboard only. */
    completeWithKeyboard: (user: UserEvent) => Promise<void>;
    /** Completes the item with single-pointer activations and no dragging. */
    completeWithPointer: (user: UserEvent) => Promise<void>;
    /** What a completed response looks like; compared with toEqual. */
    completedResponse: unknown;
    /** Controls whose accessible name comes from visible text, not aria-label. */
    allowUnnamedRoles?: readonly string[];
    /**
     * Picks up one item and leaves it held, for a type built on select then
     * place. Supplying it turns on the grab containment check below; a type
     * with no grab has nothing to contain and supplies nothing.
     */
    grab?: (user: UserEvent) => Promise<void>;
}

const NAMED_ROLES = ['button', 'radio', 'checkbox', 'textbox', 'combobox', 'slider', 'spinbutton', 'link'];

/** Utilities that carry a colour; a state told by one of these needs more. */
const COLOUR_UTILITIES =
    /\b(bg|text|border|outline|fill|stroke)-(accent|ok|warn|danger|fg|bg|surface|border)(-[a-z0-9]+)?\b/;

/** Non-colour channels: a border weight, a shape, a glyph, a weight change. */
const NON_COLOUR_CHANNEL = /\b(border-2|border-\[|border-dashed|rounded-full|font-bold|font-medium|underline)\b/;

const RAW_COLOUR = /#[0-9a-fA-F]{3,8}\b|\brgba?\(/;

function elementsIn(root: HTMLElement): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>('*')];
}

/** Everything a Tab press can land on, which is what the grab rule is about. */
const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]';

/**
 * happy-dom computes no focus order, so this reads the DOM the browser would
 * read it from: a control is out of the sequence when it is disabled, when
 * it carries a negative tabindex, or when it or an ancestor is inert. That
 * is stated rather than faked, exactly as the reflow and contrast checks are.
 */
function tabbableIn(root: HTMLElement): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => {
        if (el.hasAttribute('disabled') || el.closest('[inert]') !== null) {
            return false;
        }
        const tabindex = el.getAttribute('tabindex');
        return tabindex === null || Number(tabindex) >= 0;
    });
}

function describeControl(el: HTMLElement): string {
    const name = accessibleName(el);
    return name === '' ? `a ${el.tagName.toLowerCase()}` : `"${name}"`;
}

/**
 * The accessible name of one control, in the order the HTML accessibility
 * API mapping resolves it.
 *
 * The wrapping label matters and reading the three attributes alone missed
 * it: every primitive hides its native input inside the label that carries
 * the visible text, which is what keeps the name, the role and the state
 * real, and a check that only looked for aria-label called all six free
 * types nameless. Reading aria-labelledby back as its own attribute value
 * was the other half of the same mistake: an id is not a name.
 */
function accessibleName(el: HTMLElement): string {
    const labelledBy = el.getAttribute('aria-labelledby');
    if (labelledBy !== null && labelledBy !== '') {
        const named = labelledBy
            .split(/\s+/)
            .map((id) => el.ownerDocument.getElementById(id)?.textContent ?? '')
            .join(' ')
            .trim();
        if (named !== '') {
            return named;
        }
    }
    const ariaLabel = el.getAttribute('aria-label');
    if (ariaLabel !== null && ariaLabel.trim() !== '') {
        return ariaLabel.trim();
    }
    const id = el.getAttribute('id');
    const associated =
        el.closest('label') ??
        (id === null || id === '' ? null : el.ownerDocument.querySelector(`label[for="${id}"]`));
    if (associated !== null && (associated.textContent ?? '').trim() !== '') {
        return (associated.textContent ?? '').trim();
    }
    const own = (el.textContent ?? '').trim();
    if (own !== '') {
        return own;
    }
    return (el.getAttribute('title') ?? el.getAttribute('placeholder') ?? '').trim();
}

function declaredWidthPx(el: HTMLElement): number | null {
    const inline = el.style.width || el.style.minWidth;
    const fromStyle = /^(\d+)px$/.exec(inline);
    if (fromStyle !== null) {
        return Number(fromStyle[1]);
    }
    /* A leading dash is not a word boundary Tailwind respects: without the
       (^|\s) anchor, "max-w-[620px]" matches the bare w-[…] alternative and
       a maximum width is read as a declared one. A max-w never forces a
       scrollbar; a w or a min-w does. */
    const fromClass = /(?:^|\s)(?:min-)?w-\[(\d+)px\]/.exec(el.className);
    if (fromClass !== null) {
        return Number(fromClass[1]);
    }
    return null;
}

/**
 * Runs the kit as its own describe block. Call it from a test file:
 *
 *   runConformanceKit({ typeId: 'x_acme_molecule', definition, ... })
 */
export function runConformanceKit(fixture: ConformanceFixture): void {
    const title = fixture.name ?? fixture.typeId;

    describe(`conformance kit: ${title}`, () => {
        it('declares the three-mode rule the registry enforces', () => {
            const entry = questionTypeRegistry().entry(fixture.typeId);
            expect(entry, `${fixture.typeId} is not registered`).not.toBeNull();
            const modes: readonly InteractionMode[] = entry?.modes ?? [];
            /* SC 2.5.7 operates independently of SC 2.1.1: keyboard support
               does not satisfy it. Drag is an enhancement, never a mode on
               its own, unless the dragging is essential and justified. */
            if (modes.includes('POINTER_DRAG') && !(modes.includes('POINTER_NO_DRAG') && modes.includes('KEYBOARD'))) {
                expect(entry?.essentialDraggingJustification ?? '').not.toBe('');
            } else {
                expect(modes).toContain('POINTER_NO_DRAG');
                expect(modes).toContain('KEYBOARD');
            }
        });

        it('completes with the keyboard alone', async () => {
            const user = userEvent.setup();
            const harness = renderQuestion(fixture);
            await fixture.completeWithKeyboard(user);
            expect(harness.responses.at(-1)).toEqual(fixture.completedResponse);
        });

        it('completes with single pointer activations and no dragging', async () => {
            const user = userEvent.setup();
            const harness = renderQuestion(fixture);
            await fixture.completeWithPointer(user);
            expect(harness.responses.at(-1)).toEqual(fixture.completedResponse);
        });

        it('gives every control it renders an accessible name and a role', () => {
            const harness = renderQuestion(fixture);
            const allowed = new Set(fixture.allowUnnamedRoles ?? []);
            for (const role of NAMED_ROLES) {
                if (allowed.has(role)) {
                    continue;
                }
                for (const control of within(harness.mountEl).queryAllByRole(role)) {
                    expect(
                        accessibleName(control),
                        `a ${role} rendered by ${title} has no accessible name`,
                    ).not.toBe('');
                }
            }

            // A file input maps to no ARIA role at all, so a role-driven
            // sweep never covered it and one type shipped a picker with no
            // name: the group around it was named and the control inside it
            // was not, and an id is not a name (SC 4.1.2, SC 3.3.2).
            for (const control of harness.mountEl.querySelectorAll<HTMLElement>('input[type=""file""]')) {
                expect(
                    accessibleName(control),
                    `a file input rendered by ${title} has no accessible name`,
                ).not.toBe('');
            }
        });

        it('declares no width that forces horizontal scrolling at 320 CSS pixels', () => {
            const harness = renderQuestion(fixture);
            for (const el of elementsIn(harness.mountEl)) {
                const width = declaredWidthPx(el);
                if (width !== null) {
                    expect(width, `${title} declares a ${width}px width`).toBeLessThanOrEqual(320);
                }
            }
        });

        it('tells every state with a channel besides colour, and only through tokens', () => {
            const harness = renderQuestion(fixture);
            for (const el of elementsIn(harness.mountEl)) {
                const className = typeof el.className === 'string' ? el.className : '';
                expect(RAW_COLOUR.test(className), `${title} hardcodes a colour outside the tokens`).toBe(false);
                expect(RAW_COLOUR.test(el.style.cssText), `${title} hardcodes a colour outside the tokens`).toBe(
                    false,
                );
                const selected =
                    el.getAttribute('aria-pressed') === 'true' ||
                    el.getAttribute('aria-selected') === 'true' ||
                    el.getAttribute('aria-checked') === 'true';
                if (selected && COLOUR_UTILITIES.test(className)) {
                    expect(
                        NON_COLOUR_CHANNEL.test(className) || (el.textContent ?? '').trim() !== '',
                        `${title} tells a selected state with colour alone`,
                    ).toBe(true);
                }
            }
        });

        /*
         * "During a drag, Tab visits only drop targets that accept the held
         * item and everything else is hidden from assistive technology."
         *
         * The shell's captureInteraction contains everything outside the
         * item body, which is the outward half and was the only half that
         * existed: inside the widget, Tab still walked every move button,
         * every remove button and every other row before it reached a
         * destination, and a screen reader read them all out as if they were
         * still choices.
         */
        const grab = fixture.grab;

        if (grab !== undefined) {
            it('lets Tab reach only the destinations while an item is held', async () => {
                const user = userEvent.setup();
                const harness = renderQuestion(fixture);
                await grab(user);

                const reachable = tabbableIn(harness.mountEl);
                const targets = harness.mountEl.querySelectorAll(`[${DROP_TARGET_ATTR}]`);

                expect(targets.length, `${title} offers no destination while an item is held`).toBeGreaterThan(0);

                for (const control of reachable) {
                    expect(
                        control.closest(`[${DROP_TARGET_ATTR}]`) !== null
                            || control.closest(`[${HELD_ATTR}]`) !== null,
                        `${title} leaves ${describeControl(control)} in the tab sequence during a grab`,
                    ).toBe(true);
                }
            });

            /*
             * Escape always releases, so no grab mode can trap focus (SC
             * 2.1.2), and releasing has to undo the containment as well as
             * the hold. The count is not compared across the grab because a
             * destination is often a control that only exists while
             * something is held; what is compared is the widget before the
             * grab against the widget after it.
             */
            it('puts everything back in the tab sequence when the grab is cancelled', async () => {
                const user = userEvent.setup();
                const harness = renderQuestion(fixture);
                const before = tabbableIn(harness.mountEl).map(describeControl);
                await grab(user);

                expect(
                    harness.mountEl.querySelectorAll('[inert]').length,
                    `${title} contained nothing when an item was picked up`,
                ).toBeGreaterThan(0);

                await user.keyboard('{Escape}');

                expect(harness.mountEl.querySelectorAll('[inert]').length).toBe(0);
                expect(tabbableIn(harness.mountEl).map(describeControl)).toEqual(before);
            });
        }

        it('never touches DOM outside the element the shell handed it', async () => {
            const user = userEvent.setup();
            renderQuestion(fixture);
            /* A sibling of the mount stands in for the header, the timer and
               the progress palette: DOM the module does not own. */
            const outside = document.createElement('main');
            outside.innerHTML = '<h1>Quiz</h1><nav><button type="button">Next</button></nav>';
            document.body.appendChild(outside);
            const beforeOutside = outside.innerHTML;
            await fixture.completeWithPointer(user);
            expect(outside.innerHTML, `${title} mutated DOM it does not own`).toBe(beforeOutside);
            outside.remove();
        });

        it('breaks none of the four integrity prohibitions', () => {
            const harness = renderQuestion(fixture);
            for (const el of elementsIn(harness.mountEl)) {
                /* Never disable the clipboard, text selection or the context
                   menu: that breaks read-aloud, braille review, glossary
                   lookup and dictation, all named AfA supports. */
                for (const attribute of ['oncopy', 'oncut', 'onpaste', 'oncontextmenu', 'onselectstart']) {
                    expect(el.hasAttribute(attribute), `${title} suppresses ${attribute}`).toBe(false);
                }
                expect(/user-select:\s*none/.test(el.style.cssText)).toBe(false);
            }
            /* Never set user-scalable=no or a maximum-scale below 2. */
            for (const meta of document.querySelectorAll('meta[name="viewport"]')) {
                const content = meta.getAttribute('content') ?? '';
                expect(content).not.toContain('user-scalable=no');
            }
            /* A hidden element is hidden from everyone, so a renderer must
               not smuggle the answer into one. */
            expect(screen.queryByText(/correct answer/i)).toBeNull();
        });
    });
}

/** A definition the kit can hand a renderer that reads nothing of its own. */
export function emptyDefinition(): QuestionDefinition {
    return { schema_version: 1, prompt: '' };
}
