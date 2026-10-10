import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { ExternalLink, FolderTree } from 'lucide-react';
import type { CourseCategory } from '@vidyalms/api-sdk';
import { ProblemError } from '@/shared/api';
import { cn } from '@/shared/cn';
import { useCourseCategories, useCourseCategory } from '@/shared/manage';
import { sharedStrings as strings } from '@/shared/strings';
import { Badge } from './badge';
import { Button, buttonVariants } from './button';
import { ErrorState } from './error-state';
import { Icon } from './icon';
import { Pagination } from './pagination';
import { Spinner } from './spinner';
import { VisuallyHidden } from './visually-hidden';

const controlClass =
    'h-[38px] w-full rounded border border-border bg-surface px-3 text-[13px] text-fg placeholder:text-fg-2/70 disabled:opacity-60';

function useDebouncedValue<T>(value: T, delay = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delay);
        return () => window.clearTimeout(timer);
    }, [delay, value]);
    return debounced;
}

function CategoryState({ category }: { category: CourseCategory }) {
    if (category.state === 'published') {
        return null;
    }
    return (
        <Badge variant="neutral" glyph={null}>
            {strings.courseCategory.states[category.state]}
        </Badge>
    );
}

function usableUrl(value: string | null | undefined): value is string {
    return typeof value === 'string' && value.trim() !== '';
}

function NativeCategoryLink({
    href,
    disabled,
    children,
}: {
    href: string;
    disabled: boolean;
    children: ReactNode;
}) {
    return (
        <a
            className={cn(
                buttonVariants({ variant: 'ghost', size: 'sm' }),
                disabled && 'pointer-events-none opacity-50',
            )}
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            aria-disabled={disabled || undefined}
            tabIndex={disabled ? -1 : undefined}
            onClick={(event) => {
                if (disabled) {
                    event.preventDefault();
                }
            }}
        >
            {children}
            <Icon icon={ExternalLink} size={12} />
            <VisuallyHidden>{strings.courseCategory.opensInNewTab}</VisuallyHidden>
        </a>
    );
}

export interface CourseCategoryPickerProps {
    label: string;
    hint?: string;
    error?: string;
    value: number | null;
    onChange: (id: number | null) => void;
    disabled?: boolean;
    className?: string;
}

/**
 * A bounded, server-searched category chooser shared by both admin mounts.
 * The stored category is fetched by id, so it stays understandable even
 * when it is not on the current page or has since been removed in the CMS.
 */
