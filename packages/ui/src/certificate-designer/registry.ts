import { translate as uiText } from '@/shared/i18n';
import { PUBLISHED_REACT_MAJOR } from '@/question-types';
import {
    CERTIFICATE_DESIGNER_CONTRACT,
    CERTIFICATE_DESIGNER_SLOTS,
    CertificateDesignerRegistrationError,
    isCertificateDesignerSlot,
    type CertificateDesignerListener,
    type CertificateDesignerModule,
    type CertificateDesignerRegistryApi,
    type CertificateDesignerSlot,
} from './contract';

/*
 * The registry, with the same three rules every registry in this codebase
 * has and for the same reasons: register() refuses a taken slot so two
 * packages that both believe they own one fail loudly at boot, replace()
 * requires the slot to exist and hands back the displaced module so an
 * override decorates rather than reimplements, and there is no unregister(),
 * because a screen holding a designer must keep it resolvable for the life
 * of the page.
 *
 * A MISS IS NOT AN ERROR HERE. The template editor falls back to its own
 * form editor over the same aggregate, which is the direction the core's
 * registry-miss table publishes for an authoring surface: the author still
 * sees what is stored and can still change it.
 */

/**
 * One documented cast. The public surface is typed per slot; the store
 * cannot be, because a Map holds one value type and the slots do not share
 * their props. Every write goes through register()/replace(), which take the
 * typed module, so the value under a key is the module for that key.
 */
type StoredModule = CertificateDesignerModule<CertificateDesignerSlot>;

function check(slot: string, module: StoredModule): void {
    if (!isCertificateDesignerSlot(slot)) {
        throw new CertificateDesignerRegistrationError(
            `"${slot}" is not a certificate designer slot; this bundle offers ${
                CERTIFICATE_DESIGNER_SLOTS.join(', ')
            }.`,
        );
    }
    if (!Number.isInteger(module.version) || module.version < 1) {
        throw new CertificateDesignerRegistrationError(`"${slot}" declared no usable contract version.`);
    }
    if (module.version > CERTIFICATE_DESIGNER_CONTRACT) {
        throw new CertificateDesignerRegistrationError(
            `"${slot}" speaks certificate designer contract ${module.version} and this bundle speaks`
                + ` ${CERTIFICATE_DESIGNER_CONTRACT}; the designer is newer than the VidyaLMS assets that host it.`,
        );
    }
    if (module.Designer === undefined || module.Designer === null) {
        throw new CertificateDesignerRegistrationError(`"${slot}" has no Designer.`);
    }
    const major = module.reactMajor ?? PUBLISHED_REACT_MAJOR;
    if (major !== PUBLISHED_REACT_MAJOR) {
        throw new CertificateDesignerRegistrationError(
            `"${slot}" ships a designer built against React ${major}; this bundle publishes React`
                + ` ${PUBLISHED_REACT_MAJOR} on window.vidyalms.react.`,
        );
    }
}

export class CertificateDesignerRegistry implements CertificateDesignerRegistryApi {
    readonly contract = CERTIFICATE_DESIGNER_CONTRACT;

    private readonly modules = new Map<CertificateDesignerSlot, StoredModule>();

    private readonly listeners = new Set<CertificateDesignerListener>();

    register<S extends CertificateDesignerSlot>(slot: S, module: CertificateDesignerModule<S>): void {
        check(slot, module as StoredModule);
        if (this.modules.has(slot)) {
            throw new CertificateDesignerRegistrationError(
                uiText("vidyalms.ui.f05bda395d0beacc", "The certificate designer slot \"{p0}\" is already taken; use replace() to decorate it.", { p0: slot }),
            );
        }
        this.modules.set(slot, module as StoredModule);
        this.emit({ kind: 'register', slot });
    }

    replace<S extends CertificateDesignerSlot>(
        slot: S,
        module: CertificateDesignerModule<S>,
    ): CertificateDesignerModule<S> {
        check(slot, module as StoredModule);
        const previous = this.modules.get(slot);
        if (previous === undefined) {
            throw new CertificateDesignerRegistrationError(
                uiText("vidyalms.ui.0744af91b7955d9a", "No certificate designer is registered for \"{p0}\"; use register() to add one.", { p0: slot }),
            );
        }
        this.modules.set(slot, module as StoredModule);
        this.emit({ kind: 'replace', slot });
        return previous as CertificateDesignerModule<S>;
    }

    has(slot: string): boolean {
        return isCertificateDesignerSlot(slot) && this.modules.has(slot);
    }

    get<S extends CertificateDesignerSlot>(slot: S): CertificateDesignerModule<S> | null {
        return (this.modules.get(slot) as CertificateDesignerModule<S> | undefined) ?? null;
    }

    slots(): readonly CertificateDesignerSlot[] {
        return [...this.modules.keys()];
    }

    subscribe(listener: CertificateDesignerListener): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    }

    private emit(event: { kind: 'register' | 'replace'; slot: CertificateDesignerSlot }): void {
        for (const listener of [...this.listeners]) {
            listener(event);
        }
    }
}
