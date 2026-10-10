import { useEffect, useRef, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowUpRight, BookOpen, Building2, CalendarDays } from 'lucide-react';
import { Button, Card, EmptyState, ErrorState, Icon, Pagination, Skeleton, buttonVariants } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { useCredentialConfig } from './config';
import { organisationLearningStrings as s } from './organisation-learning-strings';

export function OrganisationLearning({ id }: { id: number }) {
    const config = useCredentialConfig();
    const client = useApiClient();
    const [tab, setTab] = useState<'assigned' | 'available'>('assigned');
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [draft, setDraft] = useState('');
    const heading = useRef<HTMLHeadingElement>(null);
    const query = useQuery({
        queryKey: ['vidyalms', 'my-organisation-learning', config.user.id, id, tab, page, search],
        enabled: config.user.id > 0,
        placeholderData: keepPreviousData,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/organisations/{id}/learning', {
                params: { path: { id }, query: { tab, page, per_page: 12, search } }, signal,
            });
            return must(data);
        },
    });
    useEffect(() => {
        heading.current?.focus({ preventScroll: true });
    }, [id]);
    const data = query.data;
    const courseUrl = (slug: string, learn = false) => (learn ? config.urls.player : config.urls.course)?.replace('__VIDYALMS_SLUG__', encodeURIComponent(slug));
    const date = (value: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z'));

    return <section className="grid min-w-0 gap-6 font-body text-fg">
        <a href="#/" className="inline-flex w-fit items-center gap-2 text-sm text-fg-2 hover:text-accent"><Icon icon={ArrowLeft} size={16} />{s.back}</a>
        <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-6">
            <div className="flex items-start gap-4"><span className="rounded-xl bg-accent-soft p-3 text-accent"><Icon icon={Building2} size={28} /></span>
                <div><p className="mb-1 text-xs uppercase tracking-widest text-fg-2">{s.eyebrow}</p><h1 ref={heading} tabIndex={-1} className="font-display text-3xl font-semibold focus:outline-none">{data?.organisation.name ?? s.title}</h1><p className="mt-2 max-w-2xl text-sm text-fg-2">{s.intro}</p></div></div>
            {config.urls.myLearning && <a href={config.urls.myLearning} className={buttonVariants({ variant: 'outline' })}>{s.allLearning}<Icon icon={ArrowUpRight} size={16} /></a>}
        </header>
        {config.user.id <= 0 ? <EmptyState title={s.signIn} description={s.signInBody} /> : <>
            {data && <dl className="grid grid-cols-3 gap-3 rounded-xl bg-surface-2 p-4 sm:p-6">
                {[[s.assigned, data.summary.assigned], [s.available, data.summary.available], [s.outstanding, data.summary.required]].map(([label, count]) => <div key={label} className="grid gap-1"><dt className="text-xs text-fg-2 sm:text-sm">{label}</dt><dd className="font-display text-2xl font-semibold sm:text-3xl">{count}</dd></div>)}
            </dl>}
            <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-3">
                <div className="flex gap-2" aria-label={s.filter}>
                    {(['assigned', 'available'] as const).map((value) => <Button key={value} variant={tab === value ? 'primary' : 'ghost'} aria-pressed={tab === value} onClick={() => {
                        setTab(value);
                        setPage(1);
                    }}>{value === 'assigned' ? s.assigned : s.available}</Button>)}
                </div>
                <form className="flex min-w-0 flex-wrap items-center gap-2" onSubmit={(event) => {
                    event.preventDefault();
                    setSearch(draft.trim());
                    setPage(1);
                }}>
                    <label className="sr-only" htmlFor="org-course-search">{s.search}</label><input id="org-course-search" type="search" maxLength={200} value={draft} onChange={(event) => setDraft(event.target.value)} placeholder={s.search} className="min-w-0 rounded-btn border border-border bg-surface px-3 py-2 text-sm text-fg" />
                    <Button type="submit" variant="outline">{s.searchButton}</Button>
                </form>
            </div>
            <p className="text-sm text-fg-2">{tab === 'assigned' ? s.assignedHint : s.availableHint}</p>
            {(query.isPending || query.isPlaceholderData) && <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" aria-label={s.loading}>{[1, 2, 3].map((n) => <Skeleton key={n} className="h-64" />)}</div>}
            {query.isError && <ErrorState error={query.error} onRetry={() => { void query.refetch(); }} />}
            {data && !query.isError && !query.isPlaceholderData && <>
                {data.items.length === 0 ? <EmptyState title={search ? s.noMatches : tab === 'assigned' ? s.noAssigned : s.noAvailable} description={search ? s.searchHint : tab === 'assigned' ? s.noAssignedHint : s.noAvailableHint} />
                    : <ul className="grid gap-5" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(min(100%,300px),1fr))' }}>{data.items.map((course) => <li key={course.course_id} className="min-w-0">
                        <Card className="flex h-full flex-col gap-4 p-5 sm:p-6">
                            <div className="flex items-center justify-between gap-2"><span className="rounded-lg bg-surface-2 p-2 text-accent"><Icon icon={BookOpen} size={22} /></span><span className={`rounded-full px-3 py-1 text-xs ${course.required ? 'bg-accent-soft text-accent' : 'bg-surface-2 text-fg-2'}`}>{course.required ? s.required : tab === 'assigned' ? s.optional : s.offered}</span></div>
                            <div><h2 className="font-display text-xl font-semibold">{course.title}</h2>{course.summary && <p className="mt-2 text-sm leading-relaxed text-fg-2">{course.summary}</p>}</div>
                            {course.enrolment_status && <div><div className="mb-2 flex justify-between gap-2 text-xs text-fg-2"><span>{s.progress}</span><span>{Math.round(course.progress_percent)}%</span></div><progress aria-label={s.progressFor(course.title)} value={course.progress_percent} max={100} className="h-1.5 w-full accent-[var(--_vy-accent)]" /></div>}
                            {(course.due_at || course.expires_at) && <div className="grid gap-2 border-t border-border pt-3 text-xs text-fg-2">
                                {course.due_at && <p className="flex items-center gap-2"><Icon icon={CalendarDays} size={14} />{s.due(date(course.due_at))}</p>}
                                {course.expires_at && <p>{course.has_access ? s.expires(date(course.expires_at)) : s.ended(date(course.expires_at))}</p>}
                            </div>}
                            {!course.can_learn && <p className="text-xs leading-relaxed text-fg-2">{!course.published ? s.unpublished : course.enrolment_status === 'suspended' ? s.suspended : tab === 'assigned' ? s.needsAccess : course.grant_mode === 'auto_grant' ? s.awaitingAllocation : s.offerHelp}</p>}
                            <div className="mt-auto flex flex-wrap items-center gap-3 border-t border-border pt-4">
                                {course.can_learn && courseUrl(course.slug, true) && <a href={courseUrl(course.slug, true)} className={buttonVariants({ variant: 'primary' })}>{course.enrolment_status === 'completed' ? s.review : course.progress_percent > 0 ? s.resume : s.start}<Icon icon={ArrowUpRight} size={16} /></a>}
                                {course.published && courseUrl(course.slug) && <a href={courseUrl(course.slug)} className="text-sm font-medium text-accent underline underline-offset-4">{s.courseDetails}</a>}
                            </div>
                        </Card>
                    </li>)}</ul>}
                <Pagination label={s.pages} page={data.page} perPage={data.per_page} total={data.total} onPageChange={setPage} />
            </>}
        </>}
    </section>;
}
