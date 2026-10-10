import { translate as uiText } from '@/shared/i18n';
import {
    REASON_RENDERER_CONTRACT,
    ReasonRendererRegistrationError,
    type ReasonRendererListener,
    type ReasonRendererModule,
    type ReasonRendererRegistryApi,
} from './contract';

/*
 * The registry, with the three rules every registry in this codebase has and
 * for the same reasons: register() refuses a taken key so two packages that
 * both believe they own one fail loudly at boot, replace() requires the key
 * to exist and hands back the displaced module so an override decorates
 * rather than reimplements, and there is no unregister(), because a stored
 * leaf names a condition whose reason key must stay renderable for the life
 * of the page.
 *
 * A miss is not an error: the caller draws the generic line, which is the
 * fallback that shipped before this registry existed and is what keeps a
 * blocked row saying something.
 */

function check(key: string, module: ReasonRendererModule): void {
    if (key.trim() === '') {
        throw new ReasonRendererRegistrationError('A reason renderer needs a reason key.');
    }
    if (!Number.isInteger(module.version) || module.version < 1) {
        throw new ReasonRendererRegistrationError(`"${key}" declared no usable contract version.`);
    }
    if (module.version > REASON_RENDERER_CONTRACT) {
        throw new ReasonRendererRegistrationError(
            `"${key}" speaks reason renderer contract ${module.version} and this bundle speaks`
                + ` ${REASON_RENDERER_CONTRACT}; the renderer is newer than the VidyaLMS assets that host it.`,
        );
    }
    if (typeof module.render !== 'function') {
        throw new ReasonRendererRegistrationError(`"${key}" has no render function.`);
    }
}

export class ReasonRendererRegistry implements ReasonRendererRegistryApi {
    readonly contract = REASON_RENDERER_CONTRACT;

    private readonly modules = new Map<string, ReasonRendererModule>();

    private readonly listeners = new Set<ReasonRendererListener>();

    register(key: string, module: ReasonRendererModule): void {
        check(key, module);
        if (this.modules.has(key)) {
            throw new ReasonRendererRegistrationError(
                `A reason renderer for "${key}" is already registered; use replace() to override it.`,
            );
        }
        this.modules.set(key, module);
        this.announce(key);
    }

    replace(key: string, module: ReasonRendererModule): ReasonRendererModule {
        check(key, module);
        const displaced = this.modules.get(key);
        if (displaced === undefined) {
            throw new ReasonRendererRegistrationError(
                uiText("vidyalms.ui.990e00da381cb9e9", "No reason renderer is registered for \"{p0}\"; use register() to add one.", { p0: key }),
            );
        }
        if (displaced === module) {
            throw new ReasonRendererRegistrationError(
                `"${key}" is already installed; replacing a module with itself would recurse.`,
            );
        }
        this.modules.set(key, module);
        this.announce(key);
        return displaced;
    }

    has(key: string): boolean {
        return this.modules.has(key);
    }

    get(key: string): ReasonRendererModule | null {
        return this.modules.get(key) ?? null;
    }

    keys(): string[] {
        return [...this.modules.keys()].sort();
    }

    subscribe(listener: ReasonRendererListener): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    }

    private announce(key: string): void {
        this.listeners.forEach((listener) => {
            listener({ key });
        });
    }
}
