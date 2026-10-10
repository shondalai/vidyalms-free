import { useQuery } from '@tanstack/react-query';
import type { components, VidyaLmsClient } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import type { Catalogue, Parameters, ReportData, ReportRun } from './model';

export const reportKeys = ['vidyalms', 'report-workspace'] as const;
export function useCatalogue(organisation: number | null) {
    const client = useApiClient();
    return useQuery<Catalogue>({
        queryKey: [...reportKeys, 'catalogue', organisation],
        staleTime: 60000,
        queryFn: async ({ signal }) =>
            organisation === null
                ? must((await client.GET('/manage/reports', { signal })).data)
                : must(
                      (
                          await client.GET('/manage/organisations/{id}/reports', {
                              params: { path: { id: organisation } },
                              signal,
                          })
                      ).data,
                  ),
    });
}
export function useReport(
    key: string,
    parameters: Parameters,
    organisation: number | null,
    page = 1,
    perPage = 20,
    enabled = true,
    courseScoped = false,
) {
    const client = useApiClient();
    return useQuery<ReportData>({
        queryKey: [...reportKeys, 'data', key, organisation, parameters, page, perPage],
        enabled,
        queryFn: async ({ signal }) => {
            const query = { ...parameters, page, per_page: perPage };
            if (organisation !== null)
                return must(
                    (
                        await client.GET('/manage/organisations/{id}/reports/{key}', {
                            params: { path: { id: organisation, key }, query },
                            signal,
                        })
                    ).data,
                );
            if (courseScoped && typeof parameters.course_id === 'number')
                return must(
                    (
                        await client.GET('/manage/courses/{id}/reports/{key}', {
                            params: { path: { id: parameters.course_id, key }, query },
                            signal,
                        })
                    ).data,
                );
            return must((await client.GET('/manage/reports/{key}', { params: { path: { key }, query }, signal })).data);
        },
    });
}
export function useReportRun(id: number | null) {
    const client = useApiClient();
    return useQuery<ReportRun>({
        queryKey: [...reportKeys, 'run', id],
        enabled: id !== null,
        queryFn: async ({ signal }) =>
            must((await client.GET('/manage/report-runs/{id}', { params: { path: { id: id! } }, signal })).data),
        refetchInterval: (query) =>
            query.state.data && ['queued', 'running'].includes(query.state.data.state) ? 5000 : false,
    });
}
export async function exportReport(
    client: VidyaLmsClient,
    key: string,
    parameters: Parameters,
    organisation: number | null,
    format: string,
    delivery: string,
    replay: string,
) {
    const query = { ...parameters, format, ...(delivery ? { delivery } : {}) };
    if (organisation !== null)
        return must(
            (
                await client.POST('/manage/organisations/{id}/reports/{key}/exports', {
                    params: { path: { id: organisation, key }, query },
                    headers: { 'Idempotency-Key': replay },
                })
            ).data,
        );
    return must(
        (
            await client.POST('/manage/reports/{key}/exports', {
                params: { path: { key }, query },
                headers: { 'Idempotency-Key': replay },
            })
        ).data,
    );
}
export function useSchedules(organisation: number | null, page: number, state?: 'enabled' | 'disabled') {
    const client = useApiClient();
    return useQuery({
        queryKey: [...reportKeys, 'schedules', organisation, page, state],
        queryFn: async ({ signal }) =>
            organisation === null
                ? must(
                      (
                          await client.GET('/manage/report-schedules', {
                              params: { query: { page, per_page: 20, state } },
                              signal,
                          })
                      ).data,
                  )
                : (() =>
                      client
                          .GET('/manage/organisations/{id}/report-schedules', {
                              params: { path: { id: organisation } },
                              signal,
                          })
                          .then(({ data }) => {
                              const items = must(data).items.filter((item) => !state || item.state === state);
                              return {
                                  items: items.slice((page - 1) * 20, page * 20),
                                  total: items.length,
                                  page,
                                  per_page: 20,
                              };
                          }))(),
    });
}
export async function saveSchedule(
    client: VidyaLmsClient,
    organisation: number | null,
    id: number | null,
    body: components['schemas']['ReportScheduleRequest'],
    replay: string,
) {
    if (organisation !== null) {
        if (id !== null)
            return must(
                (
                    await client.PATCH('/manage/organisations/{id}/report-schedules/{schedule_id}', {
                        params: { path: { id: organisation, schedule_id: id }, header: { 'Idempotency-Key': replay } },
                        body: { state: body.state ?? 'disabled' },
                    })
                ).data,
            );
        return must(
            (
                await client.POST('/manage/organisations/{id}/report-schedules', {
                    params: { path: { id: organisation }, header: { 'Idempotency-Key': replay } },
                    body,
                })
            ).data,
        );
    }
    if (id !== null)
        return must(
            (
                await client.PATCH('/manage/report-schedules/{id}', {
                    params: { path: { id }, header: { 'Idempotency-Key': replay } },
                    body,
                })
            ).data,
        );
    return must(
        (await client.POST('/manage/report-schedules', { params: { header: { 'Idempotency-Key': replay } }, body }))
            .data,
    );
}
export async function runSchedule(client: VidyaLmsClient, id: number, replay: string) {
    return must(
        (
            await client.POST('/manage/report-schedules/{id}/run', {
                params: { path: { id }, header: { 'Idempotency-Key': replay } },
            })
        ).data,
    );
}
