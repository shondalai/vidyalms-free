import { createContext, useContext } from 'react';
import { z } from 'zod';
import { mountConfigBaseSchema } from '@/shared/config';

/*
 * The credential site pages: the holder's own credentials, and the public
 * verification page.
 *
 * They are their own entry rather than screens of the player, because the
 * verification page is reached UNAUTHENTICATED from a code printed on paper
 * and must work with no session, no course and no enrolment. Mounting it
 * inside an app that assumes a learner would make the one page a relying
 * party visits depend on being signed in, which defeats the point of a
 * verifiable credential. It is the same argument the notification pages
 * already carry, applied to the other page a stranger reaches.
 *
 * - screen: which of the two this mount is. The host has one page per
 *   screen, so a bookmarked address still lands where it says.
 * - subject: which credential type the verification page answers for. The
 *   badge route is a separate path from the certificate route, because the
 *   certificate path is already printed on certificates and quoted in mail.
 * - code: the code from the address, read from the host rather than from the
 *   address bar, because the host owns its own URL shape on both platforms.
 *   Lookup accepts the code with or without separators, so whatever a person
 *   retyped is passed through untouched.
 * - urls.home: where the one forward action goes.
 *
 * PRIVACY. The verification page is a PII disclosure surface: it discloses a
 * name against a completion. What it may show is decided by the server (a
 * site setting plus the certificate's own publication flag), and this app
 * renders only what arrives. The noindex header, the meta tag and the rate
 * limit are core middleware on the route, not a client concern.
 *
 * CACHE SAFETY. Both pages are personal, and the verification page is served
 * to an unauthenticated caller from a link that may sit in a shared inbox.
 * Nothing here is cached across mounts: every query is fetched on mount and
 * dropped when the page goes, so a second person opening the same page in
 * the same browser session never sees the first one's answer.
 */

/*
 * The learner's own AI activity joins this entry rather than starting a
 * fourth bundle. It is the same audience and the same session as the holder's
 * own credentials page, it needs no new asset on either host, and the one
 * screen a stranger reaches without a session stays exactly where it was.
 */
export const credentialScreens = ['credentials', 'verify', 'organisations', 'ai-activity'] as const;

export type CredentialScreen = (typeof credentialScreens)[number];

export const credentialSubjects = ['certificate', 'badge_award'] as const;

export type CredentialSubject = (typeof credentialSubjects)[number];

export const credentialConfigSchema = mountConfigBaseSchema.extend({
    screen: z.enum(credentialScreens).default('credentials'),
    subject: z.enum(credentialSubjects).default('certificate'),
    /** Present on the verification screen; empty elsewhere. */
    code: z.string().default(''),
    routes: z.array(z.string()).optional(),
    urls: z
        .object({
            home: z.string().default(''),
            verify: z.string().default(''),
            myLearning: z.string().default(''),
            login: z.string().optional(),
            organisations: z.string().optional(),
            preferences: z.string().optional(),
            course: z.string().optional(),
            player: z.string().optional(),
        })
        .default({ home: '', verify: '', myLearning: '' }),
});

export type CredentialConfig = z.infer<typeof credentialConfigSchema>;

export const CredentialConfigContext = createContext<CredentialConfig | null>(null);

export function useCredentialConfig(): CredentialConfig {
    const config = useContext(CredentialConfigContext);
    if (config === null) {
        throw new Error('useCredentialConfig must be used inside mountApp');
    }
    return config;
}
