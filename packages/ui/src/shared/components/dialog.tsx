import type { ComponentProps } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '../cn';
import { useMountEl } from '../context';
import { sharedStrings } from '../strings';
import { VisuallyHidden } from './visually-hidden';

/*
 * Foundations artboard, piece 16: focus-trapped, Esc closes, verb-labelled
 * buttons. Radix handles the trap, restore and Esc. The layer portals into
 * the MOUNT element (not document.body) so the tokens keep inheriting and
 * the scoped utilities keep applying; fixed positioning is the one allowed
 * exception to the embed rules, and z-index stays at the 100 ceiling.
 */
export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

export interface DialogContentProps extends ComponentProps<typeof DialogPrimitive.Content> {
    /** Show the corner close button; on by default. */
    showClose?: boolean;
}

export function DialogContent({ className, children, showClose = true, ...props }: DialogContentProps) {
    const mountEl = useMountEl();
    return (
        <DialogPrimitive.Portal container={mountEl ?? undefined}>
            <DialogPrimitive.Overlay className="fixed inset-0 z-[100] bg-scrim" />
            <DialogPrimitive.Content
                className={cn(
                    'fixed inset-x-4 top-1/2 z-[100] mx-auto max-h-[calc(100dvh-2rem)] max-w-md -translate-y-1/2 overflow-y-auto rounded-lg border border-border bg-surface p-[18px] font-body text-fg shadow-[0_8px_24px_-12px_color-mix(in_srgb,var(--_vy-fg)_25%,transparent)]',
                    className,
                )}
                {...props}
            >
                {children}
                {showClose && (
                    <DialogPrimitive.Close className="absolute right-3 top-3 cursor-pointer rounded text-fg-2 hover:text-fg">
                        <X aria-hidden="true" size={16} />
                        <VisuallyHidden>{sharedStrings.close}</VisuallyHidden>
                    </DialogPrimitive.Close>
                )}
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}

export function DialogTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
    return (
        <DialogPrimitive.Title
            className={cn('mb-1.5 font-display text-sm font-bold', className)}
            {...props}
        />
    );
}

export function DialogDescription({ className, ...props }: ComponentProps<typeof DialogPrimitive.Description>) {
    return (
        <DialogPrimitive.Description className={cn('text-[12.5px] text-fg-2', className)} {...props} />
    );
}

export function DialogFooter({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('mt-3.5 flex flex-wrap justify-end gap-2', className)} {...props} />;
}
