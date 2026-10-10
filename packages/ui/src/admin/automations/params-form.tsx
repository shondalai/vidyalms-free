import { useEffect, useRef, useState } from 'react';
import { readable } from './workspace';
import { automationStrings as strings } from './strings';

export type ParamsSchema = Record<string, unknown> | null | undefined;

export interface ParamsField {
    name: string;
    label: string;
    kind: 'string' | 'integer' | 'number' | 'enum' | 'boolean' | 'object' | 'array' | 'unsupported';
    definition: Record<string, unknown>;
    options: string[];
    required: boolean;
}

function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function words(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((entry): entry is string => typeof entry === 'string') : [];
}

/** The fields a schema publishes, in its own declared order. */
export function fieldsOf(schema: ParamsSchema): ParamsField[] {
    const root = asRecord(schema);
    const properties = asRecord(root?.properties);
    if (properties === null) {
        return [];
    }
    const required = words(root?.required);
    return Object.entries(properties).map(([name, raw]) => {
        const definition = asRecord(raw) ?? {};
        const options = words(definition.enum);
        const type = Array.isArray(definition.type) ? definition.type.find((item) => item !== 'null') : definition.type;
        const label = typeof definition.title === 'string' && definition.title !== '' ? definition.title : name;
        let kind: ParamsField['kind'] = 'unsupported';
        if (options.length > 0) {
            kind = 'enum';
        } else if (type === 'string') {
            kind = 'string';
        } else if (type === 'integer' || type === 'number' || type === 'object' || type === 'array') {
            kind = type;
        } else if (type === 'boolean') {
            kind = 'boolean';
        }
        return { name, label: readable(label), kind, options, required: required.includes(name), definition };
    });
}

export interface ParamsFormProps {
    idPrefix: string;
    schema: ParamsSchema;
    value: Record<string, unknown>;
    onChange: (next: Record<string, unknown>) => void;
    emptyNote: string;
}

export function ParamsForm({ idPrefix, schema, value, onChange, emptyNote }: ParamsFormProps) {
    const fields = fieldsOf(schema);
    if (fields.length === 0) {
        return <p className="text-xs text-fg-2">{emptyNote}</p>;
    }
    const set = (name: string, next: unknown) => {
        const updated = { ...value };
        if (next === undefined || next === '') delete updated[name];
        else updated[name] = next;
        onChange(updated);
    };
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {fields.map((field) => {
                const id = `${idPrefix}-${field.name}`;
                const current = value[field.name];
                if (field.kind === 'unsupported') {
                    return (
                        <p key={field.name} className="text-xs text-fg-2" id={id}>
                            {field.label}: {current === undefined || current === null ? '' : JSON.stringify(current)}
                        </p>
                    );
                }
                return (
                    <label key={field.name} htmlFor={id} className="grid gap-1 text-xs font-semibold text-fg-2">
                        <span>
                            {field.label}
                            {field.required && <span aria-hidden="true"> *</span>}
                        </span>
                        {field.kind === 'enum' ? (
                            <select
                                id={id}
                                required={field.required}
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={typeof current === 'string' ? current : ''}
                                onChange={(event) => set(field.name, event.target.value)}
                            >
                                <option value="" />
                                {field.options.map((option) => (
                                    <option key={option} value={option}>
                                        {readable(option)}
                                    </option>
                                ))}
                            </select>
                        ) : field.kind === 'object' || field.kind === 'array' ? (
                            <JsonParameter
                                id={id}
                                value={current}
                                kind={field.kind}
                                required={field.required}
                                onChange={(next) => set(field.name, next)}
                            />
                        ) : field.kind === 'boolean' ? (
                            <input
                                id={id}
                                type="checkbox"
                                className="h-4 w-4 justify-self-start"
                                checked={current === true}
                                onChange={(event) => set(field.name, event.target.checked)}
                            />
                        ) : (
                            <input
                                id={id}
                                type={
                                    field.kind === 'integer' || field.kind === 'number'
                                        ? 'number'
                                        : field.definition.format === 'date'
                                          ? 'date'
                                          : field.definition.format === 'uri'
                                            ? 'url'
                                            : 'text'
                                }
                                required={field.required}
                                min={
                                    typeof field.definition.minimum === 'number' ? field.definition.minimum : undefined
                                }
                                max={
                                    typeof field.definition.maximum === 'number' ? field.definition.maximum : undefined
                                }
                                minLength={
                                    typeof field.definition.minLength === 'number'
                                        ? field.definition.minLength
                                        : undefined
                                }
                                maxLength={
                                    typeof field.definition.maxLength === 'number'
                                        ? field.definition.maxLength
                                        : undefined
                                }
                                step={field.kind === 'number' ? 'any' : field.kind === 'integer' ? 1 : undefined}
                                placeholder={
                                    field.definition.default == null ? undefined : String(field.definition.default)
                                }
                                className="h-8 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                                value={
                                    current === undefined || current === null ? '' : String(current as string | number)
                                }
                                onChange={(event) =>
                                    set(
                                        field.name,
                                        field.kind === 'integer' || field.kind === 'number'
                                            ? event.target.value === ''
                                                ? undefined
                                                : Number(event.target.value)
                                            : event.target.value,
                                    )
                                }
                            />
                        )}
                        {typeof field.definition.description === 'string' && (
                            <small className="font-normal">{field.definition.description}</small>
                        )}
                    </label>
                );
            })}
        </div>
    );
}

function JsonParameter({
    id,
    value,
    kind,
    required,
    onChange,
}: {
    id: string;
    value: unknown;
    kind: 'object' | 'array';
    required: boolean;
    onChange: (value: unknown) => void;
}) {
    const [raw, setRaw] = useState(() => (value == null ? '' : JSON.stringify(value, null, 2)));
    const ref = useRef<HTMLTextAreaElement>(null);
    useEffect(() => {
        let matches = false;
        try {
            matches = JSON.stringify(raw ? JSON.parse(raw) : undefined) === JSON.stringify(value);
        } catch {
            /* Keep the current invalid edit until the value changes externally. */
        }
        if (!matches) {
            setRaw(value == null ? '' : JSON.stringify(value, null, 2));
            ref.current?.setCustomValidity('');
        }
        // Local incomplete JSON must remain editable between keystrokes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);
    return (
        <textarea
            ref={ref}
            id={id}
            required={required}
            value={raw}
            placeholder={kind === 'array' ? '[]' : '{}'}
            onChange={(event) => {
                const next = event.target.value;
                setRaw(next);
                try {
                    const parsed: unknown = next ? JSON.parse(next) : undefined;
                    if (
                        parsed !== undefined &&
                        (kind === 'array'
                            ? !Array.isArray(parsed)
                            : !parsed || typeof parsed !== 'object' || Array.isArray(parsed))
                    )
                        throw new Error();
                    event.target.setCustomValidity('');
                    onChange(parsed);
                } catch {
                    event.target.setCustomValidity(strings.workspace.invalidJson);
                    onChange(value);
                }
            }}
        />
    );
}
