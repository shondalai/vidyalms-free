import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type {
    OrganisationCourseReleaseRequest,
    OrganisationLearningGrantRequest,
    OrganisationSeatPoolRequest,
    OrganisationSeatPoolUpdateRequest,
} from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { organisationKeys } from './api';

export function useOrganisationSummary(id: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'summary'],
        queryFn: async ({ signal }) =>
            must(
                (await client.GET('/manage/organisations/{id}/summary', { params: { path: { id } }, signal }))
                    .data,
            ),
    });
}
export function useOrganisationReleases(id: number, page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'releases', page],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/releases', {
                        params: { path: { id }, query: { page, per_page: 20 } },
                        signal,
                    })
                ).data,
            ),
    });
}
export function useOrganisationLearning(id: number, page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'learning', page],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/learning', {
                        params: { path: { id }, query: { page, per_page: 20 } },
                        signal,
                    })
                ).data,
            ),
    });
}
export function useOrganisationPools(id: number, page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'pools', page],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/seat-pools', {
                        params: { path: { id }, query: { page, per_page: 20 } },
                        signal,
                    })
                ).data,
            ),
    });
}
export function useOrganisationPool(id: number, poolId: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'pool', poolId],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/seat-pools/{pool_id}', {
                        params: { path: { id, pool_id: poolId } },
                        signal,
                    })
                ).data,
            ),
    });
}
export function useOrganisationAssignments(id: number, poolId: number, page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'assignments', poolId, page],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/seat-pools/{pool_id}/assignments', {
                        params: { path: { id, pool_id: poolId }, query: { page, per_page: 20 } },
                        signal,
                    })
                ).data,
            ),
    });
}
export function useOrganisationLedger(id: number, poolId: number, page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'ledger', poolId, page],
        queryFn: async ({ signal }) =>
            must(
                (
                    await client.GET('/manage/organisations/{id}/seat-pools/{pool_id}/ledger', {
                        params: { path: { id, pool_id: poolId }, query: { page, per_page: 20 } },
                        signal,
                    })
                ).data,
            ),
    });
}
function useWrite<T, R>(mutationFn: (input: T) => Promise<R>) {
    const cache = useQueryClient();
    return useMutation({
        mutationKey: organisationKeys.root,
        mutationFn,
        onSuccess: () => cache.invalidateQueries({ queryKey: organisationKeys.root }),
    });
}
type Write<T> = { id: number; body: T; idempotencyKey: string };
export function useReleaseCourse() {
    const client = useApiClient();
    return useWrite(async ({ id, body, idempotencyKey }: Write<OrganisationCourseReleaseRequest>) =>
        must(
            (
                await client.POST('/manage/organisations/{id}/releases', {
                    params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                    body,
                })
            ).data,
        ),
    );
}
export function useGrantOrganisationLearning() {
    const client = useApiClient();
    return useWrite(async ({ id, body, idempotencyKey }: Write<OrganisationLearningGrantRequest>) =>
        must(
            (
                await client.POST('/manage/organisations/{id}/learning/grant', {
                    params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                    body,
                })
            ).data,
        ),
    );
}
export function useRevokeOrganisationLearning() {
    const client = useApiClient();
    return useWrite(
        async ({
            id,
            entitlementId,
            revision,
            idempotencyKey,
        }: {
            id: number;
            entitlementId: number;
            revision: number;
            idempotencyKey: string;
        }) =>
            must(
                (
                    await client.POST('/manage/organisations/{id}/learning/{entitlement_id}/revoke', {
                        params: {
                            path: { id, entitlement_id: entitlementId },
                            header: { 'Idempotency-Key': idempotencyKey },
                        },
                        body: { expected_revision: revision },
                    })
                ).data,
            ),
    );
}
export function useCreateSeatPool() {
    const client = useApiClient();
    return useWrite(async ({ id, body, idempotencyKey }: Write<OrganisationSeatPoolRequest>) =>
        must(
            (
                await client.POST('/manage/organisations/{id}/seat-pools', {
                    params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                    body,
                })
            ).data,
        ),
    );
}
export function useUpdateSeatPool() {
    const client = useApiClient();
    return useWrite(
        async ({
            id,
            poolId,
            body,
            idempotencyKey,
        }: Write<OrganisationSeatPoolUpdateRequest> & { poolId: number }) =>
            must(
                (
                    await client.PATCH('/manage/organisations/{id}/seat-pools/{pool_id}', {
                        params: {
                            path: { id, pool_id: poolId },
                            header: { 'Idempotency-Key': idempotencyKey },
                        },
                        body,
                    })
                ).data,
            ),
    );
}
export function useAssignSeat() {
    const client = useApiClient();
    return useWrite(
        async ({
            id,
            poolId,
            userId,
            revision,
            idempotencyKey,
        }: {
            id: number;
            poolId: number;
            userId: number;
            revision: number;
            idempotencyKey: string;
        }) =>
            must(
                (
                    await client.POST('/manage/organisations/{id}/seat-pools/{pool_id}/assignments', {
                        params: {
                            path: { id, pool_id: poolId },
                            header: { 'Idempotency-Key': idempotencyKey },
                        },
                        body: { expected_revision: revision, user_id: userId },
                    })
                ).data,
            ),
    );
}
export function useReleaseSeat() {
    const client = useApiClient();
    return useWrite(
        async ({
            id,
            assignmentId,
            revision,
            idempotencyKey,
        }: {
            id: number;
            assignmentId: number;
            revision: number;
            idempotencyKey: string;
        }) =>
            must(
                (
                    await client.POST('/manage/organisations/{id}/seat-assignments/{assignment_id}/release', {
                        params: {
                            path: { id, assignment_id: assignmentId },
                            header: { 'Idempotency-Key': idempotencyKey },
                        },
                        body: { expected_revision: revision },
                    })
                ).data,
            ),
    );
}
