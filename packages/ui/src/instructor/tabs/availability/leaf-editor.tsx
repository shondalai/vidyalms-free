import { useConditionEditor, type ConditionTarget } from '@/condition-editors';
import { flowStrings } from '../../flow-strings';
import { CheckboxField, Field, Select, TextInput } from '../../components/field';
import type { AvailabilityConditionInfo, AvailabilityLeaf, ScheduleAnchorInfo } from '../../lib/flow-types';

/*
 * One leaf's editor: the registered one if a package ships it, otherwise the
 * provider's own declared schema, drawn generically.
 *
 * The fallback is the load-bearing half. A condition nobody has written an
 * editor for is still authorable the day it installs, which is the design's
 * literal acceptance criterion for a third-party condition ("core files
 * edited: zero"). Nothing here knows a condition key, a field name or a
 * unit; it knows the four scalar shapes a form can honestly draw and shows
 * anything else as the stored value rather than pretending to understand it.
 */

const strings = flowStrings.availability;

function record(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

export function summariseLeaf(
    leaf: AvailabilityLeaf,
    condition: AvailabilityConditionInfo | null,
    summarise: ((payload: Record<string, unknown>, locale: string) => string | null) | undefined,
    locale: string,
): string {
    const label = condition?.label ?? leaf.type;
    const detail = summarise?.(record(leaf.payload), locale) ?? null;
    return detail === null || detail === '' ? label : `${label}: ${detail}`;
}

/**
 * The provider's params_schema as controls.
 *
 * Draws string, number, integer, boolean and a string with an enum. A
 * property of any other shape is shown read only with what is stored, which
 * is more honest than an input that would write the wrong type into a
 * document the provider owns.
 */
function SchemaFields({
    schema,
    payload,
    onChange,
    errors,
}: {
    schema: Record<string, unknown>;
    payload: Record<string, unknown>;
    onChange: (payload: Record<string, unknown>) => void;
    errors?: Record<string, string>;
}) {
    const properties = record(schema.properties);
    const required = Array.isArray(schema.required)
        ? schema.required.filter((entry): entry is string => typeof entry === 'string')
        : [];
    const entries = Object.entries(properties).filter(([name]) => name !== '_v');
    if (entries.length === 0) {
        return <p className="text-[11.5px] text-fg-2">{strings.rawHint}</p>;
    }
    return (
        <div className="grid gap-2.5 sm:grid-cols-2">
            {entries.map(([name, raw]) => {
                const property = record(raw);
                const type = typeof property.type === 'string' ? property.type : 'string';
                const label = typeof property.title === 'string' ? property.title : name;
                const hint = typeof property.description === 'string' ? property.description : undefined;
                const options = Array.isArray(property.enum)
                    ? property.enum.filter((entry): entry is string => typeof entry === 'string')
                    : null;
                const current = payload[name];
                const error = errors?.[name];

                if (type === 'boolean') {
                    return (
                        <CheckboxField
                            key={name}
                            label={label}
                            hint={hint}
                            checked={current === true}
                            onChange={(event) => onChange({ ...payload, [name]: event.target.checked })}
                        />
                    );
                }
                if (options !== null && options.length > 0) {
                    const value = typeof current === 'string' ? current : (options[0] ?? '');
                    return (
                        <Field key={name} label={label} hint={hint} error={error}>
                            {({ id, describedBy, invalid }) => (
                                <Select
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={value}
                                    onChange={(event) => onChange({ ...payload, [name]: event.target.value })}
                                >
                                    {options.map((option) => (
                                        <option key={option} value={option}>
                                            {option}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    );
                }
                if (type === 'string' || type === 'number' || type === 'integer') {
                    const numeric = type !== 'string';
                    const value =
                        current === null || current === undefined
                            ? ''
                            : typeof current === 'object'
                              ? JSON.stringify(current)
                              : String(current);
                    return (
                        <Field
                            key={name}
                            label={required.includes(name) ? label : label}
                            hint={hint}
                            error={error}
                        >
                            {({ id, describedBy, invalid }) => (
                                <TextInput
                                    id={id}
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    inputMode={numeric ? 'decimal' : undefined}
                                    value={value}
                                    onChange={(event) => {
                                        const raw2 = event.target.value;
                                        onChange({
                                            ...payload,
                                            [name]:
                                                raw2.trim() === ''
                                                    ? null
                                                    : numeric
                                                      ? Number(raw2)
                                                      : raw2,
                                        });
                                    }}
                                />
                            )}
                        </Field>
                    );
                }
                return (
                    <div key={name} className="grid gap-1 sm:col-span-2">
                        <span className="font-display text-xs font-semibold text-fg">{label}</span>
                        <code className="block overflow-x-auto rounded border border-border bg-bg px-2.5 py-1.5 text-[11.5px] text-fg-2">
                            {JSON.stringify(current ?? null)}
                        </code>
                    </div>
                );
            })}
        </div>
    );
}

export interface LeafEditorProps {
    leaf: AvailabilityLeaf;
    condition: AvailabilityConditionInfo | null;
    anchors: readonly ScheduleAnchorInfo[];
    targets: readonly ConditionTarget[];
    locale: string;
    controlId: (local: string) => string;
    errors?: Record<string, string>;
    onChange: (leaf: AvailabilityLeaf) => void;
}

export function LeafEditor({
    leaf,
    condition,
    anchors,
    targets,
    locale,
    controlId,
    errors,
    onChange,
}: LeafEditorProps) {
    const module = useConditionEditor(leaf.type);
    const payload = record(leaf.payload);
    const setPayload = (next: Record<string, unknown>) => onChange({ ...leaf, payload: next });

    if (condition === null) {
        /* A stored leaf whose provider nothing registers. It is shown and
           kept rather than dropped: the author has to see what the item is
           gated on, and a save that silently removed it would open content
           the site meant to gate. */
        return (
            <div className="grid gap-2">
                <p className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-2.5 py-1.5 text-[11.5px] text-fg-2">
                    {strings.unavailable(leaf.type)}
                </p>
                <code className="block overflow-x-auto rounded border border-border bg-bg px-2.5 py-1.5 text-[11.5px] text-fg-2">
                    {JSON.stringify(payload)}
                </code>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            {condition.advisory && (
                <p className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-2.5 py-1.5 text-[11.5px] text-fg-2">
                    {strings.advisory}
                </p>
            )}
            {condition.available === false && (
                <p className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-2.5 py-1.5 text-[11.5px] text-fg-2">
                    {strings.unavailable(condition.key)}
                </p>
            )}
            {module === null ? (
                <SchemaFields
                    schema={record(condition.params_schema)}
                    payload={payload}
                    onChange={setPayload}
                    errors={errors}
                />
            ) : (
                <module.Editor
                    condition={condition}
                    payload={payload}
                    onChange={setPayload}
                    anchors={anchors}
                    targets={targets}
                    controlId={controlId}
                    locale={locale}
                    errors={errors}
                />
            )}
        </div>
    );
}
