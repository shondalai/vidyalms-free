import { translate as uiText } from '@/shared/i18n';
import { z } from 'zod';
import { sharedStrings } from './strings';

/*
 * The mount contract. The adapter renders
 *
 *   <div id="vidyalms-player" data-vy-config='{json}'></div>
 *
 * (or one of the other mount ids) and loads the bundle. Every app reads ONLY
 * this config; no other globals.
 *
 * The part below is identical on all apps and on both hosts. What each app
 * adds on top of it is the app's own business, so each declares its own
 * schema extending this one and hands it to mountApp: the player and the
 * quiz runner share mountConfigSchema, the instructor workbench declares
 * instructorConfigSchema (see src/instructor/config.ts). Nothing here knows
 * what an app needs, and adding an app cannot loosen another app's contract.
 *
 * THE ONE SANCTIONED GLOBAL. `window.vidyalms` is the single documented
 * carve-out to "no other globals", created at module scope by the
 * quiz-runner and instructor entries before either app mounts:
 *
 *   window.vidyalms.questionTypes  the client renderer registry, which a
 *                                  third-party bundle calls register() on
 *   window.vidyalms.react          the pinned React runtime a third-party
 *   window.vidyalms.reactDom       build marks external, so an authoring
 *   window.vidyalms.jsxRuntime     block runs on the same React as the shell
 *
 * It exists because a question type renderer arrives in its own bundle,
 * after this one, and there is nowhere else for the two to meet: the mount
 * element carries the configuration of one app, not the registry of a
 * cross-app extension point. Load order is declarative (a joomla.asset.json
 * dependency, a wp_enqueue_script dependency) and late registration is
 * handled by the registry's change event rather than by hope.
 *
 * See packages/ui/src/question-types/bridge.ts and the README. Shared code
 * that needs only the namespace takes it from ./vidyalms-namespace.ts, never
 * from the question type layer, which imports this chunk.
 */
/**
 * The version of the mount contract this bundle speaks.
 *
 * The config is a wire contract between a host and a bundle that ship
 * separately and are upgraded separately, and it had no version at all: the
 * failure mode when a bundle was newer than its host was a silently missing
 * key, which is exactly what happened with urls.upload (both app schemas had
 * to gain .optional() for a key the hosts now always send).
 *
 * Raise this when the shape changes in a way an older host cannot satisfy.
 * A host that sends nothing is a host from before versions existed, which is
 * contract 1, so an omitted value is not an error.
 */
export const MOUNT_CONTRACT = 1;

export const mountConfigBaseSchema = z.object({
    contract: z.number().int().positive().default(MOUNT_CONTRACT),
    api: z.object({
        base: z.string().min(1),
        mode: z.enum(['prefix', 'query']),
        headers: z.record(z.string(), z.string()),
    }),
    user: z.object({
        id: z.number().int(),
        name: z.string(),
    }),
    locale: z.string(),
});

export type MountConfigBase = z.infer<typeof mountConfigBaseSchema>;

/** The learner apps' contract: the player and the quiz runner read this one. */
export const mountConfigSchema = mountConfigBaseSchema.extend({
    idleTimeoutSeconds: z.number().int().min(30).max(3600).default(300),
    course: z.object({
        id: z.number().int().positive(),
        slug: z.string(),
    }),
    lessonId: z.number().int().positive().nullable(),
    urls: z.object({
        course: z.string(),
        myLearning: z.string(),
        player: z.string().optional(),
        upload: z.string().optional(),
    }),
});

export type MountConfig = z.infer<typeof mountConfigSchema>;

/**
 * What readMountConfig needs of an app's schema. Structural on purpose: a
 * zod object satisfies it without the generic gymnastics z.ZodType<T> would
 * cost at every call site.
 */
export interface MountConfigSchema<T extends MountConfigBase> {
    parse: (raw: unknown) => T;
}

export class MountConfigError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'MountConfigError';
    }
}

/**
 * Renders the malformed-config state straight into the mount element with
 * plain DOM: React never starts without a valid config, and a broken embed
 * must not be a blank div. Inline styles use the tokens with hard fallbacks
 * because the stylesheet may not have arrived when this runs.
 */
function renderConfigError(el: HTMLElement, detail: string): void {
    const doc = el.ownerDocument;
    el.replaceChildren();

    const box = doc.createElement('div');
    box.setAttribute('role', 'alert');
    // A stable marker for scripts/check-entry-evaluation.mjs, which fails an entry whose evaluation config stops parsing.
    box.setAttribute('data-vy-config-error', '');
    box.style.cssText = [
        'box-sizing:border-box',
        "font-family:var(--vy-font-body, 'Bricolage Grotesque Variable', system-ui, sans-serif)",
        'font-size:13px',
        'line-height:1.5',
        'padding:12px 16px',
        'border:1px solid var(--vy-border, #e5dfd7)',
        'border-left:3px solid var(--vy-danger, #8f3a24)',
        'border-radius:var(--vy-radius, 6px)',
        'background:var(--vy-surface, #ffffff)',
        'color:var(--vy-fg, #26211c)',
    ].join(';');

    const title = doc.createElement('strong');
    title.textContent = sharedStrings.config.malformedTitle;
    title.style.cssText = 'display:block;color:var(--vy-danger, #8f3a24);margin-bottom:2px';

    const body = doc.createElement('span');
    body.textContent = sharedStrings.config.malformedBody;
    body.style.cssText = 'display:block;color:var(--vy-fg-2, #6d6459)';

    const code = doc.createElement('code');
    code.textContent = detail;
    code.style.cssText = 'display:block;margin-top:8px;font-size:11px;color:var(--vy-fg-2, #6d6459);white-space:pre-wrap;word-break:break-word';

    box.append(title, body, code);
    el.append(box);
}

/**
 * Parses and validates data-vy-config from the mount element against the
 * app's own schema (the learner one unless an app passes its own). On
 * malformed config it renders the error state in place, then throws
 * MountConfigError; callers stop there and the person on the page still
 * sees what happened.
 */
export function readMountConfig(el: HTMLElement): MountConfig;
export function readMountConfig<T extends MountConfigBase>(el: HTMLElement, schema: MountConfigSchema<T>): T;
export function readMountConfig(
    el: HTMLElement,
    schema: MountConfigSchema<MountConfigBase> = mountConfigSchema,
): MountConfigBase {
    try {
        const raw = el.getAttribute('data-vy-config');
        if (raw === null || raw.trim() === '') {
            throw new Error('the data-vy-config attribute is missing or empty');
        }
        const parsed: unknown = JSON.parse(raw);
        const config = schema.parse(parsed);

        // A host one contract ahead of its bundle is sending a shape this
        // bundle has never seen, and the failure mode without this is a
        // silently missing key rather than a sentence anybody can act on.
        if (config.contract > MOUNT_CONTRACT) {
            throw new Error(
                `this page was rendered for mount contract ${config.contract} and this bundle speaks ${MOUNT_CONTRACT};`
                + ' the VidyaLMS assets are older than the extension that embedded them',
            );
        }

        return config;
    } catch (cause) {
        const detail =
            cause instanceof z.ZodError
                ? z.prettifyError(cause)
                : cause instanceof Error
                  ? cause.message
                  : String(cause);
        renderConfigError(el, detail);
        throw new MountConfigError(uiText("vidyalms.ui.d852b7c34c2f1346", "Malformed mount configuration on #{p0}: {p1}", { p0: el.id, p1: detail }));
    }
}
