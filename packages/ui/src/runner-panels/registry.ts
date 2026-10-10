import { translate as uiText } from '@/shared/i18n';
import { PUBLISHED_REACT_MAJOR } from '@/question-types';
import {
    RUNNER_PANEL_CONTRACT,
    RunnerPanelRegistrationError,
    isRunnerPanelSlot,
    type RunnerPanelListener,
    type RunnerPanelModule,
    type RunnerPanelRegistryApi,
    type RunnerPanelSlot,
} from './contract';

/*
 * The registry itself, with the same three rules the question type registry
 * has and for the same reasons: register() refuses a taken slot so two
 * packages that both believe they own one fail loudly at boot, replace()
 * requires the slot to exist and hands back the displaced module so an
 * override decorates rather than reimplements, and there is no unregister()
 * because a stored attempt row names a behaviour whose panel must stay
 * resolvable for the life of the page.
 */

/**
 * One documented cast. The public surface is typed per slot; the store
 * cannot be, because a Map holds one value type and the slots do not share
 * their props. Every write goes through register()/replace(), which take the
 * typed module, so the value under a key is the module for that key.
 */
type StoredModule = RunnerPanelModule<RunnerPanelSlot>;

function check(slot: string, module: RunnerPanelModule<RunnerPanelSlot>): void {
    if (!isRunnerPanelSlot(slot)) {
        throw new RunnerPanelRegistrationError(
            `"${slot}" is not a runner panel slot; this bundle offers ${
                ['certainty', 'item_feedback'].join(', ')
            }.`,
        );
    }
    if (!Number.isInteger(module.version) || module.version < 1) {
        throw new RunnerPanelRegistrationError(`"${slot}" declared no usable contract version.`);
    }
    if (module.version > RUNNER_PANEL_CONTRACT) {
        throw new RunnerPanelRegistrationError(
            `"${slot}" speaks runner panel contract ${module.version} and this bundle speaks`
                + ` ${RUNNER_PANEL_CONTRACT}; the panel is newer than the VidyaLMS assets that host it.`,
        );
    }
    if (module.Panel === undefined || module.Panel === null) {
        throw new RunnerPanelRegistrationError(`"${slot}" has no Panel.`);
    }
    const major = module.reactMajor ?? PUBLISHED_REACT_MAJOR;
    if (major !== PUBLISHED_REACT_MAJOR) {
        throw new RunnerPanelRegistrationError(
            `"${slot}" ships a panel built against React ${major}; this bundle publishes React`
                + ` ${PUBLISHED_REACT_MAJOR} on window.vidyalms.react.`,
        );
    }
}

export class RunnerPanelRegistry implements RunnerPanelRegistryApi {
    readonly contract = RUNNER_PANEL_CONTRACT;

    private readonly modules = new Map<RunnerPanelSlot, StoredModule>();

    private readonly listeners = new Set<RunnerPanelListener>();

    register<S extends RunnerPanelSlot>(slot: S, module: RunnerPanelModule<S>): void {
        check(slot, module as StoredModule);
        if (this.modules.has(slot)) {
            throw new RunnerPanelRegistrationError(
                uiText("vidyalms.ui.95dcee54f4f5f28a", "The runner panel slot \"{p0}\" is already taken; use replace() to decorate it.", { p0: slot }),
            );
        }
        this.modules.set(slot, module as StoredModule);
        this.emit({ kind: 'register', slot });
    }

    replace<S extends RunnerPanelSlot>(slot: S, module: RunnerPanelModule<S>): RunnerPanelModule<S> {
        check(slot, module as StoredModule);
        const previous = this.modules.get(slot);
        if (previous === undefined) {
            throw new RunnerPanelRegistrationError(
                uiText("vidyalms.ui.e533fc75a3e07562", "No runner panel is registered for \"{p0}\"; use register() to add one.", { p0: slot }),
            );
        }
        this.modules.set(slot, module as StoredModule);
        this.emit({ kind: 'replace', slot });
        return previous as RunnerPanelModule<S>;
    }

    has(slot: string): boolean {
        return isRunnerPanelSlot(slot) && this.modules.has(slot);
    }

    get<S extends RunnerPanelSlot>(slot: S): RunnerPanelModule<S> | null {
        return (this.modules.get(slot) as RunnerPanelModule<S> | undefined) ?? null;
    }

    slots(): readonly RunnerPanelSlot[] {
        return [...this.modules.keys()];
    }

    subscribe(listener: RunnerPanelListener): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    }

    private emit(event: { kind: 'register' | 'replace'; slot: RunnerPanelSlot }): void {
        for (const listener of [...this.listeners]) {
            listener(event);
        }
    }
}
