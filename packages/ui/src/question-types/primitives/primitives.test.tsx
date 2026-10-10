import { describe, expect, it, vi } from 'vitest';
import { useState, type ReactElement } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AnnounceChannel } from '@/shared/a11y';
import {
    ArtifactCapture,
    AssociationSet,
    MultiSelectGroup,
    OrderedList,
    RegionSet,
    ScalarControl,
    SingleSelectGroup,
    TextEntry,
    type CapturedArtifact,
    type DirectedPair,
} from './index';
import { bigChoiceClass, focusRing, hasNoManglingArbitraryValue, optionRowClass } from './styles';

/*
 * The three-mode rule, on every primitive.
 *
 * SC 2.5.7 requires that anything done by dragging be achievable with a
 * single pointer without dragging, and it operates independently of SC
 * 2.1.1, so the pointer run and the keyboard run are two separate proofs,
 * not one. The third is the linear single-input presentation: a native
 * select, a position number, a coordinate field, the text list beside an
 * image, which is what a switch user, a screen reader user on a graphic
 * surface and a 320 pixel viewport all get.
 *
 * No test here drags. If a primitive could only be completed by dragging,
 * every test in its group would fail, which is the point.
 */

interface Announced {
    channel: AnnounceChannel;
    message: string;
}

function harness<T>(initial: T, ui: (value: T, set: (next: T) => void, announced: Announced[]) => ReactElement) {
    const announced: Announced[] = [];
    const values: T[] = [];
    function Harness() {
        const [value, setValue] = useState<T>(initial);
        return ui(
            value,
            (next) => {
                values.push(next);
                setValue(next);
            },
            announced,
        );
    }
    return { values, announced, ui: <Harness /> };
}

const controlId = (local: string) => `vy-test-${local}`;

const options = [
    { key: 'a', label: 'Alpha' },
    { key: 'b', label: 'Beta' },
    { key: 'c', label: 'Gamma' },
];

