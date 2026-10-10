/*
 * Learner-safe question definition shapes, exactly what the core's
 * presentForLearner emits per type (see packages/core Provider/QuestionType).
 * The server is trusted but the wire shape is unknown to the compiler, so
 * every parser narrows defensively and returns null on anything malformed;
 * the renderer then falls back to the unsupported-question state instead of
 * crashing the attempt.
 */

export interface KeyedOption {
    key: string;
    label: string;
}

export interface ChoiceDefinition {
    prompt: string;
    options: KeyedOption[];
}

export interface PromptDefinition {
    prompt: string;
}

export interface FillBlankDefinition {
    prompt: string;
    blanks: { key: string }[];
}

export interface OrderingDefinition {
    prompt: string;
    items: KeyedOption[];
}

type RawDefinition = Record<string, unknown>;

function promptOf(raw: RawDefinition): string | null {
    return typeof raw.prompt === 'string' ? raw.prompt : null;
}

function keyedList(value: unknown): KeyedOption[] | null {
    if (!Array.isArray(value) || value.length === 0) {
        return null;
    }
    const out: KeyedOption[] = [];
    for (const entry of value) {
        if (typeof entry !== 'object' || entry === null) {
            return null;
        }
        const { key, label } = entry as { key?: unknown; label?: unknown };
        if (typeof key !== 'string' || key === '') {
            return null;
        }
        out.push({ key, label: typeof label === 'string' ? label : key });
    }
    return out;
}

export function parseChoiceDefinition(raw: RawDefinition): ChoiceDefinition | null {
    const prompt = promptOf(raw);
    const options = keyedList(raw.options);
    return prompt !== null && options !== null ? { prompt, options } : null;
}

export function parsePromptDefinition(raw: RawDefinition): PromptDefinition | null {
    const prompt = promptOf(raw);
    return prompt !== null ? { prompt } : null;
}

export function parseFillBlankDefinition(raw: RawDefinition): FillBlankDefinition | null {
    const prompt = promptOf(raw);
    if (prompt === null || !Array.isArray(raw.blanks) || raw.blanks.length === 0) {
        return null;
    }
    const blanks: { key: string }[] = [];
    for (const entry of raw.blanks) {
        if (typeof entry !== 'object' || entry === null) {
            return null;
        }
        const { key } = entry as { key?: unknown };
        if (typeof key !== 'string' || key === '') {
            return null;
        }
        blanks.push({ key });
    }
    return { prompt, blanks };
}

export function parseOrderingDefinition(raw: RawDefinition): OrderingDefinition | null {
    const prompt = promptOf(raw);
    const items = keyedList(raw.items);
    return prompt !== null && items !== null ? { prompt, items } : null;
}

/*
 * Fill-blank prompts carry one [[key]] marker per blank. The parser splits
 * the prompt into text and blank segments in reading order; a marker whose
 * key is not declared in blanks stays literal text, and a declared blank
 * whose marker is missing from the prompt (the server validates against
 * this, but stored data may predate a fix) is appended at the end so the
 * learner can still answer it.
 */
export type PromptSegment = { kind: 'text'; text: string } | { kind: 'blank'; key: string; index: number };

export interface ParsedPrompt {
    segments: PromptSegment[];
    blankCount: number;
}

export function parseBlankPrompt(prompt: string, blanks: { key: string }[]): ParsedPrompt {
    const declared = new Set(blanks.map((b) => b.key));
    const seen = new Set<string>();
    const segments: PromptSegment[] = [];
    const marker = /\[\[([^\]]+)\]\]/g;
    let cursor = 0;
    let index = 0;
    for (let match = marker.exec(prompt); match !== null; match = marker.exec(prompt)) {
        const key = match[1] ?? '';
        if (!declared.has(key) || seen.has(key)) {
            continue;
        }
        if (match.index > cursor) {
            segments.push({ kind: 'text', text: prompt.slice(cursor, match.index) });
        }
        index += 1;
        segments.push({ kind: 'blank', key, index });
        seen.add(key);
        cursor = match.index + match[0].length;
    }
    if (cursor < prompt.length) {
        segments.push({ kind: 'text', text: prompt.slice(cursor) });
    }
    for (const blank of blanks) {
        if (!seen.has(blank.key)) {
            index += 1;
            segments.push({ kind: 'blank', key: blank.key, index });
            seen.add(blank.key);
        }
    }
    return { segments, blankCount: index };
}
