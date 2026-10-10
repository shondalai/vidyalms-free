import { createContext, useContext } from 'react';
import { z } from 'zod';
import { shopSetupSchema } from '@/shared/shop-setup';
import { mountConfigBaseSchema } from '@/shared/config';

/*
 * The instructor workbench's own mount contract (05-frontend-strategy.md,
 * "Per-app mount config"). The shared part (api, user, locale) comes from
 * mountConfigBaseSchema; what this app adds is:
 *
 * - course: the course the workbench opens on, or null to render the
 *   caller's course list from GET /manage/courses;
 * - urls.courses: the host's own course list page (back to courses);
 * - urls.workbench: a URL template carrying a {course_id} token, so the
 *   course list can link into the workbench of any course;
 * - urls.preview: a URL template carrying a {slug} token, which opens the
 *   course in the learner player (preview as learner);
 * - urls.emailTemplates: the optional host-owned administration destination
 *   for the safe shared email template studio;
 * - urls.upload: the host's own multipart endpoint, which stores a file and
 *   answers {path, name, size_bytes, mime, url}. Bytes are a CMS concern and
 *   never travel through the API contract, so this URL is the app's only way
 *   to one. It is optional because a host older than the endpoint has none,
 *   and every control that offers a picker keeps its typed path beside it.
 *
 * Capabilities are deliberately absent: every screen learns what it may do
 * from the API's 403s, which render as the permission states of artboard 4b.
 */
export const instructorConfigSchema = mountConfigBaseSchema.extend({
    shop: shopSetupSchema.optional(),
    course: z
        .object({
            id: z.number().int().positive(),
            slug: z.string(),
        })
        .nullable(),
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
    /*
     * Contract paths this host actually serves, as their templates
     * ("/manage/quizzes/{id}/export").
     *
     * A control that would 404 is worse than no control, and the bundle and
     * the host ship separately: a workbench newer than its extension would
     * otherwise offer an export the host cannot route. Absent means a host
     * from before the key existed, so a route that is not listed is not
     * offered. It is a DISCOVERY key and never a permission: what the caller
     * may do is still the API's 403, rendered as the permission states.
     */
    routes: z.array(z.string()).optional(),
    urls: z.object({
        courses: z.string(),
        workbench: z.string(),
        preview: z.string(),
        /** Host-owned admin destination for the safe shared template studio. */
        emailTemplates: z.string().min(1).optional(),
        certificateTemplates: z.string().min(1).optional(),
        notifications: z.string().min(1).optional(),
        upload: z.string().min(1).optional(),
    }),
});

export type InstructorConfig = z.infer<typeof instructorConfigSchema>;

/** Provided once by mountApp; typed by this app, not by the shared layer. */
export const InstructorConfigContext = createContext<InstructorConfig | null>(null);

export function useInstructorConfig(): InstructorConfig {
    const config = useContext(InstructorConfigContext);
    if (config === null) {
        throw new Error('useInstructorConfig must be used inside mountApp');
    }
    return config;
}

/**
 * Whether this host serves one contract route, by its path template.
 *
 * Only a route the host declared is offered. A host that says nothing is one
 * from before the key existed, so nothing new is offered on it and nothing
 * old changes.
 */
export function hasRoute(config: InstructorConfig, template: string): boolean {
    return config.routes?.includes(template) ?? false;
}

/**
 * Fills a host URL template. The host owns the URL shape (a Joomla task
 * URL, a WordPress admin page), so the app only substitutes the tokens the
 * contract names and never builds a URL of its own.
 */
export function fillUrlTemplate(template: string, tokens: Record<string, string | number>): string {
    let filled = template;
    for (const [name, value] of Object.entries(tokens)) {
        filled = filled.split(`{${name}}`).join(encodeURIComponent(String(value)));
    }
    return filled;
}
