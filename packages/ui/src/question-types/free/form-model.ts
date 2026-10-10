import { z } from 'zod';
import { questionAuthoringStrings as strings } from '@/question-types/authoring-strings';
import type { FormSchema } from '../contract';

/*
 * The shared half of the question authoring form: prompt, points and
 * feedback, which every registered type has, plus the fields the six free
 * types own.
 *
 * The free six's own fields are named here rather than inside each module
 * because react-hook-form cannot type a per-module slice of one form: a
 * field array needs a literal path. A CONTRIBUTED type never touches this
 * file. It reads and writes `extra`, which is opaque to everything except
 * the module that owns the key, so adding a Pro or third-party type is a
 * registration and not an edit to a shared model.
 */

export interface OptionValue {
    key: string;
    label: string;
    correct: boolean;
}

export interface BlankValue {
    key: string;
    /** One accepted answer per line, as the author types them. */
    accepted: string;
}

export interface ItemValue {
    key: string;
    label: string;
}

export interface QuestionFormValues {
    prompt: string;
    points: string;
    feedback: string;
    options: OptionValue[];
    correctBool: 'true' | 'false';
    accepted: string;
    caseSensitive: boolean;
    blanks: BlankValue[];
    items: ItemValue[];
    /** Where a contributed type keeps its own values, keyed by type. */
    extra: Record<string, unknown>;
}

export const EMPTY_FORM_VALUES: QuestionFormValues = {
    prompt: '',
    points: '1',
    feedback: '',
    options: [],
    correctBool: 'true',
    accepted: '',
    caseSensitive: false,
    blanks: [],
    items: [],
    extra: {},
};

/** Lines of a textarea, trimmed, with the blank ones dropped. */
export function linesOf(text: string): string[] {
    return text
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

/** A key no sibling holds yet. Keys are stored with answers, so they never move under one. */
export function nextKey(existing: readonly { key: string }[], prefix: string): string {
    const taken = new Set(existing.map((entry) => entry.key));
    for (let n = existing.length + 1; ; n += 1) {
        const candidate = `${prefix}${n}`;
        if (!taken.has(candidate)) {
            return candidate;
        }
    }
}

export function stringOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

export function stringList(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((entry): entry is string => typeof entry === 'string') : [];
}

export function keyedList(value: unknown): { key: string; label: string }[] {
    if (!Array.isArray(value)) {
        return [];
    }
    const out: { key: string; label: string }[] = [];
    for (const entry of value) {
        if (typeof entry !== 'object' || entry === null) {
            continue;
        }
        const record = entry as Record<string, unknown>;
        if (typeof record.key !== 'string' || record.key === '') {
            continue;
        }
        out.push({ key: record.key, label: stringOf(record.label) });
    }
    return out;
}

const pointsField = z
    .string()
    .refine((value) => value.trim() !== '' && Number.isFinite(Number(value)), strings.form.numberRequired)
    .refine((value) => Number(value) > 0, strings.form.positiveNumber);

/**
 * The shared schema every type's own schema is layered over. It is handed to
 * a module rather than imported by one, so a third party composes without
 * having to restate the shared fields or match our zod version.
 */
export function baseQuestionSchema(): FormSchema {
    return z.object({
        prompt: z.string().trim().min(1, strings.question.promptRequired),
        points: pointsField,
        feedback: z.string(),
        options: z.array(z.object({ key: z.string(), label: z.string(), correct: z.boolean() })),
        correctBool: z.enum(['true', 'false']),
        accepted: z.string(),
        caseSensitive: z.boolean(),
        blanks: z.array(z.object({ key: z.string(), accepted: z.string() })),
        items: z.array(z.object({ key: z.string(), label: z.string() })),
        extra: z.record(z.string(), z.unknown()),
    }) as unknown as FormSchema;
}

/** The base with the prompt requirement dropped, for a type that has no prompt of its own. */
export function promptOptionalSchema(base: FormSchema): FormSchema {
    const zodBase = base as unknown as z.ZodObject<{ prompt: z.ZodType<string> }>;
    return zodBase.extend({ prompt: z.string() }) as unknown as FormSchema;
}

export interface FormIssue {
    path: (string | number)[];
    message: string;
}

/**
 * Layers a type's own rules over the base. Modules return plain issues, so
 * the single cast between the structural FormSchema and zod lives here
 * rather than once per module.
 */
export function refineForm(base: FormSchema, check: (values: QuestionFormValues) => FormIssue[]): FormSchema {
    const zodBase = base as unknown as z.ZodType<QuestionFormValues, QuestionFormValues>;
    return zodBase.superRefine((values, ctx) => {
        for (const issue of check(values)) {
            ctx.addIssue({ code: 'custom', path: issue.path, message: issue.message });
        }
    }) as unknown as FormSchema;
}

export function uniqueKeys(entries: readonly { key: string }[]): boolean {
    return new Set(entries.map((entry) => entry.key)).size === entries.length;
}
