import type { ComponentProps, ReactNode } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../cn';

/*
 * Foundations artboard, piece 6 (StatusBadge): enrolment and result states,
 * always icon + word, never colour alone. The default glyph per variant
 * keeps that guarantee even when callers forget.
 */
export const badgeVariants = cva(
    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-semibold leading-none',
    {
        variants: {
            variant: {
                /* Status text on its own tint uses the darker strong pair
                   (AA-checked in tokens.test.ts); dots and borders keep the
                   base status colours. */
                ok: 'bg-ok-soft text-ok-strong',
                warn: 'bg-warn-soft text-warn-strong',
                danger: 'bg-danger-soft text-danger-strong',
                neutral: 'bg-surface-2 text-fg-2',
                accent: 'bg-accent-soft text-accent',
            },
        },
        defaultVariants: {
            variant: 'neutral',
        },
    },
);

const defaultGlyphs: Record<NonNullable<VariantProps<typeof badgeVariants>['variant']>, string> = {
    ok: '✓', // check
    warn: '◔', // quarter circle
    danger: '✕', // cross
    neutral: '−', // minus sign
    accent: '●', // filled dot
};

export interface BadgeProps extends ComponentProps<'span'>, VariantProps<typeof badgeVariants> {
    /**
     * The shape paired with the colour. Defaults per variant; pass null to
     * drop it when a neighbouring element already carries the shape.
     */
    glyph?: ReactNode | null;
}

export function Badge({ className, variant, glyph, children, ...props }: BadgeProps) {
    const resolvedGlyph = glyph === undefined ? defaultGlyphs[variant ?? 'neutral'] : glyph;
    return (
        <span className={cn(badgeVariants({ variant }), className)} {...props}>
            {resolvedGlyph !== null && <span aria-hidden="true">{resolvedGlyph}</span>}
            {children}
        </span>
    );
}
