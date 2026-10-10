import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Button, Icon } from '@/shared/components';
import { BankInput, BankSelect, BankTextArea } from './fields';

type JsonObject = Record<string, unknown>;

function record(value: unknown): JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as JsonObject : {};
}

function typeOf(schema: JsonObject): string {
    if (typeof schema.type === 'string') {
        return schema.type;
    }
    if (Array.isArray(schema.type)) {
        return schema.type.find((type): type is string => typeof type === 'string' && type !== 'null') ?? 'unknown';
    }
    return 'unknown';
}

function labelOf(name: string, schema: JsonObject): string {
    if (typeof schema.title === 'string' && schema.title.trim() !== '') {
        return schema.title;
    }
    return name.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function defaultValue(schema: JsonObject): unknown {
    if (Object.prototype.hasOwnProperty.call(schema, 'default')) {
        return schema.default;
    }
    const options = Array.isArray(schema.enum) ? schema.enum : [];
    if (options.length > 0) {
        return options[0];
    }
    switch (typeOf(schema)) {
        case 'boolean': return false;
        case 'string': return '';
        case 'integer':
        case 'number': return typeof schema.minimum === 'number' ? schema.minimum : 0;
        case 'array': {
            const item = record(schema.items);
            const minimum = typeof schema.minItems === 'number' ? Math.max(0, Math.floor(schema.minItems)) : 0;
            return Array.from({ length: minimum }, () => defaultValue(item));
        }
        case 'object': {
            const value: JsonObject = {};
            for (const [key, child] of Object.entries(record(schema.properties))) {
                const childSchema = record(child);
                if (Object.prototype.hasOwnProperty.call(childSchema, 'default')) {
                    value[key] = childSchema.default;
                }
            }
            return value;
        }
        default: return null;
    }
}

function Hint({ schema }: { schema: JsonObject }) {
    return typeof schema.description === 'string' && schema.description !== ''
        ? <span className="text-[11px] leading-snug text-fg-2">{schema.description}</span>
        : null;
}

function JsonFallback({ label, value, onChange }: { label: string; value: unknown; onChange: (value: unknown) => void }) {
    const serialized = JSON.stringify(value ?? {}, null, 2);
    const [text, setText] = useState(serialized);
    const [error, setError] = useState('');
    useEffect(() => setText(serialized), [serialized]);
    return (
        <label className="grid gap-1.5">
            <span className="font-display text-xs font-semibold text-fg">{label} {uiText("vidyalms.ui.5aa8876730560739", " JSON")}</span>
            <BankTextArea
                rows={4}
                aria-label={uiText("vidyalms.ui.ce72ad817771335c", "{p0} JSON", { p0: label })}
                value={text}
                invalid={error !== ''}
                onChange={(event) => {
                    const next = event.target.value;
                    setText(next);
                    try {
                        onChange(JSON.parse(next));
                        setError('');
                    } catch {
                        setError('Enter valid JSON before saving.');
                    }
                }}
            />
            <span className={error === '' ? 'text-[11px] text-fg-2' : 'text-[11px] text-danger'}>
                {error || 'This provider uses a schema shape without standard controls; its JSON value remains editable.'}
            </span>
        </label>
    );
}

function SchemaValue({
    name,
    schema,
    value,
    onChange,
}: {
    name: string;
    schema: JsonObject;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const label = labelOf(name, schema);
    const options = Array.isArray(schema.enum) ? schema.enum : null;
    const type = typeOf(schema);

    if (options !== null && options.length > 0) {
        const selected = options.some((option) => Object.is(option, value)) ? value : options[0];
        return (
            <label className="grid gap-1.5">
                <span className="font-display text-xs font-semibold text-fg">{label}</span>
                <BankSelect
                    aria-label={label}
                    value={JSON.stringify(selected)}
                    onChange={(event) => onChange(JSON.parse(event.target.value))}
                >
                    {options.map((option) => <option key={JSON.stringify(option)} value={JSON.stringify(option)}>{String(option)}</option>)}
                </BankSelect>
                <Hint schema={schema} />
            </label>
        );
    }

    if (type === 'boolean') {
        return (
            <label className="flex min-h-10 cursor-pointer items-start gap-2 rounded border border-border bg-bg px-3 py-2 text-xs text-fg">
                <input type="checkbox" checked={value === true} onChange={(event) => onChange(event.target.checked)} className="mt-0.5 h-4 w-4 accent-accent" />
                <span className="grid gap-0.5"><strong className="font-display font-semibold">{label}</strong><Hint schema={schema} /></span>
            </label>
        );
    }

    if (type === 'string' || type === 'number' || type === 'integer') {
        const numeric = type !== 'string';
        return (
            <label className="grid gap-1.5">
                <span className="font-display text-xs font-semibold text-fg">{label}</span>
                <BankInput
                    aria-label={label}
                    type={numeric ? 'number' : 'text'}
                    step={type === 'integer' ? 1 : numeric ? 'any' : undefined}
                    min={typeof schema.minimum === 'number' ? schema.minimum : undefined}
                    max={typeof schema.maximum === 'number' ? schema.maximum : undefined}
                    value={value === null || value === undefined ? '' : String(value)}
                    onChange={(event) => {
                        const next = event.target.value;
                        onChange(numeric ? (next === '' ? null : Number(next)) : next);
                    }}
                />
                <Hint schema={schema} />
            </label>
        );
    }

    if (type === 'object') {
        const properties = Object.entries(record(schema.properties));
        if (properties.length === 0) {
            return <JsonFallback label={label} value={value} onChange={onChange} />;
        }
        const current = record(value);
        return (
            <fieldset className="grid gap-2.5 rounded border border-border bg-surface p-3">
                <legend className="px-1 font-display text-xs font-semibold text-fg">{label}</legend>
                <Hint schema={schema} />
                <div className="grid gap-2.5 sm:grid-cols-2">
                    {properties.map(([key, child]) => (
                        <SchemaValue
                            key={key}
                            name={key}
                            schema={record(child)}
                            value={current[key]}
                            onChange={(next) => onChange({ ...current, [key]: next })}
                        />
                    ))}
                </div>
            </fieldset>
        );
    }

    if (type === 'array') {
        const values = Array.isArray(value) ? value : [];
        const itemSchema = record(schema.items);
        const minimum = typeof schema.minItems === 'number' ? Math.max(0, Math.floor(schema.minItems)) : 0;
        return (
            <fieldset className="grid gap-2.5 rounded border border-border bg-surface p-3 sm:col-span-2">
                <legend className="px-1 font-display text-xs font-semibold text-fg">{label}</legend>
                <Hint schema={schema} />
                {values.map((item, index) => (
                    <div key={index} className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2 rounded border border-border bg-bg p-2.5">
                        <SchemaValue name={`${label} item ${index + 1}`} schema={itemSchema} value={item} onChange={(next) => onChange(values.map((entry, position) => position === index ? next : entry))} />
                        <Button type="button" variant="ghost" size="sm" aria-label={uiText("vidyalms.ui.a3be61618b1f7ef7", "Remove {p0} item {p1}", { p0: label, p1: index + 1 })} disabled={values.length <= minimum} onClick={() => onChange(values.filter((_entry, position) => position !== index))}><Icon icon={Trash2} size={14} /></Button>
                    </div>
                ))}
                <div><Button type="button" variant="outline" size="sm" onClick={() => onChange([...values, defaultValue(itemSchema)])}><Icon icon={Plus} size={14} />{uiText("vidyalms.ui.8d89e4a829ae847d", "Add ")}{label.toLowerCase()} {uiText("vidyalms.ui.a5f3c2e9f903f3c8", " item")}</Button></div>
            </fieldset>
        );
    }

    return <JsonFallback label={label} value={value} onChange={onChange} />;
}

/** Provider-owned scoring policy parameters rendered from its JSON Schema. */
export function SchemaParams({
    schema,
    value,
    onChange,
}: {
    schema: unknown;
    value: JsonObject;
    onChange: (value: JsonObject) => void;
}) {
    const root = record(schema);
    const properties = Object.entries(record(root.properties));
    if (properties.length === 0) {
        return <p className="text-[11.5px] text-fg-2">{uiText("vidyalms.ui.c71cb4eba98c5ed1", "This scoring policy has no configurable parameters.")}</p>;
    }
    return (
        <div className="grid gap-2.5 rounded border border-border bg-bg p-3 sm:grid-cols-2">
            {properties.map(([name, property]) => (
                <SchemaValue
                    key={name}
                    name={name}
                    schema={record(property)}
                    value={value[name]}
                    onChange={(next) => onChange({ ...value, [name]: next })}
                />
            ))}
        </div>
    );
}
