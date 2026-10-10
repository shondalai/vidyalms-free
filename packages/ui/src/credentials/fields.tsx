import { useId, type ComponentProps } from 'react';
import { cn } from '@/shared/cn';

/*
 * The one control these two pages need: label above, hint below, hairline
 * box, 38px high, exactly as the Foundations artboard draws it.
 *
 * Its own copy rather than an import from the admin app, because these pages
 * ship as their own entry: reaching into another app's module would pull
 * that app's chunk into a page a signed-out stranger loads to check a code.
 */

const controlClass =
    'h-[38px] w-full rounded border border-border bg-surface px-3 text-[13px] text-fg placeholder:text-fg-2'
    + ' disabled:opacity-50 aria-[invalid=true]:border-danger';

export interface TextFieldProps extends Omit<ComponentProps<'input'>, 'id'> {
    label: string;
    hint?: string;
    error?: string;
    fieldClassName?: string;
}

export function TextField({ label, hint, error, className, fieldClassName, ...props }: TextFieldProps) {
    const id = useId();
    const describedBy = error !== undefined ? `${id}-error` : hint === undefined ? undefined : `${id}-hint`;
    return (
        <div className={cn('grid gap-1.5', fieldClassName)}>
            <label htmlFor={id} className="font-display text-xs font-semibold text-fg">
                {label}
            </label>
            <input
                id={id}
                aria-invalid={error !== undefined ? true : undefined}
                aria-describedby={describedBy}
                className={cn(controlClass, className)}
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
