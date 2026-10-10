import { translate as uiText } from '@/shared/i18n';
import { useId, useState, type ReactNode } from 'react';
import { cn } from '@/shared/cn';
import { assessmentStrings as strings } from '../assessment-strings';
import { CheckboxField, Field, Select, TextInput } from './field';
import type { SettingsField, SettingsGroup, SettingsOption } from '../lib/assessment-types';

/*
 * The quiz policy form, drawn from GET settings-schema and never from a
 * field list in this file.
 *
 * The point of the route is that a new aggregation method or a new attempt
 * behaviour appears in the workbench with no client change, so nothing here
 * may know a provider key, a field name or a group name. Labels, help text,
 * defaults, bounds and the options this site can honour all arrive in the
 * answer; this module knows only the eight field TYPES the contract
 * declares, which is the one thing a renderer has to know.
 *
 * A stored value naming a provider the site no longer has comes back with
 * `available` false and its reason. It is shown, kept and selectable rather
 * than reverted: an author has to see what the quiz was set to as well as
 * what it is running under, and a form that quietly dropped it would write
 * the substitution back into the quiz on the next save.
 */

export type PolicyValues = Record<string, unknown>;

function record(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

/** The stored value if the field has one, otherwise the declared default. */
function initialValue(field: SettingsField): unknown {
    if (field.current !== undefined) {
        return field.current;
    }
    return field.default;
}

/**
 * The value object the form starts from, built from the schema alone.
 *
 * The write is a full replacement, so a field the author never touches has
 * to travel with the value the server just reported rather than be omitted
 * and take a default that may not be what the quiz is set to.
 */
export function seedPolicyValues(groups: readonly SettingsGroup[]): PolicyValues {
    const values: PolicyValues = {};
    for (const group of groups) {
        for (const field of group.fields) {
            if (field.type === 'object') {
                values[field.key] = seedPolicyValues([{ ...group, fields: field.fields ?? [] }]);
                continue;
            }
            values[field.key] = initialValue(field);
            const paramsKey = field.params_key;
            if (typeof paramsKey === 'string' && paramsKey !== '') {
                values[paramsKey] = record(values[paramsKey]);
            }
        }
    }
    return values;
}

/** A value read out of the nested object by its dotted path. */
export function valueAt(values: PolicyValues, path: readonly string[]): unknown {
    let current: unknown = values;
    for (const step of path) {
        current = record(current)[step];
    }
    return current;
}

/** The same object with one path replaced, copied rather than mutated. */
export function withValueAt(values: PolicyValues, path: readonly string[], value: unknown): PolicyValues {
    const [head, ...rest] = path;
    if (head === undefined) {
        return values;
    }
    if (rest.length === 0) {
        return { ...values, [head]: value };
    }
    return { ...values, [head]: withValueAt(record(values[head]), rest, value) };
}

const DATETIME = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

function boundsOf(field: SettingsField): { minimum: number | null; maximum: number | null } {
    const scale = field.type === 'duration' ? 60 : 1;
    const minimum = typeof field.minimum === 'number' ? field.minimum / scale : null;
    const maximum = typeof field.maximum === 'number' ? field.maximum / scale : null;
    return { minimum, maximum };
}

function numberError(field: SettingsField, raw: string): string | null {
    if (raw.trim() === '') {
        return null;
    }
    const parsed = Number(raw);
    if (!Number.isFinite(parsed)) {
        return field.type === 'integer' ? strings.form.wholeNumber : strings.form.number;
    }
    if (field.type === 'integer' && !Number.isInteger(parsed)) {
        return strings.form.wholeNumber;
    }
    const { minimum, maximum } = boundsOf(field);
    if (minimum !== null && maximum !== null && (parsed < minimum || parsed > maximum)) {
        return strings.form.between(minimum, maximum);
    }
    if (minimum !== null && parsed < minimum) {
        return strings.form.atLeast(minimum);
    }
    if (maximum !== null && parsed > maximum) {
        return strings.form.atMost(maximum);
    }
    return null;
}

/** How a value is written into a text control, and read back out of one. */
function toText(field: SettingsField, value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (field.type === 'duration' && typeof value === 'number') {
        return String(Math.round((value / 60) * 100) / 100);
    }
    return String(value);
}

function fromText(field: SettingsField, raw: string): unknown {
    const trimmed = raw.trim();
    if (trimmed === '') {
        return field.type === 'string' || field.type === 'datetime' ? null : null;
    }
    if (field.type === 'duration') {
        return Math.round(Number(trimmed) * 60);
    }
    if (field.type === 'integer' || field.type === 'number') {
        return Number(trimmed);
    }
    return trimmed;
}

/**
 * Every message the schema itself can produce, keyed by dotted path.
 *
 * The server is the authority on validation and answers 422 with the field
 * named; this only spares the obvious round trip, so it checks exactly what
 * the schema declared and invents no rule of its own.
 */
export function validatePolicyValues(
    groups: readonly SettingsGroup[],
    values: PolicyValues,
    prefix: readonly string[] = [],
): Record<string, string> {
    const errors: Record<string, string> = {};
    for (const group of groups) {
        for (const field of group.fields) {
            const path = [...prefix, field.key];
            const key = path.join('.');
            const value = valueAt(values, path);
            if (field.type === 'object') {
                Object.assign(
                    errors,
                    validatePolicyValues([{ ...group, fields: field.fields ?? [] }], values, path),
                );
                continue;
            }
            if (field.type === 'integer' || field.type === 'number' || field.type === 'duration') {
                const message = numberError(field, toText(field, value));
                if (message !== null) {
                    errors[key] = message;
                }
                continue;
            }
            if (field.type === 'datetime' && typeof value === 'string' && value !== '' && !DATETIME.test(value)) {
                errors[key] = strings.form.datetime;
            }
        }
    }
    return errors;
}

function optionLabel(option: SettingsOption): string {
    return option.group === null || option.group === undefined || option.group === ''
        ? option.label
        : `${option.group}: ${option.label}`;
}

/**
 * A JSON Schema fragment from the chosen option, rendered as controls.
 *
 * The provider declares the parameters its option takes and nothing in the
 * core interprets them, so this renders the four scalar types a settings
 * form can honestly draw and leaves anything else to a raw value: pretending
 * to understand a nested schema would be worse than showing what is stored.
 */
function ParamsFields({
    schema,
    values,
    paramsKey,
    onChange,
}: {
    schema: Record<string, unknown>;
    values: PolicyValues;
    paramsKey: string;
    onChange: (path: string[], value: unknown) => void;
}) {
    const properties = record(schema.properties);
    const entries = Object.entries(properties);
    if (entries.length === 0) {
        return null;
    }
    return (
        <div className="mt-2 grid gap-2.5 rounded border border-border bg-bg p-3">
            <p className="font-display text-[11px] font-semibold text-fg-2">{strings.policy.optionParams}</p>
            {entries.map(([name, raw]) => {
                const property = record(raw);
                const type = typeof property.type === 'string' ? property.type : 'string';
                const label = typeof property.title === 'string' ? property.title : name;
                const hint = typeof property.description === 'string' ? property.description : undefined;
                const current = record(values[paramsKey])[name];
                if (type === 'boolean') {
                    return (
                        <CheckboxField
                            key={name}
                            label={label}
                            hint={hint}
                            checked={current === true}
                            onChange={(event) => onChange([paramsKey, name], event.target.checked)}
                        />
                    );
                }
                return (
                    <Field key={name} label={label} hint={hint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                inputMode={type === 'integer' || type === 'number' ? 'decimal' : undefined}
                                value={current === null || current === undefined ? '' : String(current)}
                                onChange={(event) => {
                                    const raw2 = event.target.value;
                                    onChange(
                                        [paramsKey, name],
                                        raw2.trim() === ''
                                            ? null
                                            : type === 'integer' || type === 'number'
                                              ? Number(raw2)
                                              : raw2,
                                    );
                                }}
                            />
                        )}
                    </Field>
                );
            })}
        </div>
    );
}

