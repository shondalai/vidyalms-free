/*
 * The client renderer registry.
 *
 * A question type is one registration: a learner renderer behind
 * mount(el, ctx), an authoring block, an answered status, a response
 * description and a declaration of how it is operated. Nothing in the runner
 * or the workbench switches on a type key, which is the fork this layer
 * exists to prevent.
 *
 * See docs/technical/12-assessment-types.md, "The client renderer registry"
 * and "Accessibility", and packages/ui/README.md for how a third party
 * registers one.
 */

export {
    CORE_ERROR_KINDS,
    PUBLISHED_REACT_MAJOR,
    QUESTION_TYPE_CONTRACT,
    QuestionTypeRegistrationError,
    VENDOR_KEY_PREFIX,
    isCoreErrorKind,
    type AccessibilityProfile,
    type AnnounceChannel,
    type AnnounceFn,
    type AnsweredStatus,
    type AnsweredStatusContext,
    type AuthoringContract,
    type AuthoringProps,
    type ChildContext,
    type CoreErrorKind,
    type ErrorKind,
    type FormSchema,
    type FormValues,
    type InteractionMode,
    type MountChild,
    type QuestionContext,
    type QuestionDefinition,
    type QuestionError,
    type QuestionInstance,
    type QuestionShell,
    type QuestionStatus,
    type QuestionTypeClientModule,
    type QuestionTypeRegistryApi,
    type RegistryEntry,
    type RegistryListener,
} from './contract';

export { QuestionTypeRegistry } from './registry';
export {
    installQuestionTypeBridge,
    questionTypeRegistry,
    vidyaLmsBridge,
    vidyaLmsNamespace,
    type VidyaLmsGlobal,
    type VidyaLmsNamespace,
} from './bridge';
export { createReactQuestionModule, type LearnerRendererProps, type ReactQuestionModuleSpec } from './react-module';
export { QuestionHost, type QuestionHostProps } from './host';
export { QuestionAuthoringBlock, type QuestionAuthoringBlockProps } from './authoring-block';
export {
    useQuestionTypeCatalogue,
    type QuestionPresetInfo,
    type QuestionTypeCatalogue,
} from './catalogue';
export {
    blankQuestionValues,
    needsManualMarking,
    questionDefinition,
    questionFormSchema,
    questionFormValues,
    type QuestionFormValues,
} from './authoring-form';
export { ErrorSummary, type ErrorSummaryGroup, type ErrorSummaryProps } from './error-summary';
export { fallbackModule, shapeOfServed } from './fallback';
export {
    resolveAuthoringModule,
    resolveQuestionModule,
    unsupportedVersionOf,
    type ResolvedAuthoring,
    type ResolvedModule,
    type ResolutionSource,
} from './resolve';
export {
    BASE_TYPES,
    CARDINALITIES,
    describeByShape,
    inferResponseShape,
    isNonEmptyResponse,
    readResponseShape,
    type BaseType,
    type Cardinality,
    type ResponseShape,
} from './shape';
export { primitiveStrings, questionTypeStrings, shellStrings } from './strings';
export * from './primitives';
export {
    FREE_QUESTION_TYPES,
    isFreeQuestionType,
    registerFreeQuestionTypes,
    type FreeQuestionType,
} from './free';