describe('SingleSelectGroup', () => {
    it('selects with one pointer activation', async () => {
        const user = userEvent.setup();
        const h = harness<string | null>(null, (value, set, announced) => (
            <SingleSelectGroup
                options={options}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Pick one"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.click(screen.getByRole('radio', { name: 'Beta' }));
        expect(h.values.at(-1)).toBe('b');
    });

    it('selects with the keyboard through the native radio group', async () => {
        const user = userEvent.setup();
        const h = harness<string | null>('a', (value, set, announced) => (
            <SingleSelectGroup
                options={options}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Pick one"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        screen.getByRole('radio', { name: 'Alpha' }).focus();
        await user.keyboard('{ArrowDown}');
        expect(h.values.at(-1)).toBe('b');
    });

    it('offers the same choices as one native select in the linear presentation', async () => {
        const user = userEvent.setup();
        const h = harness<string | null>(null, (value, set, announced) => (
            <SingleSelectGroup
                presentation="linear"
                options={options}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Pick one"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.selectOptions(screen.getByRole('combobox', { name: 'Pick one' }), 'c');
        expect(h.values.at(-1)).toBe('c');
    });
});

describe('MultiSelectGroup', () => {
    it('toggles with the pointer and with the keyboard, and never auto-advances', async () => {
        const user = userEvent.setup();
        const h = harness<string[]>([], (value, set, announced) => (
            <MultiSelectGroup
                options={options}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Pick some"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.click(screen.getByRole('checkbox', { name: 'Alpha' }));
        expect(h.values.at(-1)).toEqual(['a']);
        screen.getByRole('checkbox', { name: 'Gamma' }).focus();
        await user.keyboard(' ');
        expect(h.values.at(-1)).toEqual(['a', 'c']);
    });

    it('announces the limit and keeps the refused control operable', async () => {
        const user = userEvent.setup();
        const h = harness<string[]>(['a'], (value, set, announced) => (
            <MultiSelectGroup
                options={options}
                value={value}
                onChange={set}
                maxChoices={1}
                controlId={controlId}
                label="Pick one"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.click(screen.getByRole('checkbox', { name: 'Beta' }));
        expect(h.values).toEqual([]);
        expect(screen.getByRole('checkbox', { name: 'Beta' })).toBeEnabled();
        expect(h.announced.at(-1)).toEqual({
            channel: 'status',
            message: 'Maximum of 1 reached, deselect one to change your answer.',
        });
    });
});

describe('OrderedList', () => {
    const items = [
        { key: 'one', label: 'First' },
        { key: 'two', label: 'Second' },
        { key: 'three', label: 'Third' },
    ];

    function orderHarness(initial: string[] = ['one', 'two', 'three']) {
        return harness<string[]>(initial, (value, set, announced) => (
            <OrderedList
                items={items}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Order them"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
    }

    it('reorders with two single pointer activations and no dragging', async () => {
        const user = userEvent.setup();
        const h = orderHarness();
        render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Pick up Third' }));
        await user.click(screen.getByRole('button', { name: 'Place here: First' }));
        expect(h.values.at(-1)).toEqual(['three', 'one', 'two']);
    });

    it('reorders with the move buttons, which are also a single pointer activation', async () => {
        const user = userEvent.setup();
        const h = orderHarness();
        render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Move Second up' }));
        expect(h.values.at(-1)).toEqual(['two', 'one', 'three']);
    });

    it('grabs, moves and drops with the keyboard, and Escape always releases', async () => {
        const user = userEvent.setup();
        const h = orderHarness();
        render(h.ui);
        const grab = screen.getByRole('button', { name: 'Pick up First' });
        grab.focus();
        await user.keyboard('{Enter}');
        expect(screen.getByRole('button', { name: 'Pick up First' })).toHaveAttribute('aria-pressed', 'true');
        await user.keyboard('{ArrowDown}');
        expect(h.values.at(-1)).toEqual(['two', 'one', 'three']);
        await user.keyboard('{Escape}');
        /* Escape restores the order the list had when the grab started, and
           releases, so no grab mode can trap focus (SC 2.1.2). */
        expect(h.values.at(-1)).toEqual(['one', 'two', 'three']);
        expect(screen.getByRole('button', { name: 'Pick up First' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('announces a reorder on the log channel, incrementally', async () => {
        const user = userEvent.setup();
        const h = orderHarness();
        render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Move Second up' }));
        expect(h.announced.at(-1)).toEqual({
            channel: 'log',
            message: 'The list has been reordered, Second is now 1 of 3.',
        });
    });

    it('never uses aria-grabbed or aria-dropeffect, which WAI-ARIA 1.1 deprecated', async () => {
        const user = userEvent.setup();
        const h = orderHarness();
        const { container } = render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Pick up First' }));
        expect(container.querySelector('[aria-grabbed]')).toBeNull();
        expect(container.querySelector('[aria-dropeffect]')).toBeNull();
    });

    it('reorders with one native position control per item in the linear presentation', async () => {
        const user = userEvent.setup();
        const h = harness<string[]>(['one', 'two', 'three'], (value, set, announced) => (
            <OrderedList
                presentation="linear"
                items={items}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Order them"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.selectOptions(screen.getByRole('combobox', { name: 'Position of Third' }), '1');
        expect(h.values.at(-1)).toEqual(['three', 'one', 'two']);
    });
});

describe('AssociationSet', () => {
    const sources = [
        { key: 's1', label: 'Contrast' },
        { key: 's2', label: 'Focus' },
    ];
    const targets = [
        { key: 't1', label: '1.4.3' },
        { key: 't2', label: '2.4.7' },
    ];

    function pairHarness(initial: DirectedPair[] = []) {
        return harness<DirectedPair[]>(initial, (value, set, announced) => (
            <AssociationSet
                sources={sources}
                targets={targets}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Match them"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
    }

    it('matches with two single pointer activations', async () => {
        const user = userEvent.setup();
        const h = pairHarness();
        render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Pick up Contrast' }));
        await user.click(screen.getByRole('button', { name: 'Place here: 1.4.3' }));
        expect(h.values.at(-1)).toEqual([['s1', 't1']]);
    });

    it('matches with the keyboard through the same state machine', async () => {
        const user = userEvent.setup();
        const h = pairHarness();
        render(h.ui);
        screen.getByRole('button', { name: 'Pick up Focus' }).focus();
        await user.keyboard('{Enter}');
        screen.getByRole('button', { name: 'Place here: 2.4.7' }).focus();
        await user.keyboard('{Enter}');
        expect(h.values.at(-1)).toEqual([['s2', 't2']]);
    });

    it('matches with one native select per source in the linear presentation', async () => {
        const user = userEvent.setup();
        const h = harness<DirectedPair[]>([], (value, set, announced) => (
            <AssociationSet
                presentation="linear"
                sources={sources}
                targets={targets}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Match them"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.selectOptions(screen.getByRole('combobox', { name: 'Match for Contrast' }), 't2');
        expect(h.values.at(-1)).toEqual([['s1', 't2']]);
    });

    it('refuses a target that is already full, and announces rather than disabling', async () => {
        const user = userEvent.setup();
        const h = harness<DirectedPair[]>([['s1', 't1']], (value, set, announced) => (
            <AssociationSet
                sources={sources}
                targets={[{ key: 't1', label: '1.4.3', matchMax: 1 }]}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Match them"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Pick up Focus' }));
        await user.click(screen.getByRole('button', { name: 'Place here: 1.4.3' }));
        expect(h.values).toEqual([]);
        expect(h.announced.at(-1)?.message).toContain('Maximum of 1 reached');
    });
});

describe('RegionSet', () => {
    const regions = [
        { key: 'r1', label: 'The header', left: 0, top: 0, width: 50, height: 20 },
        { key: 'r2', label: 'The footer', left: 0, top: 80, width: 50, height: 20 },
    ];
    const image = { src: 'about:blank', alt: 'A page layout' };

    function regionHarness() {
        return harness<string[]>([], (value, set, announced) => (
            <RegionSet
                image={image}
                regions={regions}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Choose the regions"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
    }

    it('always ships the parallel linear list beside the canvas', () => {
        const h = regionHarness();
        render(h.ui);
        expect(screen.getByText('Text alternative')).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'A page layout' })).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'The footer' })).toBeInTheDocument();
    });

    it('selects a region by one pointer activation on the hotspot', async () => {
        const user = userEvent.setup();
        const h = regionHarness();
        render(h.ui);
        await user.click(screen.getAllByRole('button', { name: 'The header' })[0] as HTMLElement);
        expect(h.values.at(-1)).toEqual(['r1']);
        expect(h.announced.at(-1)).toEqual({ channel: 'status', message: 'The header selected.' });
    });

    it('selects the same region from the keyboard through the linear list', async () => {
        const user = userEvent.setup();
        const h = regionHarness();
        render(h.ui);
        const row = screen.getByRole('checkbox', { name: 'The footer' });
        row.focus();
        await user.keyboard(' ');
        expect(h.values.at(-1)).toEqual(['r2']);
    });

    it('marks points from coordinate inputs when there is no usable canvas', async () => {
        const user = userEvent.setup();
        const h = harness<{ x: number; y: number }[]>([], (value, set, announced) => (
            <RegionSet
                mode="points"
                image={image}
                points={value}
                onPointsChange={set}
                controlId={controlId}
                label="Mark the fault"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        await user.type(screen.getByRole('spinbutton', { name: 'Value x' }), '30');
        await user.type(screen.getByRole('spinbutton', { name: 'Value y' }), '70');
        await user.click(screen.getByRole('button', { name: 'Add point' }));
        expect(h.values.at(-1)).toEqual([{ x: 30, y: 70 }]);
        expect(h.announced.at(-1)?.message).toBe('Point added at 30, 70.');
    });

    it('describes the image and carries its long description', () => {
        const h = harness<string[]>([], (value, set, announced) => (
            <RegionSet
                image={{ ...image, longDescription: 'The header sits above the content.' }}
                regions={regions}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Choose the regions"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        const img = screen.getByRole('img', { name: 'A page layout' });
        expect(img).toHaveAttribute('aria-describedby');
        expect(screen.getByText('The header sits above the content.')).toBeInTheDocument();
    });
});

describe('ScalarControl', () => {
    function scalarHarness(presentation: 'rich' | 'linear' = 'rich') {
        return harness<number | null>(null, (value, set, announced) => (
            <ScalarControl
                presentation={presentation}
                value={value}
                onChange={set}
                min={0}
                max={10}
                step={1}
                controlId={controlId}
                label="How confident"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
    }

    it('sets a value with the steppers, which is a single pointer activation', async () => {
        const user = userEvent.setup();
        const h = scalarHarness();
        render(h.ui);
        await user.click(screen.getByRole('button', { name: 'Increase' }));
        expect(h.values.at(-1)).toBe(1);
    });

    it('sets a value from the keyboard through the steppers, and exposes the slider value', async () => {
        const user = userEvent.setup();
        const h = scalarHarness();
        render(h.ui);
        /* The native range carries the role and the value for assistive
           technology (SC 4.1.2); the steppers and the numeric input are the
           keyboard routes that do not depend on repeated arrow presses
           across a long range. */
        const slider = screen.getByRole('slider', { name: 'How confident' });
        expect(slider).toHaveAttribute('min', '0');
        expect(slider).toHaveAttribute('max', '10');
        screen.getByRole('button', { name: 'Increase' }).focus();
        await user.keyboard('{Enter}');
        expect(h.values.at(-1)).toBe(1);
    });

    it('accepts the value typed straight into the numeric input', async () => {
        const user = userEvent.setup();
        const h = scalarHarness('linear');
        render(h.ui);
        await user.type(screen.getByRole('spinbutton', { name: 'How confident' }), '7');
        expect(h.values.at(-1)).toBe(7);
    });
});

describe('TextEntry', () => {
    it('takes typing, counts characters and describes the count to the field', async () => {
        const user = userEvent.setup();
        const h = harness<string>('', (value, set, announced) => (
            <TextEntry
                multiline
                counter="characters"
                maxLength={100}
                value={value}
                onChange={set}
                controlId={controlId}
                label="Your answer"
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        const box = screen.getByRole('textbox', { name: 'Your answer' });
        await user.type(box, 'Because');
        expect(h.values.at(-1)).toBe('Because');
        expect(box).toHaveAttribute('aria-describedby', expect.stringContaining('count'));
        expect(screen.getByText('7 / 100')).toBeInTheDocument();
    });

    it('repeats the error message inline, beside the control the summary links to', () => {
        const h = harness<string>('', (value, set, announced) => (
            <TextEntry
                value={value}
                onChange={set}
                controlId={controlId}
                label="Your answer"
                error="Enter a whole number."
                announce={(channel, message) => announced.push({ channel, message })}
            />
        ));
        render(h.ui);
        const box = screen.getByRole('textbox', { name: 'Your answer' });
        expect(box).toHaveAttribute('aria-invalid', 'true');
        expect(box).toHaveAttribute('aria-describedby', expect.stringContaining('vy-test-error'));
        expect(screen.getByText('Enter a whole number.')).toHaveAttribute('id', 'vy-test-error');
    });
});

describe('ArtifactCapture', () => {
    it('attaches and removes through named controls, announcing each', async () => {
        const user = userEvent.setup();
        const picked = vi.fn();
        const h = harness<CapturedArtifact[]>(
            [{ id: 'f1', name: 'answer.pdf', sizeBytes: 2048 }],
            (value, set, announced) => (
                <ArtifactCapture
                    value={value}
                    onChange={set}
                    onPick={picked}
                    controlId={controlId}
                    label="Attach your work"
                    announce={(channel, message) => announced.push({ channel, message })}
                />
            ),
        );
        render(h.ui);
        const list = screen.getByRole('group', { name: 'Attach your work' });
        expect(within(list).getByText('answer.pdf')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Remove answer.pdf' }));
        expect(h.values.at(-1)).toEqual([]);
        expect(h.announced.at(-1)).toEqual({ channel: 'status', message: 'answer.pdf removed.' });
    });
});

describe('the shared focus ring', () => {
    it('uses no Tailwind arbitrary value that mangles into an empty rule', () => {
        /* Tailwind rewrites `_` to a space inside `[...]`, so a ring written
           as `[outline:var(--_vy-focus)]` compiles to `outline: var(-- vy-focus)`,
           which is an invalid custom property and therefore an empty
           declaration block. Nothing fails at build time and the choice row
           silently ships with no visible focus indicator (SC 2.4.7). */
        expect(hasNoManglingArbitraryValue(focusRing)).toBe(true);
        expect(focusRing).toContain('has-[:focus-visible]:outline-2');
        expect(focusRing).toContain('has-[:focus-visible]:outline-accent');
    });

    it('is what every choice row wears, so one edit fixes every type', () => {
        for (const classes of [optionRowClass(false), optionRowClass(true), bigChoiceClass(false)]) {
            expect(classes).toContain(focusRing);
            expect(hasNoManglingArbitraryValue(classes)).toBe(true);
        }
    });
});
