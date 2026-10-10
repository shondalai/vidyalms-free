import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Button } from './button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from './dialog';

function renderSubmitDialog(onOpenChange = vi.fn()) {
    render(
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Submit attempt?</DialogTitle>
                <DialogDescription>
                    2 of 10 questions are unanswered. You can't change answers after submitting.
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline" size="sm">
                            Keep answering
                        </Button>
                    </DialogClose>
                    <Button size="sm">Submit attempt</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>,
    );
    return { onOpenChange };
}

describe('Dialog', () => {
    it('exposes a dialog named by its title', () => {
        renderSubmitDialog();
        const dialog = screen.getByRole('dialog', { name: 'Submit attempt?' });
        expect(dialog).toHaveTextContent('2 of 10 questions are unanswered.');
    });

    it('uses verb-labelled buttons, per the artboard', () => {
        renderSubmitDialog();
        expect(screen.getByRole('button', { name: 'Keep answering' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Submit attempt' })).toBeInTheDocument();
    });

    it('closes from the cancel action', async () => {
        const { onOpenChange } = renderSubmitDialog();
        await userEvent.click(screen.getByRole('button', { name: 'Keep answering' }));
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('closes on Escape', async () => {
        const { onOpenChange } = renderSubmitDialog();
        await userEvent.keyboard('{Escape}');
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('offers an accessible corner close', async () => {
        const { onOpenChange } = renderSubmitDialog();
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });
});
