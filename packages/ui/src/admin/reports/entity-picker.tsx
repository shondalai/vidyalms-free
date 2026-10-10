import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Search, ChevronDown, X } from 'lucide-react';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import {
    Button,
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
    ErrorState,
    Pagination,
    Skeleton,
} from '@/shared/components';
import { useDebounced } from '../hooks';
import { reportWorkspaceStrings as s } from './strings';
import { reportKeys } from './api';

export function EntityPicker({
    kind,
    value,
    label,
    onChange,
    required = false,
}: {
    kind: 'course' | 'organisation';
    value: number | null;
    label?: string;
    onChange: (id: number | null) => void;
    required?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const debounced = useDebounced(search);
    const client = useApiClient();
    const title = label ?? (kind === 'course' ? s.course : s.organisation);
    const detail = useQuery({
        queryKey: [...reportKeys, 'selection', kind, value],
        enabled: value !== null,
        queryFn: async ({ signal }) =>
            kind === 'course'
                ? must((await client.GET('/manage/courses/{id}', { params: { path: { id: value! } }, signal })).data)
                      .title
                : must(
                      (await client.GET('/manage/organisations/{id}', { params: { path: { id: value! } }, signal }))
                          .data,
                  ).name,
    });
    const query = useQuery({
        queryKey: [...reportKeys, 'picker', kind, debounced, page],
        enabled: open,
        queryFn: async ({ signal }) => {
            if (kind === 'course') {
                const result = must(
                    (
                        await client.GET('/manage/courses', {
                            params: { query: { q: debounced, page, per_page: 20, sort: 'title', order: 'asc' } },
                            signal,
                        })
                    ).data,
                );
                return { items: result.items.map((item) => ({ id: item.id, label: item.title })), total: result.total };
            }
            const result = must(
                (
                    await client.GET('/manage/organisations', {
                        params: { query: { search: debounced, page, per_page: 20 } },
                        signal,
                    })
                ).data,
            );
            return { items: result.items.map((item) => ({ id: item.id, label: item.name })), total: result.total };
        },
    });
    return (
        <div className="vy-report-field">
            <span>
                {title}
                {required && <span className="vy-report-required"> *</span>}
            </span>
            <div className="vy-report-picker-value">
                <button
                    type="button"
                    aria-label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: title, p1: value === null ? (kind === 'course' ? s.chooseCourse : s.allOrganisations) : (detail.data ?? `${title} ${value}`) })}
                    className="vy-report-picker-trigger"
                    onClick={() => setOpen(true)}
                >
                    <span>
                        {value === null
                            ? kind === 'course'
                                ? s.chooseCourse
                                : s.allOrganisations
                            : (detail.data ?? `${title} #${value}`)}
                    </span>
                    <ChevronDown size={14} />
                </button>
                {value !== null && (
                    <button
                        type="button"
                        className="vy-report-clear"
                        aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: s.clear, p1: title })}
                        onClick={() => onChange(null)}
                    >
                        <X size={14} />
                    </button>
                )}
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="vy-report-dialog">
                    <DialogTitle>{kind === 'course' ? s.chooseCourse : s.chooseOrganisation}</DialogTitle>
                    <DialogDescription>{s.selectHint}</DialogDescription>
                    <label className="vy-report-search">
                        <Search size={16} />
                        <input
                            type="search"
                            aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: s.search, p1: title.toLowerCase() })}
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                    </label>
                    {query.isError ? (
                        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
                    ) : query.isPending ? (
                        <Skeleton className="h-40 w-full" />
                    ) : (
                        <ul className="vy-report-choice-list">
                            {query.data.items.map((item) => (
                                <li key={item.id}>
                                    <Button
                                        variant="ghost"
                                        disabled={query.isFetching || debounced !== search}
                                        aria-pressed={item.id === value}
                                        onClick={() => {
                                            onChange(item.id);
                                            setOpen(false);
                                        }}
                                    >
                                        {item.label}
                                    </Button>
                                </li>
                            ))}
                            {!query.data.items.length && <li>{s.noMatches}</li>}
                        </ul>
                    )}
                    <Pagination
                        label={uiText("vidyalms.ui.78e681cd67d0e046", "{p0} pages", { p0: title })}
                        page={page}
                        perPage={20}
                        total={query.data?.total ?? 0}
                        onPageChange={setPage}
                    />
                </DialogContent>
            </Dialog>
        </div>
    );
}
