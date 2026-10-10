import { cn } from '@/shared/cn';

/*
 * The filter row of every admin list (05 Admin, turn 2). Chips are a radio
 * group in spirit: exactly one is active, so they are rendered as a group
 * of buttons with aria-pressed and one accessible name each. A count rides
 * in the name when the list has one, because a chip reading "Published 14"
 * to a screen reader is the same information the sighted reader gets.
 */

export interface FilterChip {
    /** Stable identity, also the value handed back on click. */
    value: string;
    label: string;
    /** Omitted when the contract gives no facet count for this filter. */
    count?: number;
}

export interface FilterChipsProps {
    /** Names the group, e.g. "Filters". */
    label: string;
    chips: FilterChip[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
}

export function FilterChips({ label, chips, value, onChange, className }: FilterChipsProps) {
    return (
        <div role="group" aria-label={label} className={cn('flex flex-wrap gap-1.5', className)}>
            {chips.map((chip) => {
                const active = chip.value === value;
                return (
                    <button
                        key={chip.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(chip.value)}
                        className={cn(
                            'min-h-10 cursor-pointer rounded-btn px-3 font-display text-[11.5px] font-semibold transition-colors duration-1',
                            active
                                ? 'bg-fg text-bg'
                                : 'border border-border text-fg-2 hover:bg-surface-2 hover:text-fg',
                        )}
                    >
                        {chip.label}
                        {chip.count !== undefined && (
                            <span className={cn('ml-1', active ? 'opacity-80' : 'text-fg-2')}>({chip.count})</span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
