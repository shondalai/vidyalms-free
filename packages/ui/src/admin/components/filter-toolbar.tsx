import { useId, type ComponentProps, type ReactNode } from 'react';
import { ChevronDown, Search } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon, VisuallyHidden } from '@/shared/components';

export interface FilterToolbarProps extends Omit<ComponentProps<'section'>, 'children'> {
    /** Names this search and filter region for assistive technology. */
    label: string;
    /** Usually a FilterSearch; grows to use spare horizontal space. */
    search?: ReactNode;
    /** Selects, chips, date ranges, or other controls. */
    filters?: ReactNode;
    /** View switches, saved views, or other trailing actions. */
    actions?: ReactNode;
}

/**
 * Responsive list controls with one 40px control rhythm. The child groups
 * wrap independently, avoiding a fixed content width on wide CMS canvases.
 */
export function FilterToolbar({ label, search, filters, actions, className, ...props }: FilterToolbarProps) {
    return (
        <section
            aria-label={label}
            className={cn(
                'flex w-full min-w-0 flex-wrap items-center gap-2 rounded-lg border border-border bg-surface p-2',
                className,
            )}
            {...props}
        >
            {search !== undefined && <div className="min-w-[min(100%,16rem)] flex-[1_1_24rem]">{search}</div>}
            {filters !== undefined && (
                <div className="flex min-w-0 flex-wrap items-center gap-2 [&_button]:h-10 [&_input]:h-10 [&_select]:h-10">
                    {filters}
                </div>
            )}
            {actions !== undefined && (
                <div className="flex min-w-0 flex-wrap items-center gap-2 sm:ml-auto [&_a]:h-10 [&_button]:h-10 [&_input]:h-10 [&_select]:h-10">
                    {actions}
                </div>
            )}
        </section>
    );
}

export interface FilterSearchProps
    extends Omit<ComponentProps<'input'>, 'aria-label' | 'onChange' | 'type' | 'value'> {
    label: string;
    value: string;
    onValueChange: (value: string) => void;
}

export function FilterSearch({ label, value, onValueChange, className, ...props }: FilterSearchProps) {
    const id = useId();
    return (
        <div className="relative w-full">
            <VisuallyHidden>
                <label htmlFor={id}>{label}</label>
            </VisuallyHidden>
            <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-fg-2">
                <Icon icon={Search} size={17} />
            </span>
            <input
                id={id}
                type="search"
                value={value}
                onChange={(event) => onValueChange(event.target.value)}
                className={cn(
                    'h-10 w-full rounded border border-border bg-bg pl-9 pr-3 text-[13px] text-fg placeholder:text-fg-2',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

export interface FilterOption {
    value: string;
    label: string;
    disabled?: boolean;
}

export interface FilterSelectProps
    extends Omit<ComponentProps<'select'>, 'aria-label' | 'children' | 'onChange' | 'value'> {
    label: string;
    value: string;
    options: readonly FilterOption[];
    onValueChange: (value: string) => void;
}

export function FilterSelect({ label, value, options, onValueChange, className, ...props }: FilterSelectProps) {
    const id = useId();
    return (
        <div className="relative min-w-[9.5rem]">
            <VisuallyHidden>
                <label htmlFor={id}>{label}</label>
            </VisuallyHidden>
            <select
                id={id}
                value={value}
                onChange={(event) => onValueChange(event.target.value)}
                className={cn(
                    'h-10 w-full cursor-pointer appearance-none rounded border border-border bg-bg bg-none py-0 pl-3 pr-9 text-[13px] text-fg',
                    className,
                )}
                {...props}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value} disabled={option.disabled}>
                        {option.label}
                    </option>
                ))}
            </select>
            <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-fg-2">
                <Icon icon={ChevronDown} size={15} />
            </span>
        </div>
    );
}
