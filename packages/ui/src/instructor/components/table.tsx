import type { ReactNode } from 'react';
import { cn } from '@/shared/cn';

/*
 * The two list pieces the workbench adds on top of the shared DataTable and
 * Pagination (Foundations pieces 10 and 17, in src/shared/components): the
 * panel head every tab sits under, and the filter chips of artboard 3b.
 * Anything a table itself does belongs to the shared component, so the two
 * administrator apps stay one design.
 */

/** The filter chips above a table: one pressed at a time, all keyboard reachable. */
export function FilterChips({
    label,
    options,
    current,
    onSelect,
    className,
}: {
    label: string;
    options: readonly { id: string; label: string }[];
    current: string;
    onSelect: (id: string) => void;
    className?: string;
}) {
    return (
        <div role="group" aria-label={label} className={cn('flex flex-wrap gap-2', className)}>
            {options.map((option) => {
                const active = option.id === current;
                return (
                    <button
                        key={option.id}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onSelect(option.id)}
                        className={cn(
                            'cursor-pointer rounded-full px-2.5 py-1 text-[11.5px] font-semibold',
                            active ? 'bg-fg text-surface' : 'border border-border bg-surface text-fg-2 hover:text-fg',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

/** A panel head: title, meta line, actions on the right. */
export function PanelHeader({
    title,
    meta,
    actions,
    headingId,
    level: Heading = 'h3',
}: {
    title: string;
    meta?: ReactNode;
    actions?: ReactNode;
    headingId?: string;
    /** The course title above a tab panel is an h2, so panels inside it are h3. */
    level?: 'h2' | 'h3';
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-5 py-3.5">
            <Heading id={headingId} className="font-display text-sm font-bold text-fg">
                {title}
            </Heading>
            {meta !== undefined && <span className="text-[11.5px] text-fg-2">{meta}</span>}
            {actions !== undefined && <span className="ml-auto flex flex-wrap items-center gap-2">{actions}</span>}
        </div>
    );
}

/** The footer under a paged table: the range on the left, the pager on the right. */
export function ListFooter({ range, children }: { range: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-2.5 text-[11.5px] text-fg-2">
            <span>{range}</span>
            {children}
        </div>
    );
}
