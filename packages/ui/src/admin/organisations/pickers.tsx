import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Button, ErrorState, Pagination, Skeleton } from '@/shared/components';
import { useManagedCourses } from '@/shared/manage';
import { useDebounced } from '../hooks';
import { TextField } from '../components/field';
import { useOrganisationMembers } from './api';
import { useOrganisationReleases } from './learning-api';
import { organisationLearningStrings as s } from './learning-strings';

function Choices({ label, value, choose, items, page, total, setPage, loading, error, retry }: { label: string; value: number | null; choose: (id: number) => void; items: { id: number; label: string; disabled?: boolean }[]; page: number; total: number; setPage: (page: number) => void; loading: boolean; error: Error | null; retry: () => void }) {
    return <fieldset className="grid min-w-0 gap-2 rounded-lg border border-border p-3"><legend className="px-1 text-sm font-semibold text-fg">{label}</legend>
        {error && <ErrorState error={error} onRetry={retry} />}{loading && <Skeleton className="h-20 w-full" />}
        <div className="grid max-h-56 gap-1 overflow-y-auto">{items.map((item) => <Button key={item.id} type="button" variant={value === item.id ? 'outline' : 'ghost'} disabled={item.disabled} aria-pressed={value === item.id} className="h-auto min-h-10 justify-start whitespace-normal text-left" onClick={() => choose(item.id)}>{item.label}</Button>)}</div>
        {!loading && !error && items.length === 0 && <p className="text-xs text-fg-2">{s.empty}</p>}
        <Pagination label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: label, p1: s.pages })} page={page} perPage={20} total={total} onPageChange={setPage} />
    </fieldset>;
}
export function MemberPicker({ id, value, choose }: { id: number; value: number | null; choose: (id: number) => void }) {
    const [page, setPage] = useState(1); const query = useOrganisationMembers(id, page, 'active');
    return <Choices label={s.member} value={value} choose={choose} items={query.data?.items.map((item) => ({ id: item.user_id, label: item.user_name ?? s.user(item.user_id) })) ?? []} page={page} total={query.data?.total ?? 0} setPage={setPage} loading={query.isPending} error={query.error} retry={() => { void query.refetch(); }} />;
}
export function ReleasedCoursePicker({ id, value, choose }: { id: number; value: number | null; choose: (id: number) => void }) {
    const [page, setPage] = useState(1); const query = useOrganisationReleases(id, page);
    return <Choices label={s.course} value={value} choose={choose} items={query.data?.items.map((item) => ({ id: item.course_id, label: item.course_title ?? s.courseId(item.course_id), disabled: item.state !== 'active' })) ?? []} page={page} total={query.data?.total ?? 0} setPage={setPage} loading={query.isPending} error={query.error} retry={() => { void query.refetch(); }} />;
}
export function SiteCoursePicker({ value, choose }: { value: number | null; choose: (id: number) => void }) {
    const [search, setSearch] = useState(''); const [page, setPage] = useState(1); const q = useDebounced(search);
    const query = useManagedCourses({ page, per_page: 20, q, state: 'published', sort: 'title', order: 'asc' });
    return <div className="grid gap-3"><TextField label={s.search} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
        <Choices label={s.course} value={value} choose={choose} items={query.data?.items.map((item) => ({ id: item.id, label: item.title })) ?? []} page={page} total={query.data?.total ?? 0} setPage={setPage} loading={query.isFetching} error={query.error} retry={() => { void query.refetch(); }} /></div>;
}
