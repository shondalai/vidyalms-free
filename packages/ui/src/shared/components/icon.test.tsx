import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CheckCircle2 } from 'lucide-react';
import { Icon } from './icon';

describe('Icon', () => {
    it('is decorative by default', () => {
        const { container } = render(<Icon icon={CheckCircle2} />);
        const svg = container.querySelector('svg');
        expect(svg).toHaveAttribute('aria-hidden', 'true');
        expect(svg).not.toHaveAttribute('role');
    });

    it('becomes an image with a name when labelled', () => {
        render(<Icon icon={CheckCircle2} label="Lesson complete" />);
        expect(screen.getByRole('img', { name: 'Lesson complete' })).toBeInTheDocument();
    });

    it('defaults to the 16px size of the 14px UI base', () => {
        const { container } = render(<Icon icon={CheckCircle2} />);
        expect(container.querySelector('svg')).toHaveAttribute('width', '16');
    });
});
