import { version, type ComponentType } from 'react';

/*
 * The client renderer registry contract, copied from the QTI Portable Custom
 * Interaction Communication Bridge rather than invented, so a renderer
 * written against VidyaLMS is structurally the same object as one written
 * against any QTI engine.
 *
 * See docs/technical/12-assessment-types.md, "The client renderer registry".
 *
 * Nothing in this file may know a type key. The free six register through
 * exactly the same calls a third party makes.
 */

/**
 * The contract version this bundle speaks. A module declares the version it
 * was written against; the registry refuses one from the future rather than
 * letting it fail at render.
 *
 * Additive only after 1: a new optional member never raises this number.
 */
export const QUESTION_TYPE_CONTRACT = 1;

/** The React major the bundle publishes on the global, gated at registration. */
export const PUBLISHED_REACT_MAJOR = Number.parseInt(version, 10);

/** Third-party keys carry this prefix; core and Pro keys never do. */
export const VENDOR_KEY_PREFIX = 'x_';

export type QuestionDefinition = Record<string, unknown>;

/**
 * PCI's own item session vocabulary. `closed` and `review` are what a
 * multi-try item moves through when the shell locks a part that is already
 * correct and re-renders after a check.
 */
export type QuestionStatus = 'interacting' | 'suspended' | 'closed' | 'review' | 'solution';

/**
 * Four values, not a boolean. The reporting model cannot reconstruct them
 * later, and a boolean cannot tell a skipped item from an unseen one or
 * from one answered invalidly.
 */
export type AnsweredStatus = 'notpresented' | 'presented' | 'attempted' | 'answered';

/**
 * The closed core set of shape errors, the ones the shell reasons about and
 * groups by. Closed is not the same as exhaustive: a type contributes an
 * additional kind as a namespaced string under the same x_<vendor>_ rule as
 * type keys, and the shell falls back to generic grouping for a kind it
 * does not know.
 *
 * No kind, core or contributed, may be derived from correctness.
 */
export const CORE_ERROR_KINDS = [
    'TOO_MANY_SELECTED',
    'TOO_FEW_SELECTED',
    'REQUIRED_GAP_EMPTY',
    'OUT_OF_RANGE',
    'UNSUPPORTED_FILE_TYPE',
    'VALUE_NOT_NUMERIC',
    'INVALID_PERMUTATION',
    'INVALID_ENVELOPE',
] as const;

export type CoreErrorKind = (typeof CORE_ERROR_KINDS)[number];

/** A core kind, or a namespaced contributed one. */
export type ErrorKind = CoreErrorKind | (string & {});

export function isCoreErrorKind(kind: string): kind is CoreErrorKind {
    return (CORE_ERROR_KINDS as readonly string[]).includes(kind);
}

export interface QuestionError {
    /** The summary links to this DOM id; the module renders no error UI. */
    controlId: string;
    kind: ErrorKind;
    /** Repeated verbatim inline and in the summary. */
    message: string;
    /** Which part of a composite, so one shared summary can group them. */
    partId?: string;
}

/* ── Accessibility declarations ─────────────────────────────────────── */

/**
 * The catalogue's own vocabulary. POINTER_DRAG is an enhancement and never
 * a mode on its own; LINEAR_TEXT is the parallel single-input presentation
 * a spatial interaction ships beside itself.
 */
export type InteractionMode = 'POINTER_DRAG' | 'POINTER_NO_DRAG' | 'KEYBOARD' | 'LINEAR_TEXT';

/** The AfA PNP 3.0 element names, stored verbatim so an import is a mapping. */
export type AccessibilityProfile = Readonly<Record<string, unknown>>;

/* ── The shell services a module reaches ────────────────────────────── */

/**
 * Three regions, three roles, no overlap (SC 4.1.3). `timer` carries the
 * ticking value with aria-live off; threshold announcements go to `status`.
 */
export type AnnounceChannel = 'status' | 'alert' | 'log' | 'timer';

export type AnnounceFn = (channel: AnnounceChannel, message: string) => void;

/**
 * What a type may ask of the DOM it does not own. The header, timer,
 * progress palette and navigation are the shell's; a module never touches
 * them, so the containment a grab needs is a request, not a mutation.
 */
