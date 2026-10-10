import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';

/*
 * The two learner-facing notification routes and the one unauthenticated
 * pair.
 *
 * Every query here is personal and none of it is cached across mounts:
 * `staleTime` and `gcTime` are zero and the fetch always runs on mount, so a
 * page opened from a link in a shared inbox never renders somebody else's
 * answer out of the client cache.
 *
 * The GET on a token READS AND NEVER ACTS. Mail security scanners follow
 * links, which is the whole reason the one-click mechanism is specified as a
 * POST; a GET that unsubscribed would turn a scanner into an opt-out. The
 * POST is the only thing on this page that writes, and it needs a person.
 */

type Schemas = components['schemas'];

const PERSONAL = { staleTime: 0, gcTime: 0, refetchOnMount: 'always' } as const;

export const notificationKeys = {
    preferences: ['vidyalms', 'me', 'notification-preferences'] as const,
    unsubscribe: (token: string) => ['vidyalms', 'unsubscribe', token] as const,
};

export function useMyNotificationPreferences() {
    const client = useApiClient();
    return useQuery({
        ...PERSONAL,
        queryKey: notificationKeys.preferences,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/notification-preferences', { signal });
            return must(data);
        },
    });
}

/**
 * Replaces the caller's own preferences as a whole list.
 *
 * Setting a category to never writes a consent row beside the preference
 * row, with the basis recorded, exactly as the unsubscribe endpoint does.
 * The two paths must not disagree about the evidence, which is why the
 * screen sends the same shape either way.
 */
export function useSetMyNotificationPreferences() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (body: Schemas['SetNotificationPreferencesRequest']) => {
            const { data } = await client.PUT('/me/notification-preferences', { body });
            return must(data);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: notificationKeys.preferences });
        },
    });
}

/** What a token would stop. Reads; acts on nothing. */
export function useUnsubscribePreview(token: string) {
    const client = useApiClient();
    return useQuery({
        ...PERSONAL,
        enabled: token !== '',
        retry: false,
        queryKey: notificationKeys.unsubscribe(token),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/notifications/unsubscribe/{token}', {
                params: { path: { token } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * Acts on one signed token.
 *
 * It writes a preference row and a consent row and NEVER a suppression row.
 * A category-scoped click producing an address-scoped block would silence
 * the next certificate mail, which is transactional and something a site may
 * be obliged to deliver; suppression is a deliverability fact about an
 * address and binds every message class.
 *
 * It carries no Idempotency-Key, deliberately. The caller may be a mail
 * client, which cannot be asked to mint one, and it needs none: setting a
 * preference that is already set changes nothing and the second call answers
 * the same outcome.
 */
export function useUnsubscribe() {
    const client = useApiClient();
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({ token, channels }: { token: string; channels?: string[] }) => {
            const { data } = await client.POST('/notifications/unsubscribe/{token}', {
                params: { path: { token } },
                body: channels === undefined ? {} : { channels },
            });
            return must(data);
        },
        onSuccess: (_data, variables) => {
            void queryClient.invalidateQueries({ queryKey: notificationKeys.unsubscribe(variables.token) });
        },
    });
}
