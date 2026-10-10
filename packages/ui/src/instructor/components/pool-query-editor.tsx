import { translate as uiText } from '@/shared/i18n';
import { cn } from '@/shared/cn';
import { Button } from '@/shared/components';
import { assessmentStrings as strings } from '../assessment-strings';
import { CheckboxField, Field, Select, TextInput } from './field';
import type { PoolQuery, ProviderOption } from '../lib/assessment-types';

/*
 * The predicate tree a random draw selects from.
 *
 * A pool stores a serialised predicate tree and never a pair of columns:
 * "and", "or" and "not" over typed leaves whose kind is resolved through a
 * compiler registry, so a package registers a predicate kind such as a
 * difficulty band from a response model without a contract change. This
 * editor therefore treats the leaf kind as an open string: the eight free
 * kinds get a name and a shaped control, and a kind it has never heard of
 * keeps its key, its comparison and its value rather than being dropped on
 * the next save.
 *
 * The comparison operators are QTI's own selection metadata set, which is
 * where the vocabulary comes from.
 */

const COMBINATORS = ['and', 'or', 'not'] as const;
type Combinator = (typeof COMBINATORS)[number];

function isCombinator(kind: string): kind is Combinator {
    return (COMBINATORS as readonly string[]).includes(kind);
}

/** The leaf kinds the free build compiles. A contributed kind is not in this list and still works. */
export const FREE_LEAF_KINDS = [
    'category',
    'tag',
    'question_type',
    'difficulty',
    'objective',
    'status',
    'author',
    'last_used_before',
] as const;

const OPERATORS = ['eq', 'ne', 'in', 'not_in', 'lt', 'lte', 'gt', 'gte'] as const;

/** Leaf kinds whose operand is a row id rather than a word. */
const ID_KINDS = new Set(['category', 'objective', 'author']);

function leafLabel(kind: string): string {
    const label = LEAF_LABELS[kind];
    return label ?? strings.slots.queryUnknownKind(kind);
}

const LEAF_LABELS: Record<string, string> = {
    category: 'Folder',
    tag: 'Tag',
    question_type: 'Question type',
    difficulty: 'Difficulty band',
    objective: 'Learning objective',
    status: 'Status',
    author: 'Author',
    last_used_before: 'Last used before',
};

const OPERATOR_LABELS: Record<string, string> = {
    eq: 'is',
    ne: 'is not',
    in: 'is one of',
    not_in: 'is none of',
    lt: 'is less than',
    lte: 'is at most',
    gt: 'is more than',
    gte: 'is at least',
};

function scalar(kind: string, raw: string): unknown {
    const trimmed = raw.trim();
    if (trimmed === '') {
        return null;
    }
    if (ID_KINDS.has(kind)) {
        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : trimmed;
    }
    return trimmed;
}

/** The stored operand written into one text control, and read back out of it. */
export function valueToText(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (Array.isArray(value)) {
        return value.map((entry) => String(entry)).join(', ');
    }
    return String(value);
}

export function textToValue(kind: string, operator: string, raw: string): unknown {
    if (operator === 'in' || operator === 'not_in') {
        return raw
            .split(',')
            .map((part) => scalar(kind, part))
            .filter((entry) => entry !== null);
    }
    return scalar(kind, raw);
}

/** An empty leaf, which is what "Add a condition" produces. */
export function blankLeaf(): PoolQuery {
    return { kind: 'tag', operator: 'eq', value: null } as PoolQuery;
}

export function blankGroup(): PoolQuery {
    return { kind: 'and', of: [blankLeaf()] } as PoolQuery;
}

function replaceChild(node: PoolQuery, index: number, child: PoolQuery | null): PoolQuery {
    const children = [...(node.of ?? [])];
    if (child === null) {
        children.splice(index, 1);
    } else {
        children[index] = child;
    }
    return { ...node, of: children };
}

export interface PoolQueryEditorProps {
    query: PoolQuery;
    onChange: (query: PoolQuery) => void;
    /** Whether this node may be removed; a root has nothing to be removed from. */
    onRemove?: () => void;
    depth?: number;
    /** True while the whole editor is read only, such as during a save. */
    disabled?: boolean;
    /**
     * The predicate kinds this site registers, from the slot tree's own
     * vocabularies.
     *
     * The list used to be the hardcoded free eight, so a registered Pro
     * predicate such as irt_difficulty_between could not be authored at all
     * and was only preserved if it was already stored. The free eight remain
     * the fallback for a server that sends no vocabulary.
     */
    kinds?: readonly ProviderOption[];
}

