import { createContext, useContext } from 'react';
import { z } from 'zod';
import { shopSetupSchema } from '@/shared/shop-setup';
import { mountConfigBaseSchema } from '@/shared/config';

/*
 * The admin app's half of the mount contract (05-frontend-strategy.md).
 * Shared with every app: api, user, locale. Its own:
 *
 * - screen: the host's initial menu entry. The workspace matches subsequent
 *   history URLs against urls.screens and supplies the active screen through
 *   this context. Reloading any screen still uses its native CMS entry.
 * - urls.workbench / urls.preview: templates the host owns, with a
 *   {course_id} and a {slug} token this app substitutes. It never builds a
 *   host URL itself.
 * - urls.settings: the host's own settings page for host-specific setup.
 *   LMS settings use the API.
 * - urls.screens: the host's URL per menu entry, so one screen can link to
 *   another (the dashboard's needs-attention rows). A screen the host did
 *   not give a URL for renders as text instead of a link, never as a dead
 *   href.
 * - urls.upload: the host's own multipart endpoint, which stores a file and
 *   answers {path, name, size_bytes, mime, url}. Optional: a host older than
 *   the endpoint has none, and the control that offers a picker keeps its
 *   typed address beside it.
 */

/**
 * The screens the free build registers. This is documentation and the source
 * of the host's menu labels, not a gate: the mount schema takes any screen
 * key and the screen registry decides at render time whether the bundle
 * carries one, so a package that registers a screen of its own does not need
 * this list edited.
 *
 * A key the bundle does not carry renders an explicit unavailable state.
 * It must never silently open a different administration screen.
 */
export const adminScreens = [
    'dashboard',
    'courses',
    'categories',
    'enrolments',
    'learners',
    'certificates',
    'notifications',
    'email-templates',
    'organisations',
    'reports',
    'operations',
    'import',
    'settings',
] as const;

export type AdminScreen = (typeof adminScreens)[number];

export const adminConfigSchema = mountConfigBaseSchema.extend({
    shop: shopSetupSchema.optional(),
    screen: z.string().min(1).default('dashboard'),
    importTransport: z.object({ url: z.string().min(1), nonceName: z.string().min(1), nonce: z.string().min(1) }).optional(),
    routes: z.array(z.string()).optional(),
    /*
     * What each registered upload purpose accepts, from the policy the host
     * already builds. Optional: a host older than this key says nothing and
     * the picker keeps the free build defaults, which is exactly what it did
     * before. A site that raised a ceiling could not use it while the number
     * lived only in the bundle.
     */
    uploadPurposes: z
        .array(
            z.object({
                key: z.string().min(1),
                max_bytes: z.number().int().nonnegative(),
                extensions: z.array(z.string()).default([]),
            }),
        )
        .optional(),
    urls: z.object({
        workbench: z.string(),
        preview: z.string(),
        settings: z.string(),
        upload: z.string().min(1).optional(),
        nativeImport: z.string().min(1).optional(),
        screens: z.record(z.string(), z.string()).default({}),
    }),
});

export type AdminConfig = z.infer<typeof adminConfigSchema>;

export const AdminConfigContext = createContext<AdminConfig | null>(null);

export function useAdminConfig(): AdminConfig {
    const config = useContext(AdminConfigContext);
    if (config === null) {
        throw new Error('useAdminConfig must be used inside mountApp');
    }
    return config;
}

/** The workbench of one course, from the host's template. */
export function workbenchUrl(config: AdminConfig, courseId: number): string {
    const courses = config.urls.screens.courses;
    return courses === undefined
        ? config.urls.workbench.replace('{course_id}', String(courseId))
        : `${courses.split('#')[0]}#/course/${courseId}`;
}

/** The learner-facing page of one course, from the host's template. */
export function previewUrl(config: AdminConfig, slug: string): string {
    return config.urls.preview.replace('{slug}', encodeURIComponent(slug));
}

/** The host's own page for another screen, or null when it gave none. */
export function screenUrl(config: AdminConfig, screen: AdminScreen): string | null {
    return config.urls.screens[screen] ?? null;
}
