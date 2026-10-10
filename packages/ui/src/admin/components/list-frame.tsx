import { useId, type ReactNode } from 'react';
import { Pagination } from '@/shared/components';
import { pageRange } from '../lib';
import { adminStrings } from '../strings';
import { FilterSearch, FilterToolbar } from './filter-toolbar';

/*
 * The frame every admin list shares (05 Admin, turn 2: "All lists share the
 * DataTable pattern: filter chips, search, sortable columns, pagination").
 * The frame owns the chrome; the screen owns the columns, the query and the
 * dialogs. One heading per screen, so the host page's outline stays sane.
 */

export interface ListFrameProps {
    title: string;
    /** The count line beside the title, e.g. "17 total". */
    meta?: string;
    search?: {
        label: string;
        placeholder: string;
        value: string;
        onChange: (value: string) => void;
    };
    /** The primary action button of the screen. */
    action?: ReactNode;
    chips?: ReactNode;
    /** Rendered above the table: created-course confirmations, errors. */
    banner?: ReactNode;
    children: ReactNode;
    /** The explanatory sentence some lists carry under the table. */
    note?: string;
    page: number;
    perPage: number;
    total: number;
    shownCount: number;
    onPageChange: (page: number) => void;
}

export function ListFrame({
    title,
    meta,
    search,
    action,
    chips,
    banner,
    children,
    note,
    page,
    perPage,
    total,
    shownCount,
    onPageChange,
}: ListFrameProps) {
    const titleId = useId();
    const hasToolbar = search !== undefined || chips !== undefined || action !== undefined;
    const hasPageSummary = total > 0 || shownCount > 0 || page > 1;

    return (
        <section
            aria-labelledby={titleId}
            className="w-full min-w-0 max-w-full overflow-hidden rounded-lg border border-border bg-surface"
        >
            <div className="vy-list-header flex flex-wrap items-center gap-2.5 border-b border-border px-4 py-3">
                <h2 id={titleId} className="font-display text-sm font-bold text-fg">
                    {title}
                </h2>
                {meta !== undefined && <span className="text-[11.5px] text-fg-2">{meta}</span>}
            </div>
            {hasToolbar && (
                <div className="border-b border-border p-3">
                    <FilterToolbar
                        label={adminStrings.list.toolbar(title)}
                        search={
                            search === undefined ? undefined : (
                                <FilterSearch
                                    label={search.label}
                                    placeholder={search.placeholder}
                                    value={search.value}
                                    onValueChange={search.onChange}
                                />
                            )
                        }
                        filters={chips}
                        actions={action}
                    />
                </div>
            )}
            {banner !== undefined && <div className="border-b border-border px-4 py-3">{banner}</div>}
            {children}
            {hasPageSummary && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-2.5 text-[11.5px] text-fg-2">
                    <span aria-live="polite">{pageRange(page, perPage, total, shownCount)}</span>
                    <Pagination
                        page={page}
                        perPage={perPage}
                        total={total}
                        onPageChange={onPageChange}
                        label={adminStrings.list.pages(title)}
                    />
                </div>
            )}
            {note !== undefined && (
                <p className="border-t border-border px-4 py-3 text-[11.5px] text-fg-2">{note}</p>
            )}
        </section>
    );
}
