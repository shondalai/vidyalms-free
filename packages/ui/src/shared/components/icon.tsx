import type { ComponentProps } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '../cn';

export interface IconProps extends Omit<ComponentProps<'svg'>, 'ref'> {
    /** The lucide icon component, e.g. `CheckCircle2`. */
    icon: LucideIcon;
    /** Pixel size; 16 matches the 14px UI base. */
    size?: number;
    /**
     * Accessible name. Omit for decorative icons (the default): they are
     * aria-hidden. Provide it only when the icon is the sole carrier of
     * meaning, which the design avoids (shape + colour + label).
     */
    label?: string;
}

export function Icon({ icon: LucideCmp, size = 16, label, className, ...props }: IconProps) {
    return (
        <LucideCmp
            size={size}
            strokeWidth={2}
            aria-hidden={label ? undefined : true}
            role={label ? 'img' : undefined}
            aria-label={label}
            focusable="false"
            className={cn('inline-block shrink-0', className)}
            {...props}
        />
    );
}
