import { translate as uiText } from '@/shared/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ProblemError } from '@vidyalms/api-sdk';
import type { CreditIdentityRequest } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { useMyCertificates } from '@/shared/api';
import type { CredentialSubject } from './config';

export { useMyCertificates };

/*
 * The learner and public halves of the credential surface.
 *
 * Nothing here is cached across mounts on the verification page: every key
 * carries the code, and the page is served to an unauthenticated caller from
 * a link that may sit in a shared inbox.
 */

export const credentialKeys = {
    root: ['vidyalms', 'credentials'] as const,
    badges: ['vidyalms', 'credentials', 'my-badges'] as const,
    identities: ['vidyalms', 'credentials', 'credit-identities'] as const,
    publication: ['vidyalms', 'credentials', 'publication'] as const,
    verification: (subject: CredentialSubject, code: string) =>
        ['vidyalms', 'credentials', 'verify', subject, code] as const,
};

function must<T>(value: T | undefined): T {
    if (value === undefined) {
        throw new Error(uiText("vidyalms.ui.79715b15d77cc409", "The server answered OK with an empty body where content was expected."));
    }
    return value;
}

/**
 * The caller's badge awards. With no package issuing badges this answers 200
 * with an empty list and `available` false, which is the registry miss
 * applied to a route: nothing on the contract is gated, so the screen shows
 * a sentence rather than an error.
 */
export function useMyBadges(enabled = true, query: { page?: number; per_page?: number } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled,
        queryKey: [...credentialKeys.badges, query],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/badges', { params: { query }, signal });
            return must(data);
        },
    });
}

/**
 * The identifiers the registered credit schemes ask this learner for. A
 * scheme whose package is no longer installed still reports the values it
 * holds, so a learner sees what was stored rather than watching it vanish.
 */
export function useMyCreditIdentities(enabled = true) {
    const client = useApiClient();
    return useQuery({
        enabled,
        queryKey: credentialKeys.identities,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/credit-identities', { signal });
            return must(data);
        },
    });
}

export function useSetMyCreditIdentities() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ body }: { body: CreditIdentityRequest }) => {
            const { data } = await client.PUT('/me/credit-identities', { body });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: credentialKeys.identities });
        },
    });
}

export function useMyCredentialPublication() {
    const client = useApiClient();
    return useQuery({
        queryKey: credentialKeys.publication,
        gcTime: 0,
        staleTime: 0,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/credentials/publication', { signal });
            return must(data);
        },
    });
}

export function useSetMyCredentialPublication() {
    const client = useApiClient();
    const cache = useQueryClient();
    return useMutation({
        mutationFn: async (publish: boolean) => {
            const { data } = await client.PUT('/me/credentials/publication', {
                body: { publish_learner_name: publish },
            });
            return must(data);
        },
        onSuccess: (data) => {
            cache.setQueryData(credentialKeys.publication, data);
            void cache.invalidateQueries({ queryKey: [...credentialKeys.root, 'verify'] });
        },
    });
}

/** Withdrawn, missing and unrendered files have different recovery paths. */
export function isNotRendered(error: unknown): boolean {
    return error instanceof ProblemError && error.status === 409 && error.problem.artefact_status === 'not_rendered';
}

/**
 * A signed, expiring URL for one of the caller's own certificate artefacts.
 *
 * It is a mutation rather than a query on purpose: the URL expires, so
 * asking for one is an action taken at the moment somebody wants the file,
 * never a value cached beside a list. The raw storage path never appears in
 * any response, and the media type is whatever the renderer produced, so the
 * client reads it rather than assuming HTML.
 */
export function useCertificateArtefact() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({ certificateId }: { certificateId: number }) => {
            const { data } = await client.GET('/me/certificates/{id}/download', {
                params: { path: { id: certificateId } },
            });
            return must(data);
        },
    });
}

/**
 * The public verification answer for one code.
 *
 * It answers 200 for every input including an unknown code, so probing
 * reveals nothing structural, and it is verification rather than validation:
 * it says the credential conforms and what its status is, never whether the
 * holder can do the job.
 */
export function useVerification(subject: CredentialSubject, code: string) {
    const client = useApiClient();
    const trimmed = code.trim();
    return useQuery({
        enabled: trimmed !== '',
        queryKey: credentialKeys.verification(subject, trimmed),
        gcTime: 0,
        staleTime: 0,
        queryFn: async ({ signal }) => {
            if (subject === 'badge_award') {
                const { data } = await client.GET('/badges/{code}/verify', {
                    params: { path: { code: trimmed } },
                    signal,
                });
                return must(data);
            }
            const { data } = await client.GET('/certificates/{code}/verify', {
                params: { path: { code: trimmed } },
                signal,
            });
            return must(data);
        },
    });
}
