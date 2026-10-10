import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Button } from './button';
import { EmptyState } from './empty-state';

describe('EmptyState', () => {
    it('renders one sentence and one action', async () => {
        const onAdd = vi.fn();
        render(
            <EmptyState
                title="No lessons yet"
                description="Add the first lesson to this module."
                action={<Button onClick={onAdd}>Add lesson</Button>}
            />,
        );
        expect(screen.getByText('No lessons yet')).toBeInTheDocument();
        expect(screen.getByText('Add the first lesson to this module.')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Add lesson' }));
        expect(onAdd).toHaveBeenCalledTimes(1);
    });

    it('works with title alone', () => {
        const { container } = render(<EmptyState title="Nothing here" />);
        expect(screen.getByText('Nothing here')).toBeInTheDocument();
        expect(container.querySelector('button')).toBeNull();
        expect((container.firstChild as HTMLElement).className).toContain('border-dashed');
    });
});
