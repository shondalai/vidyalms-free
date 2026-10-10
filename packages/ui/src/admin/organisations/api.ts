import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type {
    OrganisationCreateRequest,
    OrganisationUpdateRequest,
    OrganisationMemberRequest,
    OrganisationMemberUpdateRequest,
    OrganisationInvitationRequest,
    OrganisationInvitationClaimRequest,
    OrganisationInvitationAcceptRequest,
} from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';

export const organisationKeys = { root: ['vidyalms', 'organisations'] as const };
export interface OrganisationListParams {
    page: number;
    per_page: number;
    search?: string;
    state?: 'active' | 'suspended';
}
export function useOrganisations(params: OrganisationListParams, enabled = true) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, 'directory', params],
        enabled,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations', { params: { query: params }, signal });
            return must(data);
        },
    });
}
export function useOwnOrganisations(page: number, enabled = true) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, 'mine', page],
        enabled,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/organisations', {
                params: { query: { page, per_page: 20 } },
                signal,
            });
            return must(data);
        },
    });
}
export function useOrganisation(id: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations/{id}', {
                params: { path: { id } },
                signal,
            });
            return must(data);
        },
    });
}
export function useOrganisationRoles(id: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'roles'],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations/{id}/roles', {
                params: { path: { id } },
                signal,
            });
            return must(data);
        },
    });
}
export function useOrganisationMembers(id: number, page: number, state?: 'active' | 'suspended' | 'left') {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'members', page, state],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations/{id}/members', {
                params: { path: { id }, query: { page, per_page: 20, state } },
                signal,
            });
            return must(data);
        },
    });
}
export function useOrganisationInvitations(
    id: number,
    page: number,
    state?: 'pending' | 'accepted' | 'revoked' | 'expired' | 'bounced',
) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'invitations', page, state],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations/{id}/invitations', {
                params: { path: { id }, query: { page, per_page: 20, state } },
                signal,
            });
            return must(data);
        },
    });
}
export function useOrganisationEvents(id: number, page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...organisationKeys.root, id, 'events', page],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/organisations/{id}/events', {
                params: { path: { id }, query: { page, per_page: 20 } },
                signal,
            });
            return must(data);
        },
    });
}
function useOrganisationWrite<T, R>(mutationFn: (input: T) => Promise<R>) {
    const queryClient = useQueryClient();
    // A completed write must refresh the aggregate before the next dialog captures its CAS revision.
    // Await only successful writes; ambiguous failures retain the caller's captured revision and replay key.
    return useMutation({
        mutationKey: organisationKeys.root,
        mutationFn,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: organisationKeys.root }),
    });
}
export function useCreateOrganisation() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({ body, idempotencyKey }: { body: OrganisationCreateRequest; idempotencyKey: string }) => {
            const { data } = await client.POST('/manage/organisations', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
export function useUpdateOrganisation() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            id,
            body,
            idempotencyKey,
        }: {
            id: number;
            body: OrganisationUpdateRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PATCH('/manage/organisations/{id}', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
export function useAddOrganisationMember() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            id,
            body,
            idempotencyKey,
        }: {
            id: number;
            body: OrganisationMemberRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/organisations/{id}/members', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
export function useUpdateOrganisationMember() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            id,
            memberId,
            body,
            idempotencyKey,
        }: {
            id: number;
            memberId: number;
            body: OrganisationMemberUpdateRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PATCH('/manage/organisations/{id}/members/{member_id}', {
                params: { path: { id, member_id: memberId }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
export function useInviteOrganisationMember() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            id,
            body,
            idempotencyKey,
        }: {
            id: number;
            body: OrganisationInvitationRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/organisations/{id}/invitations', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
export function useRevokeOrganisationInvitation() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            id,
            invitationId,
            revision,
            idempotencyKey,
        }: {
            id: number;
            invitationId: number;
            revision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST(
                '/manage/organisations/{id}/invitations/{invitation_id}/revoke',
                {
                    params: {
                        path: { id, invitation_id: invitationId },
                        header: { 'Idempotency-Key': idempotencyKey },
                    },
                    body: { expected_revision: revision },
                },
            );
            return must(data);
        },
    );
}
export function useClaimOrganisationInvitation() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            body,
            idempotencyKey,
        }: {
            body: OrganisationInvitationClaimRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/me/organisation-invitations/claim', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
export function useAcceptOrganisationInvitation() {
    const client = useApiClient();
    return useOrganisationWrite(
        async ({
            body,
            idempotencyKey,
        }: {
            body: OrganisationInvitationAcceptRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/me/organisation-invitations/accept', {
                params: { header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
    );
}
