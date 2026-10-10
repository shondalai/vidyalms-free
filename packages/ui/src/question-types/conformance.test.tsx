import { screen } from '@testing-library/react';
import { runConformanceKit } from './conformance';
import {
    fillBlankDefinition,
    multipleChoiceDefinition,
    orderingDefinition,
    shortAnswerDefinition,
    singleChoiceDefinition,
    trueFalseDefinition,
} from './free/fixtures';

/*
 * The conformance kit run against the six free types.
 *
 * This file is also the worked example a third-party author copies: the kit
 * is imported, handed a definition and two completion runs (one keyboard,
 * one single-pointer without dragging), and it does the rest. A renderer
 * that cannot be completed without a drag fails here rather than in an
 * audit, and a renderer with an unnamed control fails here rather than in a
 * support ticket.
 */

runConformanceKit({
    typeId: 'single_choice',
    definition: singleChoiceDefinition,
    completedResponse: 'aa',
    completeWithPointer: async (user) => {
        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
    },
    completeWithKeyboard: async (user) => {
        screen.getByRole('radio', { name: 'Level A' }).focus();
        await user.keyboard('{ArrowDown}');
    },
});

runConformanceKit({
    typeId: 'multiple_choice',
    definition: multipleChoiceDefinition,
    completedResponse: ['informative'],
    completeWithPointer: async (user) => {
        await user.click(screen.getByRole('checkbox', { name: 'Informative images' }));
    },
    completeWithKeyboard: async (user) => {
        screen.getByRole('checkbox', { name: 'Informative images' }).focus();
        await user.keyboard(' ');
    },
});

runConformanceKit({
    typeId: 'true_false',
    definition: trueFalseDefinition,
    completedResponse: false,
    completeWithPointer: async (user) => {
        await user.click(screen.getByRole('radio', { name: /False/ }));
    },
    completeWithKeyboard: async (user) => {
        screen.getByRole('radio', { name: /True/ }).focus();
        await user.keyboard('2');
    },
});

runConformanceKit({
    typeId: 'short_answer',
    definition: shortAnswerDefinition,
    completedResponse: 'Focus',
    completeWithPointer: async (user) => {
        await user.click(screen.getByPlaceholderText('Your answer…'));
        await user.paste('Focus');
    },
    completeWithKeyboard: async (user) => {
        screen.getByPlaceholderText('Your answer…').focus();
        await user.keyboard('Focus');
    },
});

runConformanceKit({
    typeId: 'fill_blank',
    definition: fillBlankDefinition,
    completedResponse: { ratio: '45' },
    completeWithPointer: async (user) => {
        await user.click(screen.getByRole('textbox', { name: 'Blank 1 of 2' }));
        await user.paste('45');
    },
    completeWithKeyboard: async (user) => {
        screen.getByRole('textbox', { name: 'Blank 1 of 2' }).focus();
        await user.keyboard('45');
    },
});

runConformanceKit({
    typeId: 'ordering',
    definition: orderingDefinition,
    completedResponse: ['auto', 'sample', 'manual', 'report'],
    completeWithPointer: async (user) => {
        await user.click(screen.getByRole('button', { name: 'Move Run automated checks up' }));
    },
    completeWithKeyboard: async (user) => {
        screen.getByRole('button', { name: 'Pick up Run automated checks' }).focus();
        await user.keyboard('{Enter}{ArrowUp}');
    },
    grab: async (user) => {
        await user.click(screen.getByRole('button', { name: 'Pick up Run automated checks' }));
    },
});
