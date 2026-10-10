import { translate as uiText } from '@/shared/i18n';
import { useEffect, useRef, type ReactNode } from 'react';
import { cn } from '../cn';
import { Skeleton } from './skeleton';
import { VisuallyHidden } from './visually-hidden';

/*
 * Foundations artboard, piece 10: sortable header, row hover, 13px body,
 * sticky header. Every admin list is this component (05 Admin, turn 2:
 * "All lists share the DataTable pattern").
 *
 * It is a real <table>: header cells carry aria-sort and the sort control
 * is a button inside the cell, so a screen reader announces both the column
 * and its direction. Sorting is never done here. The caller sends "sort"
 * and "order" to the server and feeds the answer back, because the contract
 * pages before it sorts and a client-side sort would reorder one page only.
 */

export type SortOrder = 'asc' | 'desc';

export interface DataTableSort {
    /** The column's sortKey, exactly as the contract spells it. */
    key: string;
    order: SortOrder;
}

type FixedColumnSizing = {
    /** Fixed column width. Pixel-only so invalid grid units cannot reach a <col>. */
    width: `${number}px`;
    weight?: never;
    minWidth?: never;
};

type FluidColumnSizing = {
    width?: undefined;
    /** Share of the space left after fixed columns; defaults to 1. */
    weight?: number;
    /** Smallest useful width in pixels before the table scrolls; defaults to 180. */
    minWidth?: number;
};

export type DataTableColumn<Row> = {
    /** Stable identity of the column; also the React key. */
    key: string;
    header: string;
    /** The contract's sort value. A column without one does not sort. */
    sortKey?: string;
    align?: 'start' | 'end';
    /** Action columns: the header names the column for assistive tech only. */
    hideHeader?: boolean;
    cell: (row: Row) => ReactNode;
} & (FixedColumnSizing | FluidColumnSizing);

export interface DataTableProps<Row> {
    /** The table's accessible name, e.g. "Courses". */
    caption: string;
    columns: DataTableColumn<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    sort?: DataTableSort;
    /** Called with the new sort; the caller refetches with it. */
    onSortChange?: (sort: DataTableSort) => void;
    /** Skeleton rows instead of content, shape-true per piece 14. */
    loading?: boolean;
    skeletonRows?: number;
    /** Shown in place of the rows when there are none and nothing is loading. */
    empty?: ReactNode;
    /**
     * Optional, controlled row selection. The header control selects only
     * the rows on the rendered server page; keys selected on another page
     * are left intact so the owning screen can deliberately keep or clear
     * them when its route changes.
     */
    selection?: {
        selectedKeys: ReadonlySet<string | number>;
        onSelectionChange: (keys: Set<string | number>) => void;
        rowLabel: (row: Row) => string;
        selectAllLabel?: string;
        selectRowLabel?: (row: Row) => string;
    };
    className?: string;
}

const SORT_GLYPH: Record<SortOrder, string> = { asc: '↑', desc: '↓' };
const DEFAULT_FLUID_MIN_WIDTH = 180;

function positiveNumber(value: number | undefined, fallback: number): number {
    return value !== undefined && Number.isFinite(value) && value > 0 ? value : fallback;
}

function fixedPixels<Row>(column: DataTableColumn<Row>): number {
    return column.width === undefined ? 0 : Number.parseFloat(column.width);
}

function fluidWeight<Row>(column: DataTableColumn<Row>): number {
    return column.width === undefined ? positiveNumber(column.weight, 1) : 0;
}

function compactNumber(value: number): string {
    return value.toFixed(6).replace(/\.?0+$/, '');
}

/**
 * HTML table columns do not understand CSS Grid's `fr` unit. A fluid column
 * instead receives its weighted share of the width left after fixed columns:
 * `ratio * (100% - fixedPixels)`, expressed with widely supported calc terms.
 */
