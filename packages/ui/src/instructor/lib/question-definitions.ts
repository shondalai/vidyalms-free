/*
 * Compatibility surface for the course workbench. The implementation is
 * mount-neutral because the same provider projections now power the admin
 * question-bank editor.
 */
export {
    blankQuestionValues,
    needsManualMarking,
    questionDefinition,
    questionFormSchema,
    questionFormValues,
    linesOf,
    nextKey,
    type BlankValue,
    type ItemValue,
    type OptionValue,
    type QuestionFormValues,
} from '@/question-types/authoring-form';
export { FREE_QUESTION_TYPES, isFreeQuestionType, type FreeQuestionType } from '@/question-types';
