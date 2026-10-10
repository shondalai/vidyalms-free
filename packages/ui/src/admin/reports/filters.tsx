import { useState } from 'react';
import { Filter, RotateCcw } from 'lucide-react';
import { Button } from '@/shared/components';
import { EntityPicker } from './entity-picker';
import { fieldsFor, humanize, ready, schemaFor, type Parameters, type ReportDefinition } from './model';
import { reportWorkspaceStrings as s } from './strings';

export function ParameterFields({
    report,
    values,
    onChange,
}: {
    report: ReportDefinition;
    values: Parameters;
    onChange: (values: Parameters) => void;
}) {
    const required = schemaFor(report).required;
    return (
        <div className="vy-report-fields">
            {fieldsFor(report).map(([key, field]) => {
                const isRequired = Array.isArray(required) && required.includes(key);
                const label = typeof field.title === 'string' ? field.title : humanize(key);
                const type = Array.isArray(field.type) ? field.type.find((v) => v !== 'null') : field.type;
                const value = values[key] ?? '';
                const set = (next: Parameters[string] | undefined) => {
                    const updated = { ...values };
                    if (next === undefined || next === '') delete updated[key];
                    else updated[key] = next;
                    onChange(updated);
                };
                if (key === 'course_id')
                    return (
                        <EntityPicker
                            key={key}
                            kind="course"
                            value={typeof value === 'number' ? value : null}
                            onChange={(id) => set(id ?? undefined)}
                            required={isRequired}
                        />
                    );
                if (key === 'organisation_id')
                    return (
                        <EntityPicker
                            key={key}
                            kind="organisation"
                            value={typeof value === 'number' ? value : null}
                            onChange={(id) => set(id ?? undefined)}
                            required={isRequired}
                        />
                    );
                const options = Array.isArray(field.enum) ? field.enum : type === 'boolean' ? [true, false] : null;
                return (
                    <label className="vy-report-field" key={key}>
                        <span>
                            {label}
                            {isRequired && <span className="vy-report-required"> *</span>}
                        </span>
                        {options ? (
                            <select
                                required={isRequired}
                                value={String(value)}
                                onChange={(event) =>
                                    set(
                                        event.target.value === ''
                                            ? undefined
                                            : type === 'boolean'
                                              ? event.target.value === 'true'
                                              : type === 'integer' || type === 'number'
                                                ? Number(event.target.value)
                                                : event.target.value,
                                    )
                                }
                            >
                                <option value="">{s.any}</option>
                                {options.map((option) => (
                                    <option key={String(option)} value={String(option)}>
                                        {typeof option === 'boolean'
                                            ? option
                                                ? s.yes
                                                : s.no
                                            : humanize(String(option))}
                                    </option>
                                ))}
                            </select>
                        ) : type === 'object' || type === 'array' ? (
                            <textarea
                                required={isRequired}
                                value={typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)}
                                onChange={(event) => {
                                    const raw = event.target.value;
                                    try {
                                        set(raw ? (JSON.parse(raw) as object) : undefined);
                                        event.target.setCustomValidity('');
                                    } catch {
                                        set(raw);
                                        event.target.setCustomValidity(s.invalidJson);
                                    }
                                }}
                                placeholder={type === 'array' ? '[]' : '{}'}
                            />
                        ) : (
                            <input
                                type={
                                    field.format === 'date' || /^(from|to|start_date|end_date)$/.test(key)
                                        ? 'date'
                                        : type === 'integer' || type === 'number'
                                          ? 'number'
                                          : 'text'
                                }
                                required={isRequired}
                                min={typeof field.minimum === 'number' ? field.minimum : undefined}
                                max={typeof field.maximum === 'number' ? field.maximum : undefined}
                                step={type === 'number' ? 'any' : type === 'integer' ? 1 : undefined}
                                maxLength={typeof field.maxLength === 'number' ? field.maxLength : 500}
                                value={String(value)}
                                onChange={(event) =>
                                    set(
                                        event.target.value === ''
                                            ? undefined
                                            : type === 'integer' || type === 'number'
                                              ? Number(event.target.value)
                                              : event.target.value,
                                    )
                                }
                            />
                        )}
                        {typeof field.description === 'string' && <small>{field.description}</small>}
                    </label>
                );
            })}
        </div>
    );
}
export function ReportFilters({
    report,
    applied,
    onApply,
}: {
    report: ReportDefinition;
    applied: Parameters;
    onApply: (parameters: Parameters) => void;
}) {
    const [values, setValues] = useState(applied);
    const from = values.from ?? values.start_date;
    const to = values.to ?? values.end_date;
    const invalidDates = typeof from === 'string' && typeof to === 'string' && from !== '' && to !== '' && from > to;
    if (fieldsFor(report).length === 0) return null;
    return (
        <form
            className="vy-report-panel vy-report-filter-panel"
            onSubmit={(event) => {
                event.preventDefault();
                if (ready(report, values) && !invalidDates) onApply(values);
            }}
        >
            <div className="vy-report-panel-heading">
                <h2>
                    <Filter size={16} />
                    {s.filters}
                </h2>
                <Button type="button" variant="ghost" size="sm" onClick={() => setValues({})}>
                    <RotateCcw size={13} />
                    {s.reset}
                </Button>
            </div>
            <ParameterFields report={report} values={values} onChange={setValues} />
            <div className="vy-report-filter-footer">
                <p>{invalidDates ? s.invalidDates : ready(report, values) ? '' : s.chooseRequired}</p>
                <Button type="submit" disabled={!ready(report, values) || invalidDates}>
                    {s.apply}
                </Button>
            </div>
        </form>
    );
}
