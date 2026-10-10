import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Button, Pagination, Spinner } from '@/shared/components';
import {
    useCourseCategories,
    useCourseCategory,
    useManagedCourse,
    useManagedCourses,
    useUserSearch,
} from '@/shared/manage';
import { useDebounced } from '../hooks';
import { BankInput } from './fields';

export function OwnerPicker({
    kind,
    value,
    label,
    onChange,
    disabled = false,
}: {
    kind: 'course' | 'category';
    value: number | null;
    label: string;
    onChange: (id: number, label: string) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const search = useDebounced(q).trim();
    const selectedCourse = useManagedCourse(kind === 'course' ? value : null);
    const selectedCategory = useCourseCategory(kind === 'category' ? value : null);
    const courses = useManagedCourses(
        { q: search || undefined, sort: 'title', order: 'asc', page, per_page: 20 },
        { enabled: open && kind === 'course' },
    );
    const categories = useCourseCategories(
        { q: search || undefined, sort: 'name', order: 'asc', page, per_page: 20 },
        { enabled: open && kind === 'category' },
    );
    const result = kind === 'course' ? courses : categories;
    const rows = kind === 'course'
        ? (courses.data?.items ?? []).map((item) => ({ id: item.id, label: item.title }))
        : (categories.data?.items ?? []).map((item) => ({ id: item.id, label: item.label }));
    const resolvedLabel = label
        || (kind === 'course' ? selectedCourse.data?.title : selectedCategory.data?.label)
        || (value === null ? `Choose ${kind}` : `Selected ${kind}`);

    if (!open) {
        return (
            <button
                type="button"
                disabled={disabled}
                className="inline-flex h-10 min-w-44 items-center justify-between gap-3 rounded border border-border bg-bg px-3 text-left text-[13px] text-fg disabled:opacity-60"
                onClick={() => setOpen(true)}
            >
                <span className="truncate">{resolvedLabel}</span>
                <span aria-hidden="true">⌄</span>
            </button>
        );
    }

    return (
        <div className="relative z-30 grid min-w-[280px] gap-2 rounded-lg border border-border bg-surface p-3 shadow-card">
            <BankInput autoFocus type="search" aria-label={uiText("vidyalms.ui.3fb8bdb53b90e204", "Search {p0}s", { p0: kind })} placeholder={uiText("vidyalms.ui.3fb8bdb53b90e204", "Search {p0}s", { p0: kind })} value={q} onChange={(event) => { setQ(event.target.value); setPage(1); }} />
            {result.isFetching && <Spinner label={uiText("vidyalms.ui.a8f329424fb81c3e", "Searching {p0}s", { p0: kind })} />}
            <ul className="grid max-h-52 overflow-y-auto rounded border border-border">
                {rows.map((row) => (
                    <li key={row.id} className="border-b border-border last:border-b-0">
                        <button type="button" disabled={result.isFetching} className="w-full cursor-pointer px-3 py-2 text-left text-xs text-fg hover:bg-surface-2 disabled:opacity-50" onClick={() => { onChange(row.id, row.label); setOpen(false); setQ(''); setPage(1); }}>
                            {row.label}
                        </button>
                    </li>
                ))}
                {!result.isFetching && rows.length === 0 && <li className="p-3 text-xs text-fg-2">{uiText("vidyalms.ui.f7953ef2fee6fceb", "No matching ")}{kind}{uiText("vidyalms.ui.382584f2456ef4ea", "s.")}</li>}
            </ul>
            <div className="flex items-center justify-between gap-2">
                <Pagination page={result.data?.page ?? page} perPage={result.data?.per_page ?? 20} total={result.data?.total ?? 0} onPageChange={setPage} label={uiText("vidyalms.ui.78e681cd67d0e046", "{p0} pages", { p0: kind })} />
                <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)}>{uiText("vidyalms.ui.7d9eb7acb13e2462", "Close")}</Button>
            </div>
        </div>
    );
}

export function AuthorPicker({
    value,
    label,
    onChange,
}: {
    value: number | null;
    label: string;
    onChange: (id: number | null, label: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const users = useUserSearch(q, 20);
    if (!open) {
        return (
            <button type="button" className="inline-flex h-10 min-w-40 items-center justify-between gap-3 rounded border border-border bg-bg px-3 text-left text-[13px] text-fg" onClick={() => setOpen(true)}>
                <span className="truncate">{value === null ? uiText("vidyalms.ui.5c74546007cb263e", "Any author") : label || 'Selected author'}</span><span aria-hidden="true">⌄</span>
            </button>
        );
    }
    return (
        <div className="relative z-30 grid min-w-[260px] gap-2 rounded-lg border border-border bg-surface p-3 shadow-card">
            <BankInput autoFocus type="search" aria-label={uiText("vidyalms.ui.0452ff51d1276a1c", "Search authors")} placeholder={uiText("vidyalms.ui.0452ff51d1276a1c", "Search authors")} value={q} onChange={(event) => setQ(event.target.value)} />
            {users.isFetching && <Spinner label={uiText("vidyalms.ui.ef3ba5f1e9dd1c37", "Searching authors")} />}
            <ul className="grid max-h-48 overflow-y-auto rounded border border-border">
                <li><button type="button" className="w-full px-3 py-2 text-left text-xs text-fg hover:bg-surface-2" onClick={() => { onChange(null, ''); setOpen(false); }}>{uiText("vidyalms.ui.5c74546007cb263e", "Any author")}</button></li>
                {(users.data ?? []).map((user) => (
                    <li key={user.id} className="border-t border-border">
                        <button type="button" className="w-full px-3 py-2 text-left text-xs text-fg hover:bg-surface-2" onClick={() => { onChange(user.id, user.name); setOpen(false); setQ(''); }}>
                            <span className="block font-medium">{user.name}</span><span className="text-fg-2">{user.email}</span>
                        </button>
                    </li>
                ))}
                {q.trim().length >= 2 && users.isSuccess && users.data.length === 0 && <li className="p-3 text-xs text-fg-2">{uiText("vidyalms.ui.d990c7cd0f13fcf8", "No matching authors.")}</li>}
            </ul>
            <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)}>{uiText("vidyalms.ui.7d9eb7acb13e2462", "Close")}</Button>
        </div>
    );
}
