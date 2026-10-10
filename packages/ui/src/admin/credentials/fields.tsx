import { useId, type ComponentProps } from 'react';
import { cn } from '@/shared/cn';

/*
 * The two controls the credential screens need that the admin app's own
 * field module does not carry: a checkbox and a multi-line text area.
 *
 * They live here rather than being added to admin/components/field.tsx so
 * that one feature owns one file, which is what keeps two agents working on
 * the same package out of each other's diffs. Same shape as everything else
 * in the Foundations artboard: label above, hint below, hairline box.
 */

const controlClass =
    'w-full rounded border border-border bg-surface px-3 py-2 text-[13px] text-fg placeholder:text-fg-2'
    + ' disabled:opacity-50 aria-[invalid=true]:border-danger';

export function CheckboxField({
    label,
    hint,
    className,
    ...props
}: ComponentProps<'input'> & { label: string; hint?: string }) {
    const id = useId();
    const hintId = `${id}-hint`;
    return (
        <div className={cn('grid gap-1', className)}>
            <div className="flex items-center gap-2">
                <input
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
}

export function TextAreaField({
    label,
    hint,
    error,
    className,
    fieldClassName,
    ...props
}: Omit<ComponentProps<'textarea'>, 'id'> & {
    label: string;
    hint?: string;
    error?: string;
    fieldClassName?: string;
}) {
    const id = useId();
    const describedBy = error !== undefined ? `${id}-error` : hint === undefined ? undefined : `${id}-hint`;
    return (
        <div className={cn('grid gap-1.5', fieldClassName)}>
            <label htmlFor={id} className="font-display text-xs font-semibold text-fg">
                {label}
            </label>
            <textarea
                id={id}
                rows={props.rows ?? 6}
                aria-invalid={error !== undefined ? true : undefined}
                aria-describedby={describedBy}
                className={cn(controlClass, 'font-mono text-[12px]', className)}
                {...props}
            />
            {hint !== undefined && error === undefined && (
                <p id={`${id}-hint`} className="text-[11.5px] text-fg-2">
                    {hint}
                </p>
            )}
            {error !== undefined && (
                <p id={`${id}-error`} className="text-[11.5px] text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
