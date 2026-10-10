import { translate as uiText } from '@/shared/i18n';
import { useQuery } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { FolderTree, ArrowUpRight } from 'lucide-react';
import { Badge, Button, DataTable, EmptyState, ErrorState, buttonVariants } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { useDebounced, useListState } from '../hooks';
import { ListFrame } from '../components/list-frame';
import { WorkspacePage, WorkspaceCallout } from '../components/workspace-page';

function hostLink(value: string | null | undefined): string | undefined {
    if (!value) return;
    try {
        const url = new URL(value, window.location.href);
        return url.origin === window.location.origin ? url.href : undefined;
    } catch {
        return;
    }
}
export function CategoriesScreen() {
    const client = useApiClient();
    const list = useListState(
        'all',
        { key: 'name', order: 'asc' },
        { persistence: 'hash-query', allowedFilters: ['all'], allowedSortKeys: ['name', 'id'] },
    );
    const search = useDebounced(list.search);
    const query = useQuery({
        queryKey: ['vidyalms', 'categories', search, list.page, list.sort],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/course-categories', {
                        signal,
                        params: {
                            query: {
                                q: search,
                                page: list.page,
                                per_page: 20,
                                sort: list.sort.key as 'name' | 'id',
                                order: list.sort.order,
                            },
                        },
                    })
                ).data,
            ),
    });
    const management = hostLink(query.data?.management_url);
    return (
        <WorkspacePage
            title={uiText("vidyalms.ui.b8b1d894c6837252", "Categories")}
            description={uiText("vidyalms.ui.f8e27fae3355a500", "Give your catalogue a clear structure. Explore categories, review their hierarchy, and keep content easy to discover.")}
            actions={
                management && (
                    <a className={buttonVariants({ variant: 'primary' })} href={management}>
                        {uiText("vidyalms.ui.49cf9ec62cef55f5", "Manage categories")}<ArrowUpRight size={16} />
                    </a>
                )
            }
        >
            <WorkspaceCallout icon={FolderTree} title={uiText("vidyalms.ui.e2fc44d32e472c45", "An organized catalogue starts here")}>
                {uiText("vidyalms.ui.59e163b1edb634fc", "Categories are shared with your CMS. Use its category manager to create, rename, or reorganize them; changes are reflected in your course builder.")}</WorkspaceCallout>
            <ListFrame
                title={uiText("vidyalms.ui.a8a8a986360b5cf1", "Category directory")}
                search={{
                    label: uiText("vidyalms.ui.d03ec37c5f391830", "Search categories"),
                    placeholder: uiText("vidyalms.ui.32e9a4d481be61ab", "Search category names…"),
                    value: list.search,
                    onChange: list.setSearch,
                }}
                page={list.page}
                perPage={20}
                total={query.data?.total ?? 0}
                shownCount={query.data?.items.length ?? 0}
                onPageChange={list.setPage}
                action={
                    <Button variant="outline" onClick={() => void query.refetch()}>
                        {uiText("vidyalms.ui.0e91610117029a62", "Refresh")}</Button>
                }
                banner={
                    query.isError ? <ErrorState error={query.error} onRetry={() => void query.refetch()} /> : undefined
                }
            >
                <DataTable<components['schemas']['CourseCategory']>
                    caption="Categories"
                    rows={query.data?.items ?? []}
                    rowKey={(row) => row.id}
                    loading={query.isPending}
                    sort={list.sort}
                    onSortChange={list.setSort}
                    columns={[
                        {
                            key: 'name',
                            header: uiText("vidyalms.ui.292c06f0045a45d0", "Category"),
                            sortKey: 'name',
                            minWidth: 220,
                            weight: 2,
                            cell: (row) => (
                                <span className="vy-workspace-person">
                                    <span className="vy-workspace-glyph">
                                        <FolderTree size={18} />
                                    </span>
                                    <span>
                                        <strong>{row.name}</strong>
                                        <small>{uiText("vidyalms.ui.daddf23ec891e14c", "Category #")}{row.id}</small>
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'hierarchy',
                            header: uiText("vidyalms.ui.23be567534abe4a1", "Hierarchy"),
                            minWidth: 220,
                            weight: 2,
                            cell: (row) => <span className="vy-workspace-muted">{row.label}</span>,
                        },
                        {
                            key: 'state',
                            header: uiText("vidyalms.ui.920e413c7d411b61", "Status"),
                            width: '140px',
                            cell: (row) => (
                                <Badge variant={row.state === 'published' ? 'ok' : 'neutral'}>{row.state}</Badge>
                            ),
                        },
                        {
                            key: 'actions',
                            header: uiText("vidyalms.ui.ff8059dc6752afdd", "Actions"),
                            width: '140px',
                            cell: (row) => {
                                const url = hostLink(row.edit_url);
                                return url ? (
                                    <a className={buttonVariants({ variant: 'outline', size: 'sm' })} href={url}>
                                        {uiText("vidyalms.ui.21610dd6f1db38ba", "Edit category")}</a>
                                ) : (
                                    <span className="vy-workspace-muted">{uiText("vidyalms.ui.9b4c6c8590e918ed", "View only")}</span>
                                );
                            },
                        },
                    ]}
                    empty={
                        <EmptyState
                            title={search ? uiText("vidyalms.ui.15269886edc236b4", "No matching categories") : uiText("vidyalms.ui.cb88661762c6f0a5", "Your catalogue is ready to organize")}
                            description={
                                search
                                    ? uiText("vidyalms.ui.f051b305eee5ebe3", "Try a different name or clear the search.")
                                    : uiText("vidyalms.ui.d92b8ebb5fd96800", "Create your first category in the CMS category manager.")
                            }
                            action={
                                search ? (
                                    <Button variant="outline" onClick={() => list.setSearch('')}>
                                        {uiText("vidyalms.ui.3b7ea51793e9d906", "Clear search")}</Button>
                                ) : undefined
                            }
                        />
                    }
                />
            </ListFrame>
        </WorkspacePage>
    );
}