export interface QuestionShell {
    /**
     * During a grab, Tab visits only accepting drop targets and everything
     * else is hidden from assistive technology. The runner implements it by
     * setting inert outside the item body.
     */
    captureInteraction(): void;
    releaseInteraction(): void;
    /** A DOM id unique to this question (and part), for error summary links. */
    controlId(local: string): string;
    /** Identifies the item session this instance belongs to. */
    readonly questionId: string;
    readonly partId?: string;
}

export type MountChild = (el: HTMLElement, typeId: string, childCtx: ChildContext) => QuestionInstance;

/** What a composite supplies for a nested part; the shell fills the rest. */
export interface ChildContext {
    definition: QuestionDefinition;
    response?: unknown;
    state?: string | null;
    status?: QuestionStatus;
    readOnly?: boolean;
    partId: string;
    onResponseChange?: (response: unknown) => void;
    onStateChange?: (state: string) => void;
}

/**
 * Everything a module is handed. Context objects grow additively: a new
 * member never breaks a third party, which is why no method takes a widening
 * argument list.
 */
export interface QuestionContext {
    readonly definition: QuestionDefinition;
    readonly response: unknown;
    /** Opaque resumable UI state, or null when there is none stored. */
    readonly state: string | null;
    readonly status: QuestionStatus;
    readonly readOnly: boolean;
    readonly locale: string;
    readonly accessibilityProfile: AccessibilityProfile;
    /** The shell's live region service; no module creates a region of its own. */
    readonly announce: AnnounceFn;
    readonly shell: QuestionShell;
    /** Lets a composite compose registry entries instead of reaching into the registry. */
    readonly mountChild: MountChild;
    readonly onready: (instance: QuestionInstance) => void;
    readonly ondone: (response: unknown, state: string | null, status: QuestionStatus) => void;
    /**
     * Continuous change notification. PCI polls on ondone; an autosaving
     * runner cannot, so the response and the UI state each have a seam the
     * shell subscribes to.
     */
    readonly onResponseChange: (response: unknown) => void;
    readonly onStateChange: (state: string) => void;
}

export interface QuestionInstance {
    /** The current value, valid or not. Never a correctness claim. */
    getResponse(): unknown;
    setResponse(value: unknown): void;
    /** Opaque resumable UI state; without setState the stored column is write-only. */
    getState(): string;
    setState(state: string): void;
    getErrors(): ReadonlyArray<QuestionError>;
    /** The shell moves a module into closed or review mid-attempt. */
    setStatus(status: QuestionStatus): void;
    destroy(): void;
}

/* ── Authoring ──────────────────────────────────────────────────────── */

/**
 * A type's own form values. Opaque to the shell: the workbench holds the
 * shared prompt, points and feedback fields and hands the rest to the block,
 * so a new type is never an edit to a shared flat model.
 */
export type FormValues = Record<string, unknown>;

export interface AuthoringProps {
    /**
     * The react-hook-form handle over the merged model (the shared fields
     * plus this type's own). Typed as the shared base here because RHF
     * cannot express "my slice"; a block narrows it once at its own top.
     */
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    form: any;
    controlId: (local: string) => string;
    locale: string;
}

/**
 * A zod-shaped validator over the merged form model. Declared structurally so
 * a third party may ship its own zod copy without a version lock.
 */
export interface FormSchema {
    parse: (raw: unknown) => unknown;
    safeParse: (raw: unknown) => { success: boolean };
}

export interface AuthoringContract {
    Block: ComponentType<AuthoringProps>;
    /** The stored definition read into this type's own form values. */
    toFormValues(definition: QuestionDefinition): FormValues;
    /** Those values written back into the definition the provider validates. */
    fromFormValues(values: FormValues, stored: QuestionDefinition): QuestionDefinition;
    /**
     * Every definition key this block owns, whether or not the current values
     * happen to produce one.
     *
     * The workbench preserves every stored key a block does not own and
     * replaces exactly the ones it does. Without the declaration it had to
     * rebuild the definition from the form alone, which deleted every key no
     * form field covers on every save: the scoring policy, the shuffle flag,
     * the authored scoring intent, the selection constraints and their
     * messages, and the layout classes. A new multiple-choice item silently
     * reverted from guess_neutral to plus_minus, and a new ordering item from
     * chance-corrected Kendall tau to raw absolute position, on the first
     * open-and-save of a question nobody meant to change.
     *
     * Declaring it rather than inferring it from one call is what lets a
     * block clear a key it owns: an omitted owned key is a deletion, an
     * omitted unowned key is somebody else's business.
     */
    ownedKeys(): readonly string[];
    /** A fresh question of this type, with the smallest shape its provider accepts. */
    blankFormValues(): FormValues;
    /**
     * The type's own validation, layered over the shared base schema. The
     * base is passed in rather than imported so the module never has to
     * restate the shared fields.
     */
    formSchema(base: FormSchema): FormSchema;
    /** Whether a stored definition marks a question a human has to mark. */
    needsManualMarking?(definition: QuestionDefinition): boolean;
}

