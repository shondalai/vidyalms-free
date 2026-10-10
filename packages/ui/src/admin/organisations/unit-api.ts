import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { OrganisationUnitWrite } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { organisationKeys } from './api';

export function useOrganisationUnits(id: number, page: number, search: string, enabled = true) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'units', page, search],
        enabled,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations/{id}/units', {
                params: { path: { id }, query: { page, per_page: 20, search: search || undefined } },
                signal,
            });
            return must(data);
        },
    });
}
export function useOrganisationUnit(id: number, unitId: number | null) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'unit', unitId],
        enabled: unitId !== null,
        queryFn: async ({ signal }) => {
            if (unitId === null) throw new Error('A unit identifier is required.');
            const { data } = await client.GET('/manage/organisations/{id}/units/{unit_id}', {
                params: { path: { id, unit_id: unitId } },
                signal,
            });
            return must(data);
        },
    });
}
export function useSaveOrganisationUnit() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            unitId,
            body,
            idempotencyKey,
        }: {
            id: number;
            unitId: number | null;
            body: OrganisationUnitWrite;
            idempotencyKey: string;
        }) => {
            if (unitId === null) {
                const { data } = await client.POST('/manage/organisations/{id}/units', {
                    params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                    body,
                });
                return must(data);
            }
            const { data } = await client.PATCH('/manage/organisations/{id}/units/{unit_id}', {
                params: { path: { id, unit_id: unitId }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: organisationKeys.root }),
    });
}
