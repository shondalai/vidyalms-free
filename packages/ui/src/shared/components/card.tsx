import type { ComponentProps } from 'react';
import { cn } from '../cn';

/*
 * Foundations artboard, piece 8: surface + hairline + radius-lg is the only
 * elevation. One shadow level max; hairlines do the work inside host pages.
 */
export function Card({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('rounded-lg border border-border bg-surface text-fg', className)} {...props} />;
}

export function CardHeader({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex flex-col gap-1 p-4', className)} {...props} />;
}

export interface CardTitleProps extends ComponentProps<'h3'> {
    /** Real heading levels matter inside a host page; pick the right one. */
    as?: 'h2' | 'h3' | 'h4';
}

export function CardTitle({ className, as: Heading = 'h3', ...props }: CardTitleProps) {
    return <Heading className={cn('font-display text-[13px] font-semibold', className)} {...props} />;
}

export function CardDescription({ className, ...props }: ComponentProps<'p'>) {
    return <p className={cn('text-xs text-fg-2', className)} {...props} />;
}

export function CardContent({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('p-4 pt-0', className)} {...props} />;
}

export function CardFooter({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex items-center gap-2 p-4 pt-0', className)} {...props} />;
}
