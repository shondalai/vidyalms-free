import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminTabPanel, AdminTabs, adminTabPanelId } from './tabs';

/*
 * The ARIA tabs pattern with a roving tabindex: one tab stop for the whole
 * strip, arrows between tabs, Home and End to the ends, and the panel
 * labelled by its own tab. Only the selected panel is mounted, so only the
 * selected tab points at one: a dangling aria-controls is a broken promise
 * to a screen reader.
 */

const TABS = [
    { id: 'one', label: 'One' },
    { id: 'two', label: 'Two' },
    { id: 'three', label: 'Three' },
] as const;

function Harness() {
    const [current, setCurrent] = useState<'one' | 'two' | 'three'>('one');
    return (
        <>
            <AdminTabs label="Credentials" tabs={TABS} current={current} onSelect={setCurrent} />
            <AdminTabPanel id={current}>Panel {current}</AdminTabPanel>
        </>
    );
}

describe('AdminTabs', () => {
    it('keeps one tab stop for the whole strip', () => {
        render(<Harness />);
        expect(screen.getByRole('tab', { name: 'One' })).toHaveAttribute('tabindex', '0');
        expect(screen.getByRole('tab', { name: 'Two' })).toHaveAttribute('tabindex', '-1');
    });

    it('moves with the arrow keys and wraps', async () => {
        render(<Harness />);
        await userEvent.tab();
        await userEvent.keyboard('{ArrowRight}');
        expect(screen.getByRole('tab', { name: 'Two' })).toHaveAttribute('aria-selected', 'true');
        await userEvent.keyboard('{ArrowLeft}{ArrowLeft}');
        expect(screen.getByRole('tab', { name: 'Three' })).toHaveAttribute('aria-selected', 'true');
    });

    it('jumps to the ends with Home and End', async () => {
        render(<Harness />);
        await userEvent.tab();
        await userEvent.keyboard('{End}');
        expect(screen.getByRole('tab', { name: 'Three' })).toHaveAttribute('aria-selected', 'true');
        await userEvent.keyboard('{Home}');
        expect(screen.getByRole('tab', { name: 'One' })).toHaveAttribute('aria-selected', 'true');
    });

    it('points only the selected tab at the one mounted panel', async () => {
        render(<Harness />);
        expect(screen.getByRole('tab', { name: 'One' })).toHaveAttribute('aria-controls', adminTabPanelId('one'));
        expect(screen.getByRole('tab', { name: 'Two' })).not.toHaveAttribute('aria-controls');
        expect(screen.getByRole('tabpanel')).toHaveClass('w-full', 'min-w-0', 'max-w-full');
        await userEvent.click(screen.getByRole('tab', { name: 'Two' }));
        expect(screen.getByRole('tabpanel')).toHaveTextContent('Panel two');
    });
});
