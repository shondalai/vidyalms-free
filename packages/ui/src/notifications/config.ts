import { createContext, useContext } from 'react';
import { z } from 'zod';
import { mountConfigBaseSchema } from '@/shared/config';

/*
 * The notification site pages: the preference centre and the unsubscribe
 * confirmation.
 *
 * They are their own entry rather than a screen of the player, because the
 * unsubscribe page is reached UNAUTHENTICATED from a link in an inbox and
 * must work with no session, no course and no enrolment. Mounting it inside
 * an app that assumes a learner would make the one page a person reaches
 * after leaving depend on being signed in, which CAN-SPAM forbids: nothing
 * beyond the token may be demanded, and no fee may be charged.
 *
 * - screen: which of the two this mount is. The host has one page per
 *   screen, so a bookmarked address still lands where it says.
 * - token: the signed token from the message, on the unsubscribe screen. It
 *   is read from the host rather than from the address bar, because the host
 *   owns its own URL shape on both platforms.
 * - urls.home: where the one forward action goes.
 *
 * CACHE SAFETY. Both pages are personal, and the unsubscribe page is served
 * to an unauthenticated caller from a link that may sit in a shared inbox.
 * Nothing here is cached across mounts: every query is fetched on mount and
 * dropped when the page goes, so a second person opening the same page in
 * the same browser session never sees the first one's answer. The host owns
 * the HTTP cache headers; this owns the client cache.
 */

export const notificationScreens = ['preferences', 'unsubscribe'] as const;

export type NotificationScreen = (typeof notificationScreens)[number];

export const notificationConfigSchema = mountConfigBaseSchema.extend({
    screen: z.enum(notificationScreens).default('preferences'),
    /** Present on the unsubscribe screen; empty elsewhere. */
    token: z.string().default(''),
    urls: z
        .object({
            home: z.string().default(''),
            preferences: z.string().default(''),
        })
        .default({ home: '', preferences: '' }),
});

export type NotificationConfig = z.infer<typeof notificationConfigSchema>;

export const NotificationConfigContext = createContext<NotificationConfig | null>(null);

export function useNotificationConfig(): NotificationConfig {
    const config = useContext(NotificationConfigContext);
    if (config === null) {
        throw new Error('useNotificationConfig must be used inside mountApp');
    }
    return config;
}
