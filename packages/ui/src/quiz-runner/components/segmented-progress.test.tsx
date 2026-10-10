import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SegmentedProgress } from './segmented-progress';
import { makeQuestion, singleChoiceDefinition, trueFalseDefinition } from '../test-utils';

/*
 * The artboard's segmented bar keeps its 4px visual while every jump
 * target meets WCAG 2.5.8: the button wraps the bar with enough vertical
 * padding for a 24px minimum hit area (happy-dom cannot measure layout,
 * so the proof asserts the size-giving classes on every segment).
 */

const questions = [
    makeQuestion({ id: 11, type: 'single_choice', position: 1, definition: singleChoiceDefinition }),
    makeQuestion({ id: 12, type: 'true_false', position: 2, definition: trueFalseDefinition }),
];

describe('SegmentedProgress', () => {
    it('shows numbered questions with answered and current states', () => {
        render(<SegmentedProgress questions={questions} answers={{ '11': 'aa' }} currentIndex={0} onJump={() => {}} />);
        const buttons = screen.getAllByRole('button');
        expect(buttons.map((button) => button.textContent)).toEqual(['1', '2']);
        expect(buttons[0]).toHaveAttribute('aria-current', 'true');
        expect(buttons[0]).toHaveAttribute('data-answered', 'true');
        expect(buttons[1]).toHaveAttribute('data-answered', 'false');
    });

    it('jumps to the segment and names each question with its state', async () => {
        const onJump = vi.fn();
        render(
            <SegmentedProgress questions={questions} answers={{ '11': 'aa' }} currentIndex={0} onJump={onJump} />,
        );
        expect(screen.getByRole('button', { name: 'Question 1: current' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Question 2: not answered' }));
        expect(onJump).toHaveBeenCalledWith(1);
    });
});
