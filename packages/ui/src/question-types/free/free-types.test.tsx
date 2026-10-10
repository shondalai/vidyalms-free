import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderQuestion, channelText } from '../testing';
import { questionTypeRegistry } from '../bridge';
import {
    fillBlankDefinition,
    multipleChoiceDefinition,
    orderingDefinition,
    shortAnswerDefinition,
    singleChoiceDefinition,
    trueFalseDefinition,
} from './fixtures';

/*
 * The six free types are the registry's first six consumers, so every one of
 * them is exercised THROUGH the bridge: registration, mount(el, ctx), the
 * response seam and answeredStatus. A test that rendered the React component
 * directly would pass for a renderer that could never be loaded from another
 * bundle.
 *
 * Round-trip contract per type: a saved answer (the PUT wire shape) renders
 * back as the visible state, and interacting emits exactly that wire shape
 * again.
 */

describe('the six free types register at load', () => {
    it('is the whole free catalogue and nothing else knows their keys', () => {
        const registry = questionTypeRegistry();
        for (const key of ['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'fill_blank', 'ordering']) {
            expect(registry.has(key)).toBe(true);
            expect(registry.entry(key)?.flagged).toBe(false);
            expect(registry.entry(key)?.modes).toContain('POINTER_NO_DRAG');
            expect(registry.entry(key)?.modes).toContain('KEYBOARD');
        }
    });

    it('gives every one of them an authoring contract as well as a renderer', () => {
        const registry = questionTypeRegistry();
        for (const key of registry.keys()) {
            const module = registry.get(key);
            expect(typeof module?.mount).toBe('function');
            expect(typeof module?.authoring?.toFormValues).toBe('function');
            expect(typeof module?.authoring?.fromFormValues).toBe('function');
        }
    });
});

describe('single_choice through the bridge', () => {
    it('serializes the selected option key and renders a saved key back', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({ typeId: 'single_choice', definition: singleChoiceDefinition });
        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        expect(harness.responses.at(-1)).toBe('aa');
        expect(screen.getByRole('radio', { name: 'Level AA' })).toBeChecked();
    });

    it('restores a resumed answer', () => {
        renderQuestion({ typeId: 'single_choice', definition: singleChoiceDefinition, response: 'aaa' });
        expect(screen.getByRole('radio', { name: 'Level AAA' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'Level A' })).not.toBeChecked();
    });

    it('reads a malformed definition as unsupported instead of crashing the attempt', () => {
        renderQuestion({ typeId: 'single_choice', definition: {} });
        expect(screen.getByText("This question type isn't supported here.")).toBeInTheDocument();
    });
});

describe('multiple_choice through the bridge', () => {
    it('serializes the selected keys as an array, toggling on and off', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({
            typeId: 'multiple_choice',
            definition: multipleChoiceDefinition,
            response: ['informative'],
        });
        expect(screen.getByRole('checkbox', { name: 'Informative images' })).toBeChecked();
        await user.click(screen.getByRole('checkbox', { name: 'Icon-only buttons' }));
        expect(harness.responses.at(-1)).toEqual(['informative', 'icon']);
        await user.click(screen.getByRole('checkbox', { name: 'Informative images' }));
        expect(harness.responses.at(-1)).toEqual(['icon']);
        expect(screen.getByText('Select all that apply · 1 selected')).toBeInTheDocument();
    });

    it('announces a selection limit rather than silently disabling the rest', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({
            typeId: 'multiple_choice',
            definition: { ...multipleChoiceDefinition, max_choices: 1 },
        });
        await user.click(screen.getByRole('checkbox', { name: 'Informative images' }));
        await user.click(screen.getByRole('checkbox', { name: 'Icon-only buttons' }));
        /* The refused control stays enabled and operable: disabling it would
           read as broken and would provide no error text (SC 3.3.1). */
        expect(screen.getByRole('checkbox', { name: 'Icon-only buttons' })).toBeEnabled();
        expect(harness.responses.at(-1)).toEqual(['informative']);
        await waitFor(() => {
            expect(channelText(harness.mountEl, 'status')).toContain('Maximum of 1 reached');
        });
    });
});

