import { forwardRef, useId, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { cn } from '@/shared/cn';

const control = 'h-10 w-full rounded border border-border bg-bg px-3 text-[13px] text-fg placeholder:text-fg-2';

export function BankField({
    label,
    hint,
    error,
    children,
    className,
}: {
    label: string;
    hint?: string;
    error?: string;
    children: (props: { id: string; describedBy?: string; invalid: boolean }) => ReactNode;
    className?: string;
}) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
        .filter((value): value is string => value !== null)
        .join(' ');
    return (
        <div className={cn('grid gap-1.5', className)}>
            <label htmlFor={id} className="font-display text-xs font-semibold text-fg">{label}</label>
            {children({ id, describedBy: describedBy || undefined, invalid: error !== undefined })}
            {hint !== undefined && <p id={hintId} className="text-[11.5px] text-fg-2">{hint}</p>}
            {error !== undefined && <p id={errorId} className="text-[11.5px] font-medium text-danger">{error}</p>}
        </div>
    );
}

export const BankInput = forwardRef<HTMLInputElement, ComponentPropsWithoutRef<'input'> & { invalid?: boolean }>(function BankInput({ className, invalid, ...props }, ref) {
    return <input ref={ref} className={cn(control, invalid && 'border-danger', className)} aria-invalid={invalid || undefined} {...props} />;
});

export const BankSelect = forwardRef<HTMLSelectElement, ComponentPropsWithoutRef<'select'> & { invalid?: boolean }>(function BankSelect({ className, invalid, ...props }, ref) {
    return <select ref={ref} className={cn(control, 'cursor-pointer pr-8', invalid && 'border-danger', className)} aria-invalid={invalid || undefined} {...props} />;
});

export const BankTextArea = forwardRef<HTMLTextAreaElement, ComponentPropsWithoutRef<'textarea'> & { invalid?: boolean }>(function BankTextArea({ className, invalid, ...props }, ref) {
    return <textarea ref={ref} className={cn(control, 'h-auto min-h-24 resize-y py-2.5', invalid && 'border-danger', className)} aria-invalid={invalid || undefined} {...props} />;
});

export function EditorSection({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="grid gap-3 rounded-lg border border-border bg-surface p-4">
            <h2 className="font-display text-sm font-semibold text-fg">{title}</h2>
            {children}
        </section>
    );
}
