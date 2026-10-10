import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { Button, ErrorState, DataTable, EmptyState } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { ListFrame } from '../components/list-frame';
import { FilterSelect } from '../components/filter-toolbar';

export function ImportProblems({ id }: { id: number }) {
    const client = useApiClient();
    const [page, setPage] = useState(1);
    const [code, setCode] = useState('');
    const query = useQuery({
        queryKey: ['vidyalms', 'import-problems', id, page, code],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/import/{id}/problems', {
                        signal,
                        params: { path: { id }, query: { page, per_page: 25, code: code || undefined } },
                    })
                ).data,
            ),
    });
    const download = useMutation({
        mutationFn: async () => {
            const text = must(
                (await client.GET('/import/{id}/failed-rows', { params: { path: { id } }, parseAs: 'text' })).data,
            );
            const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = `vidyalms-import-${id}-rejected.csv`;
            link.click();
            URL.revokeObjectURL(url);
        },
    });
    return (
        <ListFrame
            title={uiText("vidyalms.ui.a5ed1df8b393ee40", "Row problems")}
            page={page}
            perPage={25}
            total={query.data?.total ?? 0}
            shownCount={query.data?.items.length ?? 0}
            onPageChange={setPage}
            chips={
                <FilterSelect
                    label={uiText("vidyalms.ui.1f29c40d33506aa3", "Problem type")}
                    value={code}
                    options={[
                        { value: '', label: uiText("vidyalms.ui.99f3f414c3220f3d", "All problems") },
                        ...Object.keys(query.data?.by_code ?? {}).map((value) => ({
                            value,
                            label: value.replaceAll('_', ' '),
                        })),
                    ]}
                    onValueChange={(value) => {
                        setCode(value);
                        setPage(1);
                    }}
                />
            }
            action={
                <Button
                    variant="outline"
                    disabled={download.isPending || !query.data?.rejected}
                    onClick={() => download.mutate()}
                >
                    {uiText("vidyalms.ui.52b0efd92472ba49", "Download rejected rows")}</Button>
            }
            banner={
                query.isError ? (
                    <ErrorState error={query.error} onRetry={() => void query.refetch()} />
                ) : download.isError ? (
                    <ErrorState error={download.error} />
                ) : undefined
            }
        >
            <DataTable<components['schemas']['ImportRowProblem']>
                caption={uiText("vidyalms.ui.9f3ac6f3b3b708d5", "Import row problems")}
                rows={query.data?.items ?? []}
                rowKey={(row) => `${row.file}:${row.row}:${row.column}:${row.code}`}
                loading={query.isPending}
                columns={[
                    {
                        key: 'row',
                        header: uiText("vidyalms.ui.dc2ec826bf30a743", "Source row"),
                        width: '160px',
                        cell: (row) => `${row.file || uiText("vidyalms.ui.0e570ca6fabe24f9", "Source")} · ${row.row}`,
                    },
                    {
                        key: 'message',
                        header: uiText("vidyalms.ui.a1c5ae7bcb34bbf4", "Problem"),
                        minWidth: 250,
                        weight: 2,
                        cell: (row) => (
                            <span className="grid gap-1">
                                <strong>{row.message}</strong>
                                <span className="vy-workspace-muted">
                                    {row.column ?? uiText("vidyalms.ui.a4331cdd86f4180f", "Entire row")} · {row.code.replaceAll('_', ' ')}
                                </span>
                            </span>
                        ),
                    },
                    { key: 'value', header: uiText("vidyalms.ui.953acccb25176146", "Source value"), minWidth: 160, weight: 1, cell: (row) => row.value },
                    { key: 'outcome', header: uiText("vidyalms.ui.4e80abb5b14656d4", "Outcome"), width: '120px', cell: (row) => row.outcome },
                ]}
                empty={<EmptyState title={uiText("vidyalms.ui.85d6f5eb7c3a71e5", "No row problems in this view")} />}
            />
        </ListFrame>
    );
}
