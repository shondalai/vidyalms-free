import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../cn';

/*
 * Foundations artboard, piece 13: one sentence + one action, dashed
 * hairline, no illustration clutter.
 */
export interface EmptyStateProps extends ComponentProps<'div'> {
    title: string;
    description?: string;
    /** Usually one Button. */
    action?: ReactNode;
}

export function EmptyState({ title, description, action, className, children, ...props }: EmptyStateProps) {
    return (
        <div
            className={cn('rounded-lg border border-dashed border-border p-6 text-center', className)}
            {...props}
        >
            <p className="text-[13px] font-semibold text-fg">{title}</p>
            {description !== undefined && <p className="mt-1 text-xs text-fg-2">{description}</p>}
            {action !== undefined && <div className="mt-3 flex justify-center">{action}</div>}
            {children}
        </div>
    );
}