describe('true_false through the bridge', () => {
    it('serializes a boolean and renders it back', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({ typeId: 'true_false', definition: trueFalseDefinition });
        await user.click(screen.getByRole('radio', { name: /False/ }));
        expect(harness.responses.at(-1)).toBe(false);
        expect(screen.getByRole('radio', { name: /False/ })).toBeChecked();
    });

    it('answers with the 1 and 2 digit shortcuts, which are scoped to the group', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({ typeId: 'true_false', definition: trueFalseDefinition });
        screen.getByRole('radio', { name: /True/ }).focus();
        await user.keyboard('1');
        expect(harness.responses.at(-1)).toBe(true);
        await user.keyboard('2');
        expect(harness.responses.at(-1)).toBe(false);
    });
});

describe('short_answer through the bridge', () => {
    it('serializes the text and keeps an honest character count', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({ typeId: 'short_answer', definition: shortAnswerDefinition });
        await user.type(screen.getByPlaceholderText('Your answer…'), 'Focus returns');
        expect(harness.responses.at(-1)).toBe('Focus returns');
        expect(screen.getByText('13 / 500')).toBeInTheDocument();
    });

    it('restores a resumed answer', () => {
        renderQuestion({ typeId: 'short_answer', definition: shortAnswerDefinition, response: 'Saved text' });
        expect(screen.getByPlaceholderText('Your answer…')).toHaveValue('Saved text');
    });
});

describe('fill_blank through the bridge', () => {
    it('renders one labelled inline input per gap and serializes a key map', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({ typeId: 'fill_blank', definition: fillBlankDefinition });
        await user.type(screen.getByRole('textbox', { name: 'Blank 1 of 2' }), '4.5');
        expect(harness.responses.at(-1)).toEqual({ ratio: '4.5' });
        await user.type(screen.getByRole('textbox', { name: 'Blank 2 of 2' }), '3');
        expect(harness.responses.at(-1)).toEqual({ ratio: '4.5', large: '3' });
    });

    it('restores a resumed answer into the right gaps', () => {
        renderQuestion({
            typeId: 'fill_blank',
            definition: fillBlankDefinition,
            response: { ratio: '4.5', large: '3' },
        });
        expect(screen.getByRole('textbox', { name: 'Blank 1 of 2' })).toHaveValue('4.5');
        expect(screen.getByRole('textbox', { name: 'Blank 2 of 2' })).toHaveValue('3');
    });
});

describe('ordering through the bridge', () => {
    it('moves items with the keyboard and announces the reorder on the log channel', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({ typeId: 'ordering', definition: orderingDefinition });
        const down = screen.getByRole('button', { name: 'Move Run automated checks down' });
        down.focus();
        await user.keyboard('{Enter}');
        expect(harness.responses.at(-1)).toEqual(['sample', 'manual', 'auto', 'report']);
        await waitFor(() => {
            expect(channelText(harness.mountEl, 'log')).toContain(
                'The list has been reordered, Run automated checks is now 3 of 4.',
            );
        });
        expect(screen.getByRole('button', { name: 'Move Define the page sample up' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Move Write up findings down' })).toBeDisabled();
    });

    it('renders a saved order and serializes the full key list on a move', async () => {
        const user = userEvent.setup();
        const harness = renderQuestion({
            typeId: 'ordering',
            definition: orderingDefinition,
            response: ['report', 'manual', 'auto', 'sample'],
        });
        expect(screen.getAllByRole('listitem')[0]).toHaveTextContent('Write up findings');
        await user.click(screen.getByRole('button', { name: 'Move Write up findings down' }));
        expect(harness.responses.at(-1)).toEqual(['manual', 'report', 'auto', 'sample']);
    });

    it('moves focus to the sibling button when a move disables the pressed button at an edge', async () => {
        const user = userEvent.setup();
        renderQuestion({ typeId: 'ordering', definition: orderingDefinition });
        const up = screen.getByRole('button', { name: 'Move Run automated checks up' });
        up.focus();
        await user.keyboard('{Enter}');
        const down = screen.getByRole('button', { name: 'Move Run automated checks down' });
        expect(up).toBeDisabled();
        await waitFor(() => expect(document.activeElement).toBe(down));
    });

    it('reports an INVALID_PERMUTATION rather than letting a repeated key be graded', () => {
        const harness = renderQuestion({
            typeId: 'ordering',
            definition: orderingDefinition,
            response: ['sample', 'sample', 'sample', 'sample'],
        });
        const reported = harness.errors.at(-1) ?? [];
        expect(reported.map((error) => error.kind)).toContain('INVALID_PERMUTATION');
    });
});
