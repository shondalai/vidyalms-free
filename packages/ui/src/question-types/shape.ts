import { shellStrings as strings } from './strings';

/*
 * The response shape taxonomy, which is QTI's own cardinality and base type
 * vocabulary and a real column on the questions table. It is the reason a
 * registry miss can be graceful rather than blunt: core knows the shape of
 * every stored answer even when no renderer is installed, so a missing
 * associate renderer still shows a readable list of the learner's chosen
 * pairs and a missing point renderer still shows the coordinate list.
 *
 * The names are pinned here rather than spelled as string literals across
 * the client, so a future QTI vocabulary revision produces a compile-time
 * list of affected call sites instead of a search.
 */

export const CARDINALITIES = ['single', 'multiple', 'ordered', 'record'] as const;
export type Cardinality = (typeof CARDINALITIES)[number];

export const BASE_TYPES = [
    'boolean',
    'directed_pair',
    'duration',
    'file',
    'float',
    'identifier',
    'integer',
    'pair',
    'point',
    'string',
    'uri',
] as const;
export type BaseType = (typeof BASE_TYPES)[number];

export interface ResponseShape {
    cardinality: Cardinality;
    baseType: BaseType | null;
    /**
     * The field signature of a record shape, in the order the column carries
     * it. Empty for a bare "record", which is what the column holds when the
     * full signature is longer than it.
     */
    fields?: readonly ResponseField[];
}

export interface ResponseField {
    name: string;
    cardinality: Cardinality;
    baseType: BaseType | null;
}

function asCardinality(value: unknown): Cardinality | null {
    return typeof value === 'string' && (CARDINALITIES as readonly string[]).includes(value)
        ? (value as Cardinality)
        : null;
}

function asBaseType(value: unknown): BaseType | null {
    return typeof value === 'string' && (BASE_TYPES as readonly string[]).includes(value)
        ? (value as BaseType)
        : null;
}

/**
 * Reads the shape off a served question. Three spellings are accepted because
 * the column is one string with two forms and the object form is what a
 * hand-written fixture reaches for: "multiple:directed_pair",
 * "record(blank:single:string)" and { cardinality, base_type }.
 *
 * The record form used to return null, because the string was split on ":"
 * and "record(blank" is not a cardinality. That is every record-shaped
 * question, which is exactly the population the shape-keyed fallback exists
 * for: a fill-in-the-blank whose renderer is missing or version-mismatched
 * fell through to a placeholder with no shape and guessed from the raw
 * answer.
 */
export function readResponseShape(source: unknown): ResponseShape | null {
    if (typeof source === 'string') {
        return readShapeString(source);
    }
    if (typeof source !== 'object' || source === null) {
        return null;
    }
    const record = source as Record<string, unknown>;
    const card = asCardinality(record.cardinality);
    if (card === null) {
        return null;
    }
    return { cardinality: card, baseType: asBaseType(record.base_type ?? record.baseType) };
}

function readShapeString(source: string): ResponseShape | null {
    const trimmed = source.trim();

    /*
     * The column is 30 characters, so a long signature is stored as the bare
     * head "record". Both forms are the same shape as far as every reader
     * here is concerned; only the field list differs.
     */
    if (trimmed === 'record') {
        return { cardinality: 'record', baseType: null, fields: [] };
    }

    const match = /^record\((.*)\)$/.exec(trimmed);
    if (match !== null) {
        return { cardinality: 'record', baseType: null, fields: readFields(match[1] ?? '') };
    }

    const [cardinality, baseType] = trimmed.split(':');
    const card = asCardinality(cardinality);
    return card === null ? null : { cardinality: card, baseType: asBaseType(baseType) };
}

function readFields(signature: string): readonly ResponseField[] {
    if (signature.trim() === '') {
        return [];
    }
    const fields: ResponseField[] = [];
    for (const part of signature.split(',')) {
        const [name, cardinality, baseType] = part.split(':');
        const card = asCardinality(cardinality);
        if (name === undefined || name === '' || card === null) {
            continue;
        }
        fields.push({ name, cardinality: card, baseType: asBaseType(baseType) });
    }
    return fields;
}

/**
 * The last resort when the served question carries no shape at all, which is
 * every question served by a host older than the column. Guessing from the
 * stored answer is worse than reading the column and better than a blunt
 * unsupported panel.
 */
export function inferResponseShape(response: unknown): ResponseShape | null {
    if (response === null || response === undefined) {
        return null;
    }
    if (typeof response === 'boolean') {
        return { cardinality: 'single', baseType: 'boolean' };
    }
    if (typeof response === 'number') {
        return { cardinality: 'single', baseType: Number.isInteger(response) ? 'integer' : 'float' };
    }
    if (typeof response === 'string') {
        return { cardinality: 'single', baseType: 'string' };
    }
    if (Array.isArray(response)) {
        const first = response[0];
        if (Array.isArray(first) && first.length === 2) {
            return { cardinality: 'multiple', baseType: 'directed_pair' };
        }
        if (typeof first === 'object' && first !== null && 'x' in first && 'y' in first) {
            return { cardinality: 'multiple', baseType: 'point' };
        }
        return { cardinality: 'ordered', baseType: 'identifier' };
    }
    if (typeof response === 'object') {
        return { cardinality: 'record', baseType: null };
    }
    return null;
}

