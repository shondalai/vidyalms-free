import { translate as uiText } from '@/shared/i18n';
import { PUBLISHED_REACT_MAJOR } from '@/question-types';
import {
    CONDITION_EDITOR_CONTRACT,
    ConditionEditorRegistrationError,
    type ConditionEditorListener,
    type ConditionEditorModule,
    type ConditionEditorRegistryApi,
} from './contract';

/*
 * The registry, with the three rules every registry in this codebase has and
 * for the same reasons: register() refuses a taken key so two packages that
 * both believe they own one fail loudly at boot, replace() requires the key
 * to exist and hands back the displaced module so an override decorates
 * rather than reimplements, and there is no unregister(), because a stored
 * leaf names a condition key that must stay resolvable for the life of the
 * page.
 *
 * A MISS IS NOT AN ERROR HERE. The consumer falls back to the schema-driven
 * generic editor, which is the same direction the core's own registry-miss
 * table publishes for an authoring surface: the author still sees what is
 * stored and can still change it.
 */

function check(key: string, module: ConditionEditorModule): void {
    if (key.trim() === '') {
        throw new ConditionEditorRegistrationError('A condition editor needs a provider key.');
    }
    if (!Number.isInteger(module.version) || module.version < 1) {
        throw new ConditionEditorRegistrationError(`"${key}" declared no usable contract version.`);
    }
    if (module.version > CONDITION_EDITOR_CONTRACT) {
        throw new ConditionEditorRegistrationError(
            `"${key}" speaks condition editor contract ${module.version} and this bundle speaks`
                + ` ${CONDITION_EDITOR_CONTRACT}; the editor is newer than the VidyaLMS assets that host it.`,
        );
    }
    if (module.Editor === undefined || module.Editor === null) {
        throw new ConditionEditorRegistrationError(`"${key}" has no Editor.`);
    }
    const major = module.reactMajor ?? PUBLISHED_REACT_MAJOR;
    if (major !== PUBLISHED_REACT_MAJOR) {
        throw new ConditionEditorRegistrationError(
            `"${key}" ships an editor built against React ${major}; this bundle publishes React`
                + ` ${PUBLISHED_REACT_MAJOR} on window.vidyalms.react.`,
        );
    }
}

export class ConditionEditorRegistry implements ConditionEditorRegistryApi {
    readonly contract = CONDITION_EDITOR_CONTRACT;

    private readonly modules = new Map<string, ConditionEditorModule>();

    private readonly listeners = new Set<ConditionEditorListener>();

    register(key: string, module: ConditionEditorModule): void {
        check(key, module);
        if (this.modules.has(key)) {
            throw new ConditionEditorRegistrationError(
                `A condition editor for "${key}" is already registered; use replace() to decorate it.`,
            );
        }
        this.modules.set(key, module);
        this.emit({ kind: 'register', key });
    }

    replace(key: string, module: ConditionEditorModule): ConditionEditorModule {
        check(key, module);
        const previous = this.modules.get(key);
        if (previous === undefined) {
            throw new ConditionEditorRegistrationError(
                uiText("vidyalms.ui.b20ddb51c9891b04", "No condition editor is registered for \"{p0}\"; use register() to add one.", { p0: key }),
            );
        }
        this.modules.set(key, module);
        this.emit({ kind: 'replace', key });
        return previous;
    }

    has(key: string): boolean {
        return this.modules.has(key);
    }

    get(key: string): ConditionEditorModule | null {
        return this.modules.get(key) ?? null;
    }

    keys(): readonly string[] {
        return [...this.modules.keys()];
    }

    subscribe(listener: ConditionEditorListener): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    }

    private emit(event: { kind: 'register' | 'replace'; key: string }): void {
        for (const listener of [...this.listeners]) {
            listener(event);
        }
    }
}
