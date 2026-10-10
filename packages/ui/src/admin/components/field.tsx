import { forwardRef, useId, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { cn } from '@/shared/cn';
import { VisuallyHidden } from '@/shared/components/visually-hidden';

/*
 * Foundations artboard, piece 11: label above, error below, hairline box,
 * 38px control. The label is always rendered (visually hidden where the
 * surrounding heading already names the control, as on a list search box),
 * because a placeholder is not a label.
 */

const controlClass =
    'h-[38px] w-full rounded border border-border bg-surface px-3 text-[13px] text-fg placeholder:text-fg-2 disabled:opacity-50 aria-[invalid=true]:border-danger';

interface FieldShellProps {
    id: string;
    label: string;
    labelHidden?: boolean;
    hint?: string;
    error?: string;
    className?: string;
    children: ReactNode;
}

function FieldShell({ id, label, labelHidden, hint, error, className, children }: FieldShellProps) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            {labelHidden === true ? (
                <VisuallyHidden>
                    <label htmlFor={id}>{label}</label>
                </VisuallyHidden>
            ) : (
                <label htmlFor={id} className="font-display text-xs font-semibold text-fg">
                    {label}
                </label>
            )}
            {children}
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

function describedBy(id: string, hint: string | undefined, error: string | undefined): string | undefined {
    if (error !== undefined) {
        return `${id}-error`;
    }
    return hint === undefined ? undefined : `${id}-hint`;
}

export interface TextFieldProps extends Omit<ComponentPropsWithoutRef<'input'>, 'id'> {
    label: string;
    labelHidden?: boolean;
    hint?: string;
    error?: string;
    fieldClassName?: string;
}

export const TextField = forwardRef<HTMLInputElement, TextFieldProps>(function TextField({
    label,
    labelHidden,
    hint,
    error,
    className,
    fieldClassName,
    ...props
}, ref) {
    const id = useId();
    return (
        <FieldShell
            id={id}
            label={label}
            labelHidden={labelHidden}
            hint={hint}
            error={error}
            className={fieldClassName}
        >
            <input
                ref={ref}
                id={id}
                aria-invalid={error !== undefined ? true : undefined}
                aria-describedby={describedBy(id, hint, error)}
                className={cn(controlClass, className)}
                {...props}
            />
        </FieldShell>
    );
});

export interface SelectFieldProps extends Omit<ComponentPropsWithoutRef<'select'>, 'id'> {
    label: string;
    labelHidden?: boolean;
    hint?: string;
    error?: string;
    fieldClassName?: string;
}

export const SelectField = forwardRef<HTMLSelectElement, SelectFieldProps>(function SelectField({
    label,
    labelHidden,
    hint,
    error,
    className,
    fieldClassName,
    children,
    ...props
}, ref) {
    const id = useId();
    return (
        <FieldShell
            id={id}
            label={label}
            labelHidden={labelHidden}
            hint={hint}
            error={error}
            className={fieldClassName}
        >
            <select
                ref={ref}
                id={id}
                aria-invalid={error !== undefined ? true : undefined}
                aria-describedby={describedBy(id, hint, error)}
                className={cn(controlClass, 'cursor-pointer', className)}
                {...props}
            >
                {children}
            </select>
        </FieldShell>
    );
});

export interface RadioGroupProps {
    legend: string;
    name: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    className?: string;
}

/** A real fieldset: the legend names the group for assistive technology. */
export function RadioGroup({ legend, name, value, options, onChange, className }: RadioGroupProps) {
    return (
        <fieldset className={cn('grid gap-1.5', className)}>
            <legend className="mb-1.5 font-display text-xs font-semibold text-fg">{legend}</legend>
            <div className="flex flex-wrap gap-4">
                {options.map((option) => (
                    <label key={option.value} className="flex cursor-pointer items-center gap-2 text-[13px] text-fg">
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            onChange={() => onChange(option.value)}
                            className="h-4 w-4 cursor-pointer accent-accent"
                        />
                        {option.label}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}