/** The one warning line a field whose stored provider is gone carries. */
function UnavailableNote({ field }: { field: SettingsField }) {
    return (
        <p className="mt-1 rounded border border-border border-l-[3px] border-l-warn bg-surface px-2.5 py-1.5 text-[11.5px] text-fg-2">
            <strong className="font-semibold text-warn-strong">
                {strings.policy.unavailable(field.unavailable_reason ?? null)}
            </strong>{' '}
            {typeof field.current === 'string' && <span>{strings.policy.storedValue(field.current)}</span>}
        </p>
    );
}

export interface PolicyFieldProps {
    field: SettingsField;
    path: string[];
    values: PolicyValues;
    errors: Record<string, string>;
    onChange: (path: string[], value: unknown) => void;
}

export function PolicyField({ field, path, values, errors, onChange }: PolicyFieldProps): ReactNode {
    const key = path.join('.');
    const value = valueAt(values, path);
    const error = errors[key];
    const hint = field.description ?? undefined;
    const unavailable = field.available === false;
    /*
     * A text control keeps what the author is typing, not the number it has
     * parsed to so far. Reflecting the parsed number back makes a decimal
     * impossible to enter: "0." parses to 0, the box redraws as "0", and the
     * next keystroke gives 02 rather than 0.2, which is exactly the range a
     * try penalty lives in.
     */
    const [typed, setTyped] = useState<string | null>(null);

    if (field.type === 'object') {
        return (
            <fieldset className="grid gap-2.5 rounded border border-border p-3">
                <legend className="px-1 font-display text-[11.5px] font-semibold text-fg">{field.label}</legend>
                {hint !== undefined && <p className="text-[11.5px] text-fg-2">{hint}</p>}
                {(field.fields ?? []).map((child) => (
                    <PolicyField
                        key={child.key}
                        field={child}
                        path={[...path, child.key]}
                        values={values}
                        errors={errors}
                        onChange={onChange}
                    />
                ))}
            </fieldset>
        );
    }

    if (field.type === 'boolean') {
        return (
            <div>
                <CheckboxField
                    label={field.label}
                    hint={hint}
                    checked={value === true}
                    onChange={(event) => onChange(path, event.target.checked)}
                />
                {unavailable && <UnavailableNote field={field} />}
            </div>
        );
    }

    if (field.type === 'enum') {
        const options = field.options ?? [];
        const current = typeof value === 'string' ? value : '';
        const known = options.some((option) => option.key === current);
        const chosen = options.find((option) => option.key === current) ?? null;
        const paramsSchema = record(chosen?.params_schema ?? {});
        const paramsKey = typeof field.params_key === 'string' ? field.params_key : null;
        return (
            <div>
                <Field label={field.label} hint={hint} error={error}>
                    {({ id, describedBy, invalid }) => (
                        <Select
                            id={id}
                            aria-describedby={describedBy}
                            invalid={invalid}
                            value={current}
                            onChange={(event) => onChange(path, event.target.value)}
                        >
                            {/* The stored key stays selectable even when the
                                site cannot honour it, so a save does not
                                quietly write the substitution back. */}
                            {!known && current !== '' && (
                                <option value={current}>{strings.policy.keepStored(current)}</option>
                            )}
                            {options.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {optionLabel(option)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                {chosen?.description !== null && chosen?.description !== undefined && (
                    <p className="mt-1 text-[11.5px] text-fg-2">{chosen.description}</p>
                )}
                {unavailable && <UnavailableNote field={field} />}
                {paramsKey !== null && Object.keys(paramsSchema).length > 0 && (
                    <ParamsFields
                        schema={paramsSchema}
                        values={values}
                        paramsKey={paramsKey}
                        onChange={onChange}
                    />
                )}
            </div>
        );
    }

    const durationHint =
        field.type === 'duration'
            ? hint === undefined
                ? strings.form.durationHint
                : `${hint} ${strings.form.durationHint}`
            : hint;

    return (
        <div>
            <Field label={field.label} hint={durationHint} error={error}>
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        inputMode={
                            field.type === 'integer' || field.type === 'number' || field.type === 'duration'
                                ? 'decimal'
                                : undefined
                        }
                        placeholder={field.type === 'datetime' ? uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss") : undefined}
                        value={typed ?? toText(field, value)}
                        onChange={(event) => {
                            setTyped(event.target.value);
                            onChange(path, fromText(field, event.target.value));
                        }}
                    />
                )}
            </Field>
            {unavailable && <UnavailableNote field={field} />}
        </div>
    );
}

export function PolicyGroup({
    group,
    values,
    errors,
    onChange,
    className,
}: {
    group: SettingsGroup;
    values: PolicyValues;
    errors: Record<string, string>;
    onChange: (path: string[], value: unknown) => void;
    className?: string;
}) {
    const headingId = useId();
    return (
        <section aria-labelledby={headingId} className={cn('grid gap-3', className)}>
            <div>
                <h4 id={headingId} className="font-display text-[12.5px] font-semibold text-fg">
                    {group.label}
                </h4>
                {group.description !== null && group.description !== undefined && (
                    <p className="text-[11.5px] text-fg-2">{group.description}</p>
                )}
            </div>
            {group.fields.map((field) => (
                <PolicyField
                    key={field.key}
                    field={field}
                    path={[field.key]}
                    values={values}
                    errors={errors}
                    onChange={onChange}
                />
            ))}
        </section>
    );
}
