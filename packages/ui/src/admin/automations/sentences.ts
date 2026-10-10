import type { AutomationSentence } from './api';

/*
 * Sentences, never expressions. A trigger, a condition and an action each
 * declare a template with numbered placeholders and a plain readable form,
 * and the builder fills the numbers from the stored structured values. There
 * is no free-text expression language anywhere on this screen, which is the
 * whole reason a non-developer can read a rule aloud.
 *
 * A provider that declares no sentence falls back to its label rather than to
 * invented prose, so a third-party action reads as its own name instead of as
 * a sentence somebody guessed for it.
 */

/** Fills {0}, {1} ... from the values. An unfilled placeholder keeps its slot name. */
export function fillSentence(template: string, values: readonly string[]): string {
    return template.replace(/\{(\d+)\}/g, (whole, index: string) => {
        const value = values[Number(index)];
        return value === undefined || value === '' ? whole : value;
    });
}

/**
 * The readable line for one element. `sentence` wins when the provider
 * declares one; otherwise the label is the sentence.
 */
export function readSentence(
    sentence: AutomationSentence | string | null | undefined,
    label: string,
    values: readonly string[] = [],
): string {
    if (sentence === null || sentence === undefined) {
        return label;
    }
    // Installed providers can publish an untranslated language key instead of a sentence descriptor.
    if (typeof sentence === 'string') return sentence.startsWith('vidyalms.') ? label : sentence;
    /* A step nobody has configured yet reads as its plain form. Filling a
       template whose every value is still empty would show the author the
       placeholder names, which is the expression language in disguise. */
    if (values.some((value) => value !== '')) {
        return fillSentence(sentence.template, values);
    }
    return sentence.readable === '' ? sentence.template : sentence.readable;
}

/** The values a params document contributes, in the placeholder order the provider declared. */
export function placeholderValues(
    sentence: AutomationSentence | string | null | undefined,
    params: Record<string, unknown> | null | undefined,
): string[] {
    if (
        sentence === null ||
        sentence === undefined ||
        typeof sentence === 'string' ||
        sentence.placeholders === undefined
    ) {
        return [];
    }
    return sentence.placeholders.map((name) => {
        const value = params?.[name];
        if (value === null || value === undefined) {
            return '';
        }
        if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }
        return '';
    });
}
