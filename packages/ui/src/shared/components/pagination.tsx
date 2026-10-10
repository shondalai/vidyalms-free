import { cn } from '../cn';
import { sharedStrings } from '../strings';

/*
 * Foundations artboard, piece 17: buttons, not links, inside an app; the
 * current page is a button too, disabled and marked aria-current. One
 * navigation landmark per list, named by its label so a screen reader user
 * can tell two lists apart on one screen.
 *
 * Page numbers are windowed around the current page with an ellipsis, so a
 * hundred pages stay one row.
 */

export interface PaginationProps {
    page: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    /** Accessible name of the nav, e.g. "Courses pages". */
    label: string;
    className?: string;
}

const GAP = '…';

/**
 * First, last, and the two neighbours of the current page; gaps become one
 * ellipsis each. Always ascending, never duplicated.
 */
export function pageWindow(page: number, pageCount: number): (number | typeof GAP)[] {
    const wanted = new Set<number>([1, pageCount, page - 1, page, page + 1]);
    const pages = [...wanted].filter((candidate) => candidate >= 1 && candidate <= pageCount).sort((a, b) => a - b);
    const out: (number | typeof GAP)[] = [];
    let previous = 0;
    for (const current of pages) {
        if (previous !== 0 && current - previous > 1) {
            out.push(GAP);
        }
        out.push(current);
        previous = current;
    }
    return out;
}

export function Pagination({ page, perPage, total, onPageChange, label, className }: PaginationProps) {
    const pageCount = Math.max(1, Math.ceil(total / Math.max(1, perPage)));
    /* A bookmarked/filter-changed page can outlive its final row. Keep a
       route back to page one instead of stranding an empty table with no
       pagination controls. */
    if (pageCount <= 1 && page <= 1) {
        return null;
    }
    const stepClass =
        'h-9 cursor-pointer rounded-btn border border-border bg-surface px-3 font-display text-xs font-semibold text-fg transition-colors duration-1 hover:bg-surface-2 disabled:cursor-default disabled:text-fg-2 disabled:opacity-50 disabled:hover:bg-surface';

    return (
        <nav aria-label={label} className={cn('flex flex-wrap items-center justify-end gap-1 text-[12.5px]', className)}>
            <button type="button" className={stepClass} disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
                {sharedStrings.pagination.previous}
            </button>
            {pageWindow(page, pageCount).map((entry, index) =>
                entry === GAP ? (
                    <span key={`gap-${index}`} aria-hidden="true" className="px-1 text-fg-2">
                        {GAP}
                    </span>
                ) : (
                    <button
                        key={entry}
                        type="button"
                        aria-label={sharedStrings.pagination.page(entry)}
                        aria-current={entry === page ? 'page' : undefined}
                        disabled={entry === page}
                        onClick={() => onPageChange(entry)}
                        className={cn(
                            'inline-flex h-9 min-w-9 items-center justify-center rounded-btn border px-2 font-display text-xs font-semibold transition-colors duration-1',
                            entry === page
                                ? 'border-accent bg-accent text-accent-fg'
                                : 'cursor-pointer border-transparent text-fg hover:border-border hover:bg-surface-2',
                        )}
                    >
                        {entry}
                    </button>
                ),
            )}
            <button
                type="button"
                className={stepClass}
                disabled={page >= pageCount}
                onClick={() => onPageChange(page + 1)}
            >
                {sharedStrings.pagination.next}
            </button>
        </nav>
    );
}
