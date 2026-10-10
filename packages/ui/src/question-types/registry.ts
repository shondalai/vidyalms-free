import {
    PUBLISHED_REACT_MAJOR,
    QUESTION_TYPE_CONTRACT,
    QuestionTypeRegistrationError,
    type InteractionMode,
    type QuestionContext,
    type QuestionInstance,
    type QuestionTypeClientModule,
    type QuestionTypeRegistryApi,
    type RegistryEntry,
    type RegistryListener,
} from './contract';

/*
 * The registry itself. Registration is a gate, not a form: a module that
 * cannot state how it is operated without a mouse drag does not get in, and
 * a module with no conformance kit result gets in flagged so a site can
 * decide whether to publish with it.
 *
 * There is no unregister(). Stored rows reference type keys, so a key stays
 * resolvable for the life of the page; switching a renderer off is
 * replace() with one that refuses.
 */

function readModes(module: QuestionTypeClientModule, key: string): readonly InteractionMode[] {
    let modes: readonly InteractionMode[];
    try {
        modes = module.interactionModes();
    } catch (cause) {
        throw new QuestionTypeRegistrationError(
            `"${key}" threw from interactionModes(): ${cause instanceof Error ? cause.message : String(cause)}`,
        );
    }
    if (!Array.isArray(modes) || modes.length === 0) {
        throw new QuestionTypeRegistrationError(`"${key}" declared no interaction modes.`);
    }
    return modes;
}

/**
 * SC 2.5.7 operates independently of SC 2.1.1, so keyboard support does not
 * satisfy it: a fully keyboard-operable drag interface still fails if a
 * pointer user cannot complete the task by clicking alone.
 */
function checkModes(key: string, module: QuestionTypeClientModule, modes: readonly InteractionMode[]): string | null {
    const justification = module.essentialDraggingJustification?.() ?? null;
    const hasPointerNoDrag = modes.includes('POINTER_NO_DRAG');
    const hasKeyboard = modes.includes('KEYBOARD');
    if (hasPointerNoDrag && hasKeyboard) {
        return justification;
    }
    if (!modes.includes('POINTER_DRAG')) {
        throw new QuestionTypeRegistrationError(
            `"${key}" must declare POINTER_NO_DRAG and KEYBOARD; it declared ${modes.join(', ')}.`,
        );
    }
    if (justification === null || justification.trim() === '') {
        throw new QuestionTypeRegistrationError(
            `"${key}" declares POINTER_DRAG without both POINTER_NO_DRAG and KEYBOARD and supplies no`
                + ' essentialDraggingJustification(); SC 2.5.7 is not satisfied by keyboard support.',
        );
    }
    return justification;
}

function checkVersion(key: string, module: QuestionTypeClientModule): void {
    if (!Number.isInteger(module.version) || module.version < 1) {
        throw new QuestionTypeRegistrationError(`"${key}" declared no usable contract version.`);
    }
    if (module.version > QUESTION_TYPE_CONTRACT) {
        throw new QuestionTypeRegistrationError(
            `"${key}" speaks question type contract ${module.version} and this bundle speaks`
                + ` ${QUESTION_TYPE_CONTRACT}; the renderer is newer than the VidyaLMS assets that host it.`,
        );
    }
    /* An authoring block is React; a second React breaks hooks silently, so
       the pairing fails loudly here instead. */
    if (module.authoring !== undefined) {
        const major = module.reactMajor ?? PUBLISHED_REACT_MAJOR;
        if (major !== PUBLISHED_REACT_MAJOR) {
            throw new QuestionTypeRegistrationError(
                `"${key}" ships an authoring block built against React ${major}; this bundle publishes`
                    + ` React ${PUBLISHED_REACT_MAJOR} on window.vidyalms.react.`,
            );
        }
    }
}

function checkShape(key: string, module: QuestionTypeClientModule): void {
    for (const member of ['mount', 'answeredStatus', 'describeResponse', 'interactionModes'] as const) {
        if (typeof module[member] !== 'function') {
            throw new QuestionTypeRegistrationError(`"${key}" has no ${member}().`);
        }
    }
    const authoring = module.authoring;
    if (authoring === undefined) {
        return;
    }
    for (const member of ['toFormValues', 'fromFormValues', 'blankFormValues', 'formSchema'] as const) {
        if (typeof authoring[member] !== 'function') {
            throw new QuestionTypeRegistrationError(`"${key}" ships an authoring contract with no ${member}().`);
        }
    }
    if (authoring.Block === undefined || authoring.Block === null) {
        throw new QuestionTypeRegistrationError(`"${key}" ships an authoring contract with no Block.`);
    }
}

function buildEntry(key: string, module: QuestionTypeClientModule): RegistryEntry {
    if (typeof key !== 'string' || key.trim() === '') {
        throw new QuestionTypeRegistrationError('A question type key must be a non-empty string.');
    }
    checkShape(key, module);
    checkVersion(key, module);
    const modes = readModes(module, key);
    const justification = checkModes(key, module, modes);
    const conformanceResult = module.conformanceResult?.() ?? null;
    return {
        key,
        module,
        flagged: conformanceResult === null || conformanceResult.trim() === '',
        modes,
        essentialDraggingJustification: justification,
        conformanceResult,
    };
}

export class QuestionTypeRegistry implements QuestionTypeRegistryApi {
    readonly contract = QUESTION_TYPE_CONTRACT;

    private readonly byKey = new Map<string, RegistryEntry>();

    private readonly listeners = new Set<RegistryListener>();

    register(typeId: string, module: QuestionTypeClientModule): void {
        if (this.byKey.has(typeId)) {
            throw new QuestionTypeRegistrationError(
                `"${typeId}" is already registered; use replace() to decorate it and delegate to the`
                    + ' module it returns.',
            );
        }
        this.byKey.set(typeId, buildEntry(typeId, module));
        this.emit('register', typeId);
    }

    replace(typeId: string, module: QuestionTypeClientModule): QuestionTypeClientModule {
        const existing = this.byKey.get(typeId);
        if (existing === undefined) {
            throw new QuestionTypeRegistrationError(
                `"${typeId}" is not registered, so there is nothing to replace; use register().`,
            );
        }
        this.byKey.set(typeId, buildEntry(typeId, module));
        this.emit('replace', typeId);
        return existing.module;
    }

    has(typeId: string): boolean {
        return this.byKey.has(typeId);
    }

    get(typeId: string): QuestionTypeClientModule | null {
        return this.byKey.get(typeId)?.module ?? null;
    }

    entry(typeId: string): RegistryEntry | null {
        return this.byKey.get(typeId) ?? null;
    }

    keys(): readonly string[] {
        return [...this.byKey.keys()];
    }

    entries(): readonly RegistryEntry[] {
        return [...this.byKey.values()];
    }

    /**
     * PCI's own accessor. A miss is a normal path, so this throws only when
     * the caller asked for a key it has already been told is absent; the
     * shell resolves through resolveModule() instead and falls through to the
     * shape-keyed renderer.
     */
    getInstance(typeId: string, el: HTMLElement, ctx: QuestionContext): QuestionInstance {
        const module = this.get(typeId);
        if (module === null) {
            throw new QuestionTypeRegistrationError(`"${typeId}" is not registered.`);
        }
        return module.mount(el, ctx);
    }

    subscribe(listener: RegistryListener): () => void {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    }

    private emit(kind: 'register' | 'replace', key: string): void {
        for (const listener of [...this.listeners]) {
            try {
                listener({ kind, key });
            } catch (cause) {
                /* One bad subscriber never stops a registration. */
                console.error('[vidyalms] question type registry listener threw', cause);
            }
        }
    }
}