export function CourseCategoryPicker({
    label,
    hint,
    error,
    value,
    onChange,
    disabled = false,
    className,
}: CourseCategoryPickerProps) {
    const [choosing, setChoosing] = useState(false);
    const [term, setTerm] = useState('');
    const [page, setPage] = useState(1);
    const searchRef = useRef<HTMLInputElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const restoreFocus = useRef(false);
    const id = useId();
    const normalizedTerm = term.trim();
    const query = useDebouncedValue(normalizedTerm);
    const isDebouncing = normalizedTerm !== query;
    const categories = useCourseCategories({
        ...(query === '' ? {} : { q: query }),
        sort: 'name',
        order: 'asc',
        page,
        per_page: 20,
    });
    const selected = useCourseCategory(value);
    const selectedMissing =
        value !== null && selected.error instanceof ProblemError && selected.error.status === 404;
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const labelId = `${id}-label`;
    const describedBy = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
        .filter((entry): entry is string => entry !== null)
        .join(' ');
    const categoryRows = categories.data?.items ?? [];
    const categoryRowsUnavailable =
        isDebouncing || categories.isFetching || categories.isPlaceholderData || categories.isError;
    const closeChooser = () => {
        restoreFocus.current = true;
        setChoosing(false);
        setTerm('');
        setPage(1);
    };
    const choose = (category: CourseCategory) => {
        onChange(category.id);
        closeChooser();
    };

    useEffect(() => {
        if (choosing) {
            searchRef.current?.focus();
            return;
        }

        if (restoreFocus.current) {
            restoreFocus.current = false;
            triggerRef.current?.focus();
        }
    }, [choosing]);

    return (
        <div
            role="group"
            aria-labelledby={labelId}
            aria-describedby={describedBy === '' ? undefined : describedBy}
            aria-invalid={error === undefined ? undefined : true}
            className={cn('grid gap-1.5', className)}
        >
            <span id={labelId} className="font-display text-xs font-semibold text-fg">{label}</span>

            {!choosing && value === null ? (
                <div className="flex flex-wrap items-center gap-2 rounded border border-border bg-surface-2 px-3 py-2">
                    <Icon icon={FolderTree} size={15} className="text-fg-2" />
                    <span className="mr-auto text-[13px] text-fg-2">{strings.courseCategory.none}</span>
                    <button
                        ref={triggerRef}
                        type="button"
                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        disabled={disabled}
                        onClick={() => setChoosing(true)}
                    >
                        {strings.courseCategory.choose}
                    </button>
                    {usableUrl(categories.data?.management_url) && (
                        <NativeCategoryLink href={categories.data.management_url} disabled={disabled}>
                            {strings.courseCategory.manage}
                        </NativeCategoryLink>
                    )}
                </div>
            ) : !choosing && value !== null ? (
                <div className="grid gap-2 rounded border border-border bg-surface-2 p-3">
                    {selected.isPending ? (
                        <Spinner label={strings.courseCategory.searching} />
                    ) : selectedMissing ? (
                        <p role="alert" className="text-[12.5px] text-warning">
                            {strings.courseCategory.missing(value)}
                        </p>
                    ) : selected.isError ? (
                        <ErrorState
                            error={selected.error}
                            title={strings.courseCategory.unavailable}
                            onRetry={() => void selected.refetch()}
                        />
                    ) : (
                        <div className="flex min-w-0 items-center gap-2">
                            <Icon icon={FolderTree} size={15} className="shrink-0 text-fg-2" />
                            <span
                                className="min-w-0 flex-1 break-words text-[13px] font-medium leading-5 text-fg"
                                title={selected.data.label}
                            >
                                {selected.data.label}
                            </span>
                            <CategoryState category={selected.data} />
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            ref={triggerRef}
                            type="button"
                            className={buttonVariants({ variant: 'outline', size: 'sm' })}
                            disabled={disabled}
                            onClick={() => setChoosing(true)}
                        >
                            {strings.courseCategory.change}
                        </button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={disabled}
                            onClick={() => {
                                onChange(null);
                                setChoosing(false);
                            }}
                        >
                            {strings.courseCategory.clear}
                        </Button>
                        {usableUrl(selected.data?.edit_url) && (
                            <NativeCategoryLink href={selected.data.edit_url} disabled={disabled}>
                                {strings.courseCategory.edit}
                            </NativeCategoryLink>
                        )}
                        {usableUrl(categories.data?.management_url) && (
                            <NativeCategoryLink href={categories.data.management_url} disabled={disabled}>
                                {strings.courseCategory.manage}
                            </NativeCategoryLink>
                        )}
                    </div>
                </div>
            ) : (
                <div
                    className="grid gap-2"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            closeChooser();
                        }
                    }}
                >
                    <input
                        ref={searchRef}
                        type="search"
                        autoComplete="off"
                        className={cn(controlClass, error !== undefined && 'border-danger')}
                        aria-label={label}
                        aria-describedby={describedBy === '' ? undefined : describedBy}
                        aria-invalid={error === undefined ? undefined : true}
                        placeholder={strings.courseCategory.searchPlaceholder}
                        value={term}
                        disabled={disabled}
                        onChange={(event) => {
                            setTerm(event.target.value);
                            setPage(1);
                        }}
                    />
                    <div aria-live="polite" className="min-h-5">
                        {(isDebouncing || categories.isFetching) && <Spinner label={strings.courseCategory.searching} />}
                        {categories.isError && (
                            <ErrorState
                                error={categories.error}
                                title={strings.courseCategory.unavailable}
                                onRetry={() => void categories.refetch()}
                            />
                        )}
                        {!isDebouncing && categories.isSuccess && categoryRows.length === 0 && (
                            <p className="text-[11.5px] text-fg-2">{strings.courseCategory.noMatches}</p>
                        )}
                    </div>
                    {categoryRows.length > 0 && (
                        <ul
                            aria-label={strings.courseCategory.results}
                            aria-busy={categoryRowsUnavailable}
                            className="grid max-h-52 gap-1 overflow-y-auto rounded border border-border bg-surface p-1"
                        >
                            {categoryRows.map((category) => {
                                const unavailable = categoryRowsUnavailable || category.state === 'trashed';

                                return (
                                    <li key={category.id}>
                                        <button
                                            type="button"
                                            disabled={disabled || unavailable}
                                            className="flex w-full cursor-pointer items-start gap-2 rounded px-2.5 py-2 text-left text-[12.5px] hover:bg-surface-2 disabled:cursor-default disabled:opacity-60"
                                            onClick={() => choose(category)}
                                        >
                                            <span
                                                className="min-w-0 flex-1 break-words leading-5 text-fg"
                                                title={category.label}
                                            >
                                                {category.label}
                                            </span>
                                            <CategoryState category={category} />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {categories.data !== undefined && !isDebouncing && (
                        <Pagination
                            page={page}
                            perPage={categories.data.per_page}
                            total={categories.data.total}
                            onPageChange={setPage}
                            label={strings.courseCategory.pages}
                        />
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="ghost" size="sm" onClick={closeChooser}>
                            {strings.close}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={disabled}
                            onClick={() => {
                                onChange(null);
                                closeChooser();
                            }}
                        >
                            {strings.courseCategory.none}
                        </Button>
                        {usableUrl(categories.data?.management_url) && (
                            <NativeCategoryLink href={categories.data.management_url} disabled={disabled}>
                                {strings.courseCategory.manage}
                            </NativeCategoryLink>
                        )}
                    </div>
                </div>
            )}

            {hint !== undefined && error === undefined && (
                <p id={hintId} className="text-[11.5px] text-fg-2">{hint}</p>
            )}
            {error !== undefined && (
                <p id={errorId} className="text-[11.5px] font-medium text-danger">{error}</p>
            )}
        </div>
    );
}
