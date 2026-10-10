import type { ComponentProps } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../cn';

/*
 * Foundations artboard, piece 1: primary / outline / ghost / destructive,
 * 40px default, 44px touch, display font at 600. Focus comes from the
 * global --vy-focus ring; no per-instance focus classes.
 */
export const buttonVariants = cva(
    'inline-flex cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-btn font-display font-semibold transition-colors duration-1 disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                primary: 'bg-accent text-accent-fg hover:opacity-90',
                outline: 'border border-border bg-surface text-fg hover:bg-surface-2',
                ghost: 'bg-transparent text-fg-2 hover:bg-surface-2 hover:text-fg',
                destructive: 'bg-danger text-accent-fg hover:opacity-90',
            },
            size: {
                sm: 'h-[34px] px-3.5 text-xs',
                md: 'h-10 px-4 text-[13px]',
                touch: 'h-11 px-4 text-[13px]',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
        },
    },
);

export interface ButtonProps extends ComponentProps<'button'>, VariantProps<typeof buttonVariants> {}

export function Button({ className, variant, size, type, ...props }: ButtonProps) {
    return <button type={type ?? 'button'} className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}
