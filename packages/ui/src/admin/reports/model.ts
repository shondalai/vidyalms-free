import type { components } from '@vidyalms/api-sdk';
import { reportWorkspaceStrings as s } from './strings';

export type ReportDefinition = components['schemas']['ReportDefinition'];
export type Catalogue = components['schemas']['ReportDefinitionList'];
export type ReportData = components['schemas']['CourseReport'];
export type ReportRun = components['schemas']['ReportRun'];
export type Schedule = components['schemas']['ReportSchedule'];
export type Parameters = Record<string, string | number | boolean | object>;
export type RecordData = Record<string, unknown>;
export const object = (value: unknown): value is RecordData =>
    value !== null && typeof value === 'object' && !Array.isArray(value);
export const positiveId = (value: unknown): number | null => {
    if (!/^[1-9]\d*$/.test(String(value ?? ''))) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) ? id : null;
};
export const humanize = (key: string) =>
    key
        .replace(/[_-]/g, ' ')
        .replace(/\b(id|ai|qr|csv|pdf|usd|eur|gbp)\b/gi, (v) => v.toUpperCase())
        .replace(/^./, (v) => v.toUpperCase());
export const categories = [
    { key: 'courses', title: s.coursePerformance, description: s.courseHint, icon: 'course', tone: 'violet' },
    { key: 'learners', title: s.learnerProgress, description: s.learnerHint, icon: 'users', tone: 'peach' },
    {
        key: 'organizations',
        title: s.organisationPerformance,
        description: s.organisationHint,
        icon: 'organization',
        tone: 'blue',
    },
    { key: 'assessment', title: s.assessment, description: s.assessmentHint, icon: 'assessment', tone: 'green' },
    { key: 'certificates', title: s.certificates, description: s.certificatesHint, icon: 'award', tone: 'violet' },
    { key: 'risk', title: s.risk, description: s.riskHint, icon: 'clock', tone: 'peach' },
    { key: 'ai', title: s.ai, description: s.aiHint, icon: 'ai', tone: 'blue' },
    { key: 'other', title: s.other, description: s.otherHint, icon: 'chart', tone: 'violet' },
] as const;
export type Category = (typeof categories)[number];
export function categoryFor(key: string): Category {
    const name = key.replace(/_/g, '-');
    const group = /ai-|processing|access-log|privacy/.test(name)
        ? 'ai'
        : /risk|compliance|expiry/.test(name)
          ? 'risk'
          : /certificate|credential/.test(name)
            ? 'certificates'
            : /item-analysis|assessment|cohort-funnel/.test(name)
              ? 'assessment'
              : /learner|roster|enrolment/.test(name)
                ? 'learners'
                : /org-|seat/.test(name)
                  ? 'organizations'
                  : /course|site-learning/.test(name)
                    ? 'courses'
                    : 'other';
    return categories.find((c) => c.key === group)!;
}
export function schemaFor(report: ReportDefinition): RecordData {
    if (object(report.parameter_schema)) return report.parameter_schema;
    // These older providers publish their filters on their existing route contract.
    if (report.key === 'item_analysis')
        return {
            required: ['course_id'],
            properties: {
                course_id: { type: 'integer', minimum: 1 },
                quiz_id: { type: 'integer', minimum: 1 },
                min_responses: { type: 'integer', minimum: 1, default: 30 },
                policy_key: { type: 'string' },
            },
        };
    return {};
}
export function fieldsFor(report: ReportDefinition): [string, RecordData][] {
    const schema = schemaFor(report);
    return Object.entries(object(schema.properties) ? schema.properties : {}).filter(
        ([key, value]) => !['page', 'per_page', 'cursor'].includes(key) && object(value),
    ) as [string, RecordData][];
}
export function parametersFor(report: ReportDefinition, query: URLSearchParams): Parameters {
    const values: Parameters = {};
    for (const [key, field] of fieldsFor(report)) {
        const raw = query.get(key);
        if (raw !== null && raw !== '') {
            const type = Array.isArray(field.type) ? field.type.find((v) => v !== 'null') : field.type;
            if (type === 'integer' || type === 'number') values[key] = Number(raw);
            else if (type === 'boolean') values[key] = raw === 'true';
            else if (type === 'object' || type === 'array') {
                try {
                    values[key] = JSON.parse(raw) as object;
                } catch {
                    values[key] = raw;
                }
            } else values[key] = raw;
        } else if (field.default !== undefined && field.default !== null)
            values[key] = field.default as Parameters[string];
    }
    return values;
}
export function ready(report: ReportDefinition, values: Parameters): boolean {
    const required = schemaFor(report).required;
    return (
        !Array.isArray(required) ||
        required.every((key) => values[String(key)] !== undefined && values[String(key)] !== '')
    );
}
export function reportHref(key: string, parameters: Parameters = {}, organisation: number | null = null) {
    const query = new URLSearchParams();
    if (organisation !== null) query.set('org', String(organisation));
    for (const [name, value] of Object.entries(parameters))
        query.set(name, typeof value === 'object' ? JSON.stringify(value) : String(value));
    return `/report/${encodeURIComponent(key)}${query.size ? `?${query}` : ''}`;
}
export function formatValue(value: unknown, key = '', columnType = ''): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? s.yes : s.no;
    // Database decimal columns can arrive as strings; leave identifiers and arbitrary text intact.
    if (
        typeof value === 'string' &&
        /^-?\d+(\.\d+)?$/.test(value) &&
        /percent|percentage|average_progress|average_quiz_score|completion_rate$/.test(key)
    )
        value = Number(value);
    if (typeof value === 'number') {
        if (!Number.isFinite(value)) return '—';
        if (/completion_rate$/.test(key)) return `${(value * 100).toFixed(1)}%`;
        if (/percent|percentage|average_progress|average_quiz_score/.test(key))
            return `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
        if (/_id$|^id$/.test(key)) return String(value);
        return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    if ((columnType === 'datetime' || /_at$/.test(key)) && typeof value === 'string') {
        const date = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
        if (!Number.isNaN(date.getTime()))
            return date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }
    return typeof value === 'string' ? value : Array.isArray(value) ? `${value.length} entries` : 'Details';
}
const META = new Set([
    'report',
    'computed',
    'computed_by',
    'reason',
    'omitted_fields',
    'columns',
    'items',
    'total',
    'page',
    'per_page',
    'row_count',
    'truncated',
    'notes',
    'suppressed_cells',
    'scope_digest',
    'generated_at',
    'period',
    'scope',
    'completion_basis',
    'available',
    'course_id',
    'organisation_id',
    'unit_id',
]);
export function factsFor(data: ReportData): [string, unknown][] {
    return Object.entries(data).filter(([key]) => !META.has(key));
}
