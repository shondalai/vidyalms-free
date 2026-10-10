/*
 * Narrowing readers for the free six. A saved answer arrives as unknown, so
 * every renderer narrows defensively rather than trusting the wire.
 *
 * The bare shapes are schema_version 1 of each type:
 *
 *   single_choice    string, the selected option key
 *   multiple_choice  string[], the selected option keys
 *   true_false       boolean
 *   short_answer     string
 *   fill_blank       { [blankKey]: string }
 *   ordering         string[], the item keys in the learner's order
 *
 * A definition upgraded to schema_version 2 emits the QTI Appendix C
 * envelope instead; the readers below accept the envelope inward so no
 * stored answer and no shipped client breaks when that lands.
 */

function unwrapEnvelope(answer: unknown): unknown {
    if (typeof answer !== 'object' || answer === null || Array.isArray(answer)) {
        return answer;
    }
    const record = answer as Record<string, unknown>;
    const keys = Object.keys(record);
    if (keys.length !== 1) {
        return answer;
    }
    if (keys[0] === 'base') {
        const base = record.base;
        if (typeof base === 'object' && base !== null && !Array.isArray(base)) {
            const inner = base as Record<string, unknown>;
            const innerKeys = Object.keys(inner);
            return innerKeys.length === 1 ? inner[innerKeys[0] as string] : base;
        }
        return base;
    }
    if (keys[0] === 'list') {
        const list = record.list;
        if (typeof list === 'object' && list !== null && !Array.isArray(list)) {
            const inner = list as Record<string, unknown>;
            const innerKeys = Object.keys(inner);
            return innerKeys.length === 1 ? inner[innerKeys[0] as string] : list;
        }
        return list;
    }
    return answer;
}

export function asString(answer: unknown): string | null {
    const value = unwrapEnvelope(answer);
    return typeof value === 'string' ? value : null;
}

export function asStringArray(answer: unknown): string[] | null {
    const value = unwrapEnvelope(answer);
    if (!Array.isArray(value)) {
        return null;
    }
    return value.filter((entry): entry is string => typeof entry === 'string');
}

export function asBoolean(answer: unknown): boolean | null {
    const value = unwrapEnvelope(answer);
    return typeof value === 'boolean' ? value : null;
}

export function asStringRecord(answer: unknown): Record<string, string> {
    const value = unwrapEnvelope(answer);
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return {};
    }
    const out: Record<string, string> = {};
    for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
        if (typeof entry === 'string') {
            out[key] = entry;
        }
    }
    return out;
}