/* ── The module ─────────────────────────────────────────────────────── */

export interface QuestionTypeClientModule {
    /** The contract version the module was written against. */
    version: number;
    /**
     * The React major the module's AuthoringBlock was built against. Omit for
     * a learner-only module. An incompatible pairing fails at registration
     * rather than at render.
     */
    reactMajor?: number;
    mount(el: HTMLElement, ctx: QuestionContext): QuestionInstance;
    answeredStatus(response: unknown, ctx: AnsweredStatusContext): AnsweredStatus;
    /** The learner's saved response as plain words, for review and grading rows. */
    describeResponse(
        response: unknown,
        presented: QuestionDefinition,
        ctx: DescribeContext,
    ): string | null;
    /**
     * Omit to register a learner-only module: authoring then falls through to
     * the placeholder, which preserves the stored definition untouched.
     */
    authoring?: AuthoringContract;
    /** At least POINTER_NO_DRAG and KEYBOARD; the registry refuses less. */
    interactionModes(): readonly InteractionMode[];
    /**
     * Required when POINTER_DRAG is declared without the other two. The
     * ledger stores it and the authoring UI surfaces it.
     */
    essentialDraggingJustification?(): string;
    /**
     * The identifier of a conformance kit result. A module with none
     * registers in a visibly flagged state rather than being refused.
     */
    conformanceResult?(): string;
    /**
     * Which definition schema_versions this renderer can read. Absent means
     * [1]. A served definition outside the list is a registry miss and takes
     * the same shape-keyed path as an unregistered key, rather than being
     * handed to a renderer that will read fields that are no longer there.
     */
    supportedDefinitionVersions?(): readonly number[];
}

/**
 * What describeResponse is told beyond the response and the projection.
 *
 * It was the one module method with no context, so a renderer could not
 * localise the words it writes into a review screen or a grading row, and
 * could not consult the shape the server declared. Adding the parameter is
 * not a breaking change and does not raise the contract version: a module
 * whose describeResponse takes two parameters is still assignable and is
 * still called correctly, because extra arguments are ignored.
 */
export interface DescribeContext {
    /** The request locale, so a review description reads in the site language. */
    locale: string;
    /**
     * The QTI cardinality and base type the server declared for this item,
     * when the wire carried one.
     */
    responseShape: ResponseShapeRef | null;
}

/** The shape as the wire spells it; parsed by the shell, not by a module. */
export interface ResponseShapeRef {
    cardinality: string;
    baseType: string | null;
}

/** What answeredStatus is told beyond the response itself. */
export interface AnsweredStatusContext {
    definition: QuestionDefinition;
    /** False before the learner has been shown the item at all. */
    presented: boolean;
    errors?: ReadonlyArray<QuestionError>;
}

/* ── The registry surface a third party sees ────────────────────────── */

export interface RegistryEntry {
    key: string;
    module: QuestionTypeClientModule;
    /** True when no conformance kit result was declared. */
    flagged: boolean;
    modes: readonly InteractionMode[];
    essentialDraggingJustification: string | null;
    conformanceResult: string | null;
}

export type RegistryListener = (event: { kind: 'register' | 'replace'; key: string }) => void;

export interface QuestionTypeRegistryApi {
    readonly contract: number;
    register(typeId: string, module: QuestionTypeClientModule): void;
    /** Decorates: the displaced module is returned so an override can delegate. */
    replace(typeId: string, module: QuestionTypeClientModule): QuestionTypeClientModule;
    has(typeId: string): boolean;
    get(typeId: string): QuestionTypeClientModule | null;
    entry(typeId: string): RegistryEntry | null;
    keys(): readonly string[];
    entries(): readonly RegistryEntry[];
    getInstance(typeId: string, el: HTMLElement, ctx: QuestionContext): QuestionInstance;
    /** A deferred chunk that registers late still renders without a reload. */
    subscribe(listener: RegistryListener): () => void;
}

export class QuestionTypeRegistrationError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'QuestionTypeRegistrationError';
    }
}