/*
 * The entry vocabulary the fallback reads, kept deliberately wide and in one
 * place. The free six identify a list entry by "key" and name it with
 * "label"; every schema in the catalogue identifies by "id" and names with
 * "text" or "name". A reader that knew only the free spelling printed raw
 * identifiers at exactly the moment it was supposed to be graceful, which is
 * when the renderer for a type is missing.
 */
const ENTRY_LIST_FIELDS = [
    'options',
    'items',
    'choices',
    'sources',
    'targets',
    'regions',
    'blanks',
    'bank',
    'gaps',
] as const;

const ENTRY_ID_KEYS = ['key', 'id'] as const;
const ENTRY_LABEL_KEYS = ['label', 'text', 'name'] as const;

function labelFor(definition: Record<string, unknown>, key: string): string {
    for (const field of ENTRY_LIST_FIELDS) {
        const list = definition[field];
        if (!Array.isArray(list)) {
            continue;
        }
        for (const entry of list) {
            if (typeof entry !== 'object' || entry === null) {
                continue;
            }
            const record = entry as Record<string, unknown>;
            if (!ENTRY_ID_KEYS.some((name) => record[name] === key)) {
                continue;
            }
            for (const name of ENTRY_LABEL_KEYS) {
                const label = record[name];
                if (typeof label === 'string' && label !== '') {
                    return label;
                }
            }
        }
    }
    return key;
}

function scalarText(value: unknown, definition: Record<string, unknown>): string {
    if (typeof value === 'boolean') {
        return value ? strings.shapeYes : strings.shapeNo;
    }
    if (typeof value === 'number') {
        return String(value);
    }
    if (typeof value === 'string') {
        return labelFor(definition, value);
    }
    if (Array.isArray(value) && value.length === 2 && value.every((v) => typeof v === 'string')) {
        return strings.shapePair(labelFor(definition, value[0] as string), labelFor(definition, value[1] as string));
    }
    if (typeof value === 'object' && value !== null) {
        const record = value as Record<string, unknown>;
        if (typeof record.x === 'number' && typeof record.y === 'number') {
            return strings.shapePoint(record.x, record.y);
        }
        if (typeof record.name === 'string') {
            return record.name;
        }
        if (typeof record.filename === 'string') {
            return record.filename;
        }
    }
    return '';
}

/** One readable line per element of the response, in presentation order. */
export function describeByShape(
    response: unknown,
    shape: ResponseShape | null,
    definition: Record<string, unknown> = {},
): string[] {
    if (response === null || response === undefined || response === '') {
        return [];
    }
    const resolved = shape ?? inferResponseShape(response);
    if (resolved !== null && resolved.cardinality === 'record' && !Array.isArray(response)
        && typeof response === 'object') {
        return Object.entries(response as Record<string, unknown>)
            .map(([key, value]) => {
                const text = scalarText(value, definition);
                return text === '' ? '' : `${labelFor(definition, key)}: ${text}`;
            })
            .filter((line) => line !== '');
    }
    if (Array.isArray(response)) {
        return response.map((entry) => scalarText(entry, definition)).filter((line) => line !== '');
    }
    if (typeof response === 'object') {
        return Object.entries(response as Record<string, unknown>)
            .map(([key, value]) => {
                const text = scalarText(value, definition);
                return text === '' ? '' : `${labelFor(definition, key)}: ${text}`;
            })
            .filter((line) => line !== '');
    }
    const single = scalarText(response, definition);
    return single === '' ? [] : [single];
}

/** Whether a response of this shape counts as answered. */
export function isNonEmptyResponse(response: unknown): boolean {
    if (response === null || response === undefined) {
        return false;
    }
    if (typeof response === 'boolean' || typeof response === 'number') {
        return true;
    }
    if (typeof response === 'string') {
        return response.trim() !== '';
    }
    if (Array.isArray(response)) {
        return response.some((entry) => isNonEmptyResponse(entry));
    }
    if (typeof response === 'object') {
        const record = response as Record<string, unknown>;
        /* The QTI Appendix C envelope: { base: null } is explicitly no answer. */
        if ('base' in record && Object.keys(record).length === 1) {
            return isNonEmptyResponse(record.base);
        }
        if ('list' in record && Object.keys(record).length === 1) {
            return isNonEmptyResponse(record.list);
        }
        return Object.values(record).some((value) => isNonEmptyResponse(value));
    }
    return false;
}