function resolvedColumnWidth<Row>(
    column: DataTableColumn<Row>,
    fixedTotal: number,
    weightTotal: number,
): string | undefined {
    if (column.width !== undefined) {
        return column.width;
    }
    if (weightTotal <= 0) {
        return undefined;
    }

    const ratio = fluidWeight(column) / weightTotal;
    const percent = compactNumber(ratio * 100);
    const fixedShare = compactNumber(ratio * fixedTotal);

    return fixedTotal === 0 ? `${percent}%` : `calc(${percent}% - ${fixedShare}px)`;
}

/** Clicking the active column flips it; clicking another starts ascending. */
function nextSort(key: string, current: DataTableSort | undefined): DataTableSort {
    if (current !== undefined && current.key === key) {
        return { key, order: current.order === 'asc' ? 'desc' : 'asc' };
    }
    return { key, order: 'asc' };
}

function ariaSortOf(sortKey: string | undefined, sort: DataTableSort | undefined): 'ascending' | 'descending' | 'none' | undefined {
    if (sortKey === undefined) {
        return undefined;
    }
    if (sort === undefined || sort.key !== sortKey) {
        return 'none';
    }
    return sort.order === 'asc' ? 'ascending' : 'descending';
}

function SelectionCheckbox({
    checked,
    mixed = false,
    label,
    onChange,
}: {
    checked: boolean;
    mixed?: boolean;
    label: string;
    onChange: () => void;
}) {
    const ref = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (ref.current !== null) {
            ref.current.indeterminate = mixed;
        }
    }, [mixed]);

    return (
        <input
            ref={ref}
            type="checkbox"
            checked={checked}
            aria-label={label}
            onChange={onChange}
            className="h-4 w-4 cursor-pointer accent-accent"
        />
    );
}

