import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button } from './button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from './tooltip';

describe('Tooltip', () => {
    it('renders its content when open', () => {
        render(
            <TooltipProvider>
                <Tooltip defaultOpen>
                    <TooltipTrigger asChild>
                        <Button variant="ghost">Resume</Button>
                    </TooltipTrigger>
                    <TooltipContent>Continues at question 4</TooltipContent>
                </Tooltip>
            </TooltipProvider>,
        );
        expect(screen.getAllByText('Continues at question 4').length).toBeGreaterThan(0);
    });

    it('keeps the trigger as a plain button when closed', () => {
        render(
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button variant="ghost">Resume</Button>
                    </TooltipTrigger>
                    <TooltipContent>Continues at question 4</TooltipContent>
                </Tooltip>
            </TooltipProvider>,
        );
        expect(screen.getByRole('button', { name: 'Resume' })).toBeInTheDocument();
        expect(screen.queryByText('Continues at question 4')).toBeNull();
    });
});