export function PoolQueryEditor({
    query,
    onChange,
    onRemove,
    depth = 0,
    disabled = false,
    kinds,
}: PoolQueryEditorProps) {
    const kind = typeof query.kind === 'string' ? query.kind : 'tag';
    const vocabulary: readonly ProviderOption[] =
        kinds !== undefined && kinds.length > 0
            ? kinds
            : FREE_LEAF_KINDS.map((leaf) => ({ key: leaf, label: leafLabel(leaf) }));

    if (isCombinator(kind)) {
        const children = query.of ?? [];
        return (
            <fieldset
                className={cn(
                    'grid gap-2 rounded border border-border p-2.5',
                    depth > 0 && 'bg-bg',
                )}
            >
                <legend className="px-1 font-display text-[11px] font-semibold text-fg-2">
                    {kind === 'and'
                        ? strings.slots.queryAll
                        : kind === 'or'
                          ? strings.slots.queryAny
                          : strings.slots.queryNot}
                </legend>
                <div className="flex flex-wrap items-center gap-2">
                    <Field label={strings.slots.queryKind} hideLabel className="w-[130px]">
                        {({ id }) => (
                            <Select
                                id={id}
                                className="h-8"
                                disabled={disabled}
                                value={kind}
                                onChange={(event) => onChange({ ...query, kind: event.target.value })}
                            >
                                <option value="and">{strings.slots.queryAll}</option>
                                <option value="or">{strings.slots.queryAny}</option>
                                <option value="not">{strings.slots.queryNot}</option>
                            </Select>
                        )}
                    </Field>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={disabled}
                        onClick={() => onChange({ ...query, of: [...children, blankLeaf()] })}
                    >
                        {strings.slots.queryAddLeaf}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={disabled}
                        onClick={() => onChange({ ...query, of: [...children, blankGroup()] })}
                    >
                        {strings.slots.queryAddGroup}
                    </Button>
                    {onRemove !== undefined && (
                        <Button variant="ghost" size="sm" disabled={disabled} onClick={onRemove}>
                            {strings.slots.queryRemove}
                        </Button>
                    )}
                </div>
                <div className="grid gap-2 pl-3">
                    {children.map((child, index) => (
                        <PoolQueryEditor
                            key={index}
                            query={child}
                            depth={depth + 1}
                            disabled={disabled}
                            kinds={kinds}
                            onChange={(next) => onChange(replaceChild(query, index, next))}
                            onRemove={() => onChange(replaceChild(query, index, null))}
                        />
                    ))}
                </div>
            </fieldset>
        );
    }

    const operator = typeof query.operator === 'string' ? query.operator : 'eq';
    const known = vocabulary.some((option) => option.key === kind);

    return (
        <div className="flex flex-wrap items-end gap-2 rounded border border-border bg-surface p-2.5">
            <Field label={strings.slots.queryKind} className="w-[150px]">
                {({ id }) => (
                    <Select
                        id={id}
                        className="h-8"
                        disabled={disabled}
                        value={kind}
                        onChange={(event) => onChange({ ...query, kind: event.target.value })}
                    >
                        {/* A stored kind this site cannot resolve stays
                            selected rather than silently becoming a tag on
                            the next save. */}
                        {!known && <option value={kind}>{leafLabel(kind)}</option>}
                        {vocabulary.map((option) => (
                            <option key={option.key} value={option.key}>
                                {option.label}
                            </option>
                        ))}
                        <option value="and">{strings.slots.queryAll}</option>
                        <option value="or">{strings.slots.queryAny}</option>
                    </Select>
                )}
            </Field>
            <Field label={strings.slots.queryOperator} className="w-[120px]">
                {({ id }) => (
                    <Select
                        id={id}
                        className="h-8"
                        disabled={disabled}
                        value={operator}
                        onChange={(event) => onChange({ ...query, operator: event.target.value as 'eq' })}
                    >
                        {OPERATORS.map((entry) => (
                            <option key={entry} value={entry}>
                                {OPERATOR_LABELS[entry] ?? entry}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
            <Field label={strings.slots.queryValue} className="min-w-[160px] flex-1">
                {({ id }) => (
                    <TextInput
                        id={id}
                        className="h-8"
                        disabled={disabled}
                        value={valueToText(query.value)}
                        onChange={(event) =>
                            onChange({ ...query, value: textToValue(kind, operator, event.target.value) })
                        }
                    />
                )}
            </Field>
            {kind === 'category' && (
                <CheckboxField
                    label={uiText("vidyalms.ui.52851f18abcf0ad0", "Include subfolders")}
                    checked={query.include_subcategories === true}
                    disabled={disabled}
                    onChange={(event) => onChange({ ...query, include_subcategories: event.target.checked })}
                />
            )}
            {onRemove !== undefined && (
                <Button variant="ghost" size="sm" disabled={disabled} onClick={onRemove}>
                    {strings.slots.queryRemove}
                </Button>
            )}
        </div>
    );
}
