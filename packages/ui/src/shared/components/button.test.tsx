import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Button } from './button';

describe('Button', () => {
    it('renders an accessible button and fires clicks', async () => {
        const onClick = vi.fn();
        render(<Button onClick={onClick}>Continue</Button>);
        const button = screen.getByRole('button', { name: 'Continue' });
        await userEvent.click(button);
        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('defaults to type=button so it never submits a host form by accident', () => {
        render(<Button>Save draft</Button>);
        expect(screen.getByRole('button', { name: 'Save draft' })).toHaveAttribute('type', 'button');
    });

    it('renders each variant of the artboard set', () => {
        render(
            <>
                <Button variant="primary">Continue</Button>
                <Button variant="outline">Save draft</Button>
                <Button variant="ghost">Cancel</Button>
                <Button variant="destructive">Delete</Button>
            </>,
        );
        expect(screen.getByRole('button', { name: 'Continue' }).className).toContain('bg-accent');
        expect(screen.getByRole('button', { name: 'Save draft' }).className).toContain('border-border');
        expect(screen.getByRole('button', { name: 'Cancel' }).className).toContain('bg-transparent');
        expect(screen.getByRole('button', { name: 'Delete' }).className).toContain('bg-danger');
    });

    it('supports the three sizes including the 44px touch target', () => {
        render(
            <>
                <Button size="sm">Small</Button>
                <Button size="md">Medium</Button>
                <Button size="touch">Touch</Button>
            </>,
        );
        expect(screen.getByRole('button', { name: 'Small' }).className).toContain('h-[34px]');
        expect(screen.getByRole('button', { name: 'Medium' }).className).toContain('h-10');
        expect(screen.getByRole('button', { name: 'Touch' }).className).toContain('h-11');
    });

    it('is disabled when asked and does not fire', async () => {
        const onClick = vi.fn();
        render(
            <Button disabled onClick={onClick}>
                Submit attempt
            </Button>,
        );
        const button = screen.getByRole('button', { name: 'Submit attempt' });
        expect(button).toBeDisabled();
        await userEvent.click(button).catch(() => undefined);
        expect(onClick).not.toHaveBeenCalled();
    });
});
