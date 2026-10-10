import type { ComponentProps } from 'react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { cn } from '../cn';
import { useMountEl } from '../context';

/*
 * Radix tooltip, portalled into the mount for token inheritance. Inverse
 * surface (fg on bg colours flipped), small, never the only way to reach
 * information: anything a tooltip says must also exist as text or an
 * accessible name.
 */
export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

export function TooltipContent({
    className,
    sideOffset = 6,
    ...props
}: ComponentProps<typeof TooltipPrimitive.Content>) {
    const mountEl = useMountEl();
    return (
        <TooltipPrimitive.Portal container={mountEl ?? undefined}>
            <TooltipPrimitive.Content
                sideOffset={sideOffset}
                className={cn(
                    'z-[100] rounded bg-fg px-2 py-1 font-body text-[11px] font-medium text-surface',
                    className,
                )}
                {...props}
            />
        </TooltipPrimitive.Portal>
    );
}
