import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Skeleton } from './skeleton';

describe('Skeleton', () => {
    it('is invisible to assistive technology', () => {
        const { container } = render(<Skeleton className="h-3.5 w-3/5" />);
        const bone = container.firstChild as HTMLElement;
        expect(bone).toHaveAttribute('aria-hidden', 'true');
    });

    it('takes shape from the caller and stills under reduced motion', () => {
        const { container } = render(<Skeleton className="h-16" />);
        const bone = container.firstChild as HTMLElement;
        expect(bone.className).toContain('h-16');
        expect(bone.className).toContain('animate-pulse');
        expect(bone.className).toContain('motion-reduce:animate-none');
    });
});
