/*
 * The learner-safe definition parsers of the six free types now live beside
 * the modules that register them, because the same parsers are used by the
 * quiz runner, by the result screen and by the instructor workbench, which
 * are separate bundles. Re-exported here so the runner's own call sites and
 * its tests keep one import path.
 */
export {
    parseBlankPrompt,
    parseChoiceDefinition,
    parseFillBlankDefinition,
    parseOrderingDefinition,
    parsePromptDefinition,
    type ChoiceDefinition,
    type FillBlankDefinition,
    type KeyedOption,
    type OrderingDefinition,
    type ParsedPrompt,
    type PromptDefinition,
    type PromptSegment,
} from '@/question-types/free/definitions';
