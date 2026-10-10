import type { ComponentProps } from 'react';
import { cn } from '../cn';

/*
 * Foundations artboard, piece 14: shape-true placeholders. Size each
 * skeleton like the content it stands in for (h-3.5 w-3/5 for a title
 * line, h-16 for a media block); never a generic throbber. aria-hidden:
 * loading is announced once by the surrounding view, not per bone.
 */
export function Skeleton({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            aria-hidden="true"
            className={cn('animate-pulse rounded-sm bg-surface-2 motion-reduce:animate-none', className)}
            {...props}
        />
    );
}
