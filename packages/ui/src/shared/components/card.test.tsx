import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from './card';

describe('Card', () => {
    it('renders title as a real heading with content', () => {
        render(
            <Card>
                <CardHeader>
                    <CardTitle>Panel title</CardTitle>
                    <CardDescription>Hairlines do the work.</CardDescription>
                </CardHeader>
                <CardContent>Body</CardContent>
            </Card>,
        );
        expect(screen.getByRole('heading', { level: 3, name: 'Panel title' })).toBeInTheDocument();
        expect(screen.getByText('Hairlines do the work.')).toBeInTheDocument();
        expect(screen.getByText('Body')).toBeInTheDocument();
    });

    it('lets the caller pick the heading level for the host page outline', () => {
        render(<CardTitle as="h2">Course summary</CardTitle>);
        expect(screen.getByRole('heading', { level: 2, name: 'Course summary' })).toBeInTheDocument();
    });

    it('uses the surface and hairline treatment', () => {
        const { container } = render(<Card>x</Card>);
        expect((container.firstChild as HTMLElement).className).toContain('rounded-lg');
        expect((container.firstChild as HTMLElement).className).toContain('border-border');
    });
});
