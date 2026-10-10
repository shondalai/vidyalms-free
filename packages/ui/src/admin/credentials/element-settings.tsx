import type { components } from '@vidyalms/api-sdk';
import { SelectField, TextField } from '../components/field';
import { credentialStrings } from '../credential-strings';
import { CheckboxField, TextAreaField } from './fields';

/*
 * The element payload form, drawn from the element type's own settings
 * schema and never from a field list in this file.
 *
 * The point of the element registry is that a new element appears in the
 * palette with no client change, so nothing here may know an element key, a
 * field name or a group name. Labels, help text, defaults, bounds and the
 * options this site can honour all arrive in the answer; this module knows
 * only the eight field types the contract declares, which is the one thing a
 * renderer has to know.
 *
 * The payload is replaced wholesale rather than merged, so a field the
 * author never touches has to travel with the value the server just
 * reported rather than be omitted and take a default that may not be what
 * the element is set to.
 */

type SettingsField = components['schemas']['SettingsField'];
type SettingsGroup = components['schemas']['SettingsGroup'];

export type ElementPayload = Record<string, unknown>;

const strings = credentialStrings;

function record(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

/** The stored value if the payload has one, otherwise the declared default. */
function initialValue(field: SettingsField, stored: ElementPayload): unknown {
    return Object.prototype.hasOwnProperty.call(stored, field.key) ? stored[field.key] : field.default;
}

/**
 * The value object the form starts from: the schema's shape filled with what
 * the element actually holds. Fields the schema does not declare are kept as
 * they are, because dropping them would quietly delete an author's work the
 * moment a package narrowed its own schema.
 */
export function seedElementPayload(groups: readonly SettingsGroup[], stored: ElementPayload): ElementPayload {
    const values: ElementPayload = { ...stored };
    for (const group of groups) {
        for (const field of group.fields) {
            if (field.type === 'object') {
                values[field.key] = seedElementPayload(
                    [{ ...group, fields: field.fields ?? [] }],
                    record(values[field.key]),
                );
                continue;
            }
            values[field.key] = initialValue(field, stored);
        }
    }
    return values;
}

function asText(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    return '';
}

function numberError(field: SettingsField, raw: string): string | undefined {
    if (raw.trim() === '') {
        return undefined;
    }
    const parsed = Number(raw);
    if (!Number.isFinite(parsed)) {
        return field.type === 'integer' ? strings.form.wholeNumber : strings.form.number;
    }
    if (field.type === 'integer' && !Number.isInteger(parsed)) {
        return strings.form.wholeNumber;
    }
    const minimum = typeof field.minimum === 'number' ? field.minimum : null;
    const maximum = typeof field.maximum === 'number' ? field.maximum : null;
    if (minimum !== null && maximum !== null && (parsed < minimum || parsed > maximum)) {
        return strings.form.between(minimum, maximum);
    }
    if (minimum !== null && parsed < minimum) {
        return strings.form.atLeast(minimum);
    }
    if (maximum !== null && parsed > maximum) {
        return strings.form.atMost(maximum);
    }
    return undefined;
}

export interface ElementSettingsFormProps {
    groups: readonly SettingsGroup[];
    values: ElementPayload;
    onChange: (values: ElementPayload) => void;
    disabled?: boolean;
}

export function ElementSettingsForm({ groups, values, onChange, disabled }: ElementSettingsFormProps) {
    if (groups.length === 0) {
        return null;
    }
    return (
        <div className="grid gap-4">
            {groups.map((group) => (
                <fieldset key={group.key} className="grid gap-2.5">
                    <legend className="font-display text-[11.5px] font-bold uppercase tracking-wide text-fg-2">
                        {group.label}
                    </legend>
                    {group.description !== undefined && group.description !== null && (
                        <p className="text-[11.5px] text-fg-2">{group.description}</p>
                    )}
                    {group.fields.map((field) => (
                        <FieldControl
                            key={field.key}
                            field={field}
                            value={values[field.key]}
                            disabled={disabled}
                            onChange={(next) => onChange({ ...values, [field.key]: next })}
                        />
                    ))}
                </fieldset>
            ))}
        </div>
    );
}

function FieldControl({
    field,
    value,
    onChange,
    disabled,
}: {
    field: SettingsField;
    value: unknown;
    onChange: (value: unknown) => void;
    disabled?: boolean;
}) {
    const hint = field.description ?? undefined;
    const unavailable = field.available === false;
    const describedHint =
        unavailable && field.unavailable_reason !== undefined && field.unavailable_reason !== null
            ? field.unavailable_reason
            : hint;

    if (field.type === 'object') {
        return (
            <div className="rounded border border-border p-2.5">
                <p className="mb-2 font-display text-[11.5px] font-semibold text-fg">{field.label}</p>
                <ElementSettingsForm
                    groups={[{ key: field.key, label: field.label, fields: field.fields ?? [] }]}
                    values={record(value)}
                    disabled={disabled}
                    onChange={(next) => onChange(next)}
                />
            </div>
        );
    }

    if (field.type === 'boolean') {
        return (
            <CheckboxField
                label={field.label}
                hint={describedHint}
                checked={value === true}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
            />
        );
    }

    if (field.type === 'enum') {
        return (
            <SelectField
                label={field.label}
                hint={describedHint}
                value={asText(value)}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">{strings.form.unset}</option>
                {(field.options ?? []).map((option) => (
                    <option key={option.key} value={option.key}>
                        {option.label}
                    </option>
                ))}
            </SelectField>
        );
    }

    if (field.type === 'integer' || field.type === 'number' || field.type === 'duration') {
        const raw = asText(value);
        return (
            <TextField
                label={field.label}
                hint={describedHint}
                error={numberError(field, raw)}
                inputMode="decimal"
                value={raw}
                disabled={disabled}
                onChange={(event) => {
                    const next = event.target.value;
                    onChange(next.trim() === '' ? null : Number(next));
                }}
            />
        );
    }

    if (field.type === 'datetime') {
        return (
            <TextField
                label={field.label}
                hint={describedHint}
                value={asText(value)}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
            />
        );
    }

    /* A string field long enough to wrap is authored in a text area: a
       token-carrying line of certificate copy is regularly longer than a
       single-line control shows. */
    if (asText(value).length > 60) {
        return (
            <TextAreaField
                label={field.label}
                hint={describedHint}
                rows={3}
                value={asText(value)}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            />
        );
    }

    return (
        <TextField
            label={field.label}
            hint={describedHint}
            value={asText(value)}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}
