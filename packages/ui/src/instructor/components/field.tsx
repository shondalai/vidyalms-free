import { forwardRef, useId, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { cn } from '@/shared/cn';

/*
 * Foundations artboard, piece 11 (Field set): label above, control, error
 * below. The label is a real <label for>, the hint and the error are wired
 * through aria-describedby, and an invalid control carries aria-invalid, so
 * the error is never colour alone.
 */

export const controlClass =
    'w-full rounded border border-border bg-surface px-3 text-[13px] text-fg placeholder:text-fg-2/70 disabled:opacity-60';

export const inputClass = cn(controlClass, 'h-[38px]');

export interface FieldProps {
    label: string;
    hint?: string;
    error?: string;
    /** Renders the control with the ids the label, hint and error need. */
    children: (props: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
    className?: string;
    /** Put the label out of sight when a column header already names the control. */
    hideLabel?: boolean;
}

export function Field({ label, hint, error, children, className, hideLabel = false }: FieldProps) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint !== undefined ? hintId : null, error !== undefined ? errorId : null]
        .filter((value): value is string => value !== null)
        .join(' ');
    return (
        <div className={cn('grid min-w-0 content-start gap-1', className)}>
            <label
                htmlFor={id}
                className={cn('font-display text-xs font-semibold text-fg', hideLabel && 'sr-only')}
            >
                {label}
            </label>
            {children({ id, describedBy: describedBy === '' ? undefined : describedBy, invalid: error !== undefined })}
            {hint !== undefined && (
                <p id={hintId} className="text-[11.5px] text-fg-2">
                    {hint}
                </p>
            )}
            {error !== undefined && (
                <p id={errorId} className="text-[11.5px] font-medium text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

export const TextInput = forwardRef<
    HTMLInputElement,
    ComponentPropsWithoutRef<'input'> & { invalid?: boolean }
>(function TextInput({ className, invalid, ...props }, ref) {
    return (
        <input
            ref={ref}
            type="text"
            aria-invalid={invalid === true ? true : undefined}
            className={cn(inputClass, invalid === true && 'border-danger', className)}
            {...props}
        />
    );
});

export const TextArea = forwardRef<
    HTMLTextAreaElement,
    ComponentPropsWithoutRef<'textarea'> & { invalid?: boolean }
>(function TextArea({ className, invalid, ...props }, ref) {
    return (
        <textarea
            ref={ref}
            aria-invalid={invalid === true ? true : undefined}
            className={cn(
                controlClass,
                'resize-y py-2.5 leading-[1.5]',
                invalid === true && 'border-danger',
                className,
            )}
            {...props}
        />
    );
});

export const Select = forwardRef<
    HTMLSelectElement,
    ComponentPropsWithoutRef<'select'> & { invalid?: boolean }
>(function Select({ className, invalid, ...props }, ref) {
    return (
        <select
            ref={ref}
            aria-invalid={invalid === true ? true : undefined}
            className={cn(inputClass, 'cursor-pointer pr-8', invalid === true && 'border-danger', className)}
            {...props}
        />
    );
});

/** A checkbox with its label beside it; the whole row is the target. */
export const CheckboxField = forwardRef<
    HTMLInputElement,
    ComponentPropsWithoutRef<'input'> & { label: string; hint?: string }
>(function CheckboxField({ label, hint, className, ...props }, ref) {
    const id = useId();
    const hintId = `${id}-hint`;
    return (
        <div className={cn('grid min-w-0 content-start gap-1', className)}>
            <div className="flex items-center gap-2">
                <input
                    ref={ref}
                    id={id}
                    type="checkbox"
                    aria-describedby={hint === undefined ? undefined : hintId}
                    className="h-4 w-4 shrink-0 cursor-pointer accent-accent"
                    {...props}
                />
                <label htmlFor={id} className="cursor-pointer font-display text-xs font-semibold text-fg">
                    {label}
                </label>
            </div>
            {hint !== undefined && (
                <p id={hintId} className="text-[11.5px] text-fg-2">
                    {hint}
                </p>
            )}
        </div>
    );
});

/**
 * The message of an error that belongs to a whole list rather than to one
 * of its rows ("add at least one", "each key must be different"). React
 * Hook Form files a field array's own error under `root`, while an error
 * the resolver reports on a plain array lands on `message`, so both are
 * read here and nothing depends on which shape a field happens to have.
 */
export function arrayErrorMessage(error: unknown): string | undefined {
    if (typeof error !== 'object' || error === null) {
        return undefined;
    }
    const record = error as { message?: unknown; root?: { message?: unknown } };
    if (typeof record.message === 'string') {
        return record.message;
    }
    return typeof record.root?.message === 'string' ? record.root.message : undefined;
}

/** The heading of a block inside a form, with the optional aside the design shows. */
export function FormSection({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
    return (
        <section className="grid gap-2.5">
            <h3 className="font-display text-xs font-semibold text-fg">
                {title}
                {note !== undefined && <span className="ml-1.5 font-normal text-fg-2">{note}</span>}
            </h3>
            {children}
        </section>
    );
}
