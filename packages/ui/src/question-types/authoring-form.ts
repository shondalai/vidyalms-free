import type { z } from 'zod';
import { questionTypeRegistry } from './bridge';
import type { FormSchema, FormValues, QuestionDefinition } from './contract';
import {
    EMPTY_FORM_VALUES,
    baseQuestionSchema,
    promptOptionalSchema,
    stringOf,
    type QuestionFormValues,
} from './free/form-model';

/*
 * Mount-neutral projection between stored question definitions and the one
 * React Hook Form model used by both the course workbench and the admin bank
 * editor. A provider owns only the keys it declares; every other stored key
 * survives a round trip so removing an add-on never destroys its data.
 */

function authoringOf(type: string) {
    return questionTypeRegistry().get(type)?.authoring ?? null;
}

export function questionFormValues(type: string, definition: QuestionDefinition): QuestionFormValues {
    const base: QuestionFormValues = {
        ...EMPTY_FORM_VALUES,
        prompt: stringOf(definition.prompt),
        feedback: stringOf(definition.feedback),
        caseSensitive: definition.case_sensitive === true,
    };
    const own = authoringOf(type)?.toFormValues(definition) ?? {};
    return { ...base, ...(own as Partial<QuestionFormValues>) };
}

export function blankQuestionValues(type: string): QuestionFormValues {
    const own = authoringOf(type)?.blankFormValues() ?? {};
    return { ...EMPTY_FORM_VALUES, ...(own as Partial<QuestionFormValues>) };
}

const SHARED_KEYS = ['prompt', 'feedback'] as const;

export function questionDefinition(
    type: string,
    values: QuestionFormValues,
    stored: QuestionDefinition = {},
): QuestionDefinition {
    const authoring = authoringOf(type);
    if (authoring === null) {
        return stored;
    }
    const feedback = values.feedback.trim();
    const base: QuestionDefinition = { prompt: values.prompt.trim() };
    if (feedback !== '') {
        base.feedback = feedback;
    }
    const own = authoring.fromFormValues(values as unknown as FormValues, stored);
    const owned = new Set<string>([...SHARED_KEYS, ...authoring.ownedKeys()]);
    const preserved: QuestionDefinition = {};
    for (const [key, value] of Object.entries(stored)) {
        if (!owned.has(key)) {
            preserved[key] = value;
        }
    }
    return { ...preserved, ...base, ...own };
}

export function questionFormSchema(type: string): z.ZodType<QuestionFormValues, QuestionFormValues> {
    const base = baseQuestionSchema();
    const authoring = authoringOf(type);
    const schema: FormSchema = authoring === null ? promptOptionalSchema(base) : authoring.formSchema(base);
    return schema as unknown as z.ZodType<QuestionFormValues, QuestionFormValues>;
}

export function needsManualMarking(type: string, definition: QuestionDefinition): boolean {
    return authoringOf(type)?.needsManualMarking?.(definition) ?? false;
}

export type {
    BlankValue,
    ItemValue,
    OptionValue,
    QuestionFormValues,
} from './free/form-model';
export { linesOf, nextKey } from './free/form-model';