export function DataTable<Row>({
    caption,
    columns,
    rows,
    rowKey,
    sort,
    onSortChange,
    loading = false,
    skeletonRows = 5,
    empty,
    selection,
    className,
}: DataTableProps<Row>) {
    const sortable = onSortChange !== undefined;
    const showEmpty = !loading && rows.length === 0 && empty !== undefined;
    const selectionWidth = selection === undefined ? 0 : 44;
    const fixedTotal = columns.reduce((total, column) => total + fixedPixels(column), selectionWidth);
    const weightTotal = columns.reduce((total, column) => total + fluidWeight(column), 0);
    const minimumFluidPool = columns.reduce((largestPool, column) => {
        if (column.width !== undefined || weightTotal <= 0) {
            return largestPool;
        }

        const ratio = fluidWeight(column) / weightTotal;
        const columnMinimum = positiveNumber(column.minWidth, DEFAULT_FLUID_MIN_WIDTH);
        return Math.max(largestPool, columnMinimum / ratio);
    }, 0);
    const minimumTableWidth = Math.ceil(fixedTotal + minimumFluidPool);
    const visibleKeys = rows.map((row) => rowKey(row));
    const selectedOnPage =
        selection === undefined ? 0 : visibleKeys.filter((key) => selection.selectedKeys.has(key)).length;
    const allOnPage = visibleKeys.length > 0 && selectedOnPage === visibleKeys.length;
    const someOnPage = selectedOnPage > 0 && !allOnPage;

    const togglePage = () => {
        if (selection === undefined) {
            return;
        }
        const next = new Set(selection.selectedKeys);
        for (const key of visibleKeys) {
            if (allOnPage) {
                next.delete(key);
            } else {
                next.add(key);
            }
        }
        selection.onSelectionChange(next);
    };

    const toggleRow = (row: Row) => {
        if (selection === undefined) {
            return;
        }
        const key = rowKey(row);
        const next = new Set(selection.selectedKeys);
        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }
        selection.onSelectionChange(next);
    };

    return (
        <div className={cn('w-full min-w-0 max-w-full overflow-x-auto', className)}>
            <table
                className="w-full table-fixed border-collapse text-left text-[12.5px]"
                aria-busy={loading}
                style={minimumTableWidth > 0 ? { minWidth: `${minimumTableWidth}px` } : undefined}
            >
                <caption className="text-left">
                    <VisuallyHidden>{caption}</VisuallyHidden>
                </caption>
                <colgroup>
                    {selection !== undefined && <col style={{ width: `${selectionWidth}px` }} />}
                    {columns.map((column) => (
                        <col
                            key={column.key}
                            style={{ width: resolvedColumnWidth(column, fixedTotal, weightTotal) }}
                        />
                    ))}
                </colgroup>
                <thead>
                    <tr>
                        {selection !== undefined && (
                            <th scope="col" className="sticky top-0 z-10 bg-surface-2 px-2 py-2 text-center">
                                <SelectionCheckbox
                                    checked={allOnPage}
                                    mixed={someOnPage}
                                    label={selection.selectAllLabel ?? uiText("vidyalms.ui.bf140dd1d1d746eb", "Select every row on this page")}
                                    onChange={togglePage}
                                />
                            </th>
                        )}
                        {columns.map((column) => {
                            const sortKey = column.sortKey;
                            const active = sort !== undefined && sort.key === sortKey;
                            const canSort = sortable && sortKey !== undefined;
                            return (
                                <th
                                    key={column.key}
                                    scope="col"
                                    aria-sort={ariaSortOf(sortKey, sort)}
                                    className={cn(
                                        'sticky top-0 z-10 bg-surface-2 px-2.5 py-2 font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2 first:pl-4 last:pr-4',
                                        column.align === 'end' && 'text-right',
                                    )}
                                >
                                    {column.hideHeader === true ? (
                                        <VisuallyHidden>{column.header}</VisuallyHidden>
                                    ) : canSort ? (
                                        <button
                                            type="button"
                                            onClick={() => onSortChange(nextSort(sortKey, sort))}
                                            className="inline-flex cursor-pointer items-center gap-1 uppercase text-fg-2 hover:text-fg"
                                        >
                                            {column.header}
                                            <span aria-hidden="true" className={active ? '' : 'opacity-0'}>
                                                {SORT_GLYPH[active ? sort.order : 'asc']}
                                            </span>
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody>
                    {loading &&
                        Array.from({ length: skeletonRows }, (_unused, index) => (
                            <tr key={`skeleton-${index}`} className="border-t border-border">
                                {selection !== undefined && <td className="px-2 py-3" />}
                                {columns.map((column) => (
                                    <td key={column.key} className="px-2.5 py-3 first:pl-4 last:pr-4">
                                        <Skeleton className="h-3.5 w-4/5" />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    {!loading &&
                        rows.map((row) => (
                            <tr
                                key={rowKey(row)}
                                aria-selected={selection?.selectedKeys.has(rowKey(row)) || undefined}
                                className="border-t border-border align-middle even:bg-bg hover:bg-surface-2"
                            >
                                {selection !== undefined && (
                                    <td className="px-2 py-2.5 text-center">
                                        <SelectionCheckbox
                                            checked={selection.selectedKeys.has(rowKey(row))}
                                            label={
                                                selection.selectRowLabel?.(row) ??
                                                uiText("vidyalms.ui.8d29790dbe82cb4e", "Select {p0}", { p0: selection.rowLabel(row) })
                                            }
                                            onChange={() => toggleRow(row)}
                                        />
                                    </td>
                                )}
                                {columns.map((column) => (
                                    <td
                                        key={column.key}
                                        className={cn(
                                            'px-2.5 py-2.5 first:pl-4 last:pr-4',
                                            column.align === 'end' && 'text-right',
                                        )}
                                    >
                                        {column.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    {showEmpty && (
                        <tr className="border-t border-border">
                            <td colSpan={columns.length + (selection === undefined ? 0 : 1)} className="p-4">
                                {empty}
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
