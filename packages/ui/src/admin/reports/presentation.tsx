import { translate as uiText } from '@/shared/i18n';
import {
    ArrowUpRight,
    Award,
    BarChart3,
    BookOpen,
    Building2,
    Clock3,
    GraduationCap,
    ShieldCheck,
    Sparkles,
    Users,
} from 'lucide-react';
import { Badge, Button, EmptyState, Pagination } from '@/shared/components';
import type { SiteLearningReport } from '@vidyalms/api-sdk';
import { factsFor, formatValue, humanize, object, positiveId, type RecordData, type ReportData } from './model';
import { reportWorkspaceStrings as s } from './strings';

export const reportIcons = {
    course: BookOpen,
    users: Users,
    organization: Building2,
    assessment: ShieldCheck,
    award: Award,
    clock: Clock3,
    ai: Sparkles,
    chart: BarChart3,
};
export function LearningStats({ summary }: { summary?: SiteLearningReport['summary'] }) {
    const metrics = [
        { label: s.courses, value: summary?.courses, Icon: BookOpen },
        { label: s.enrolments, value: summary?.enrolments, Icon: Users },
        { label: s.completed, value: summary?.completed, Icon: GraduationCap },
        {
            label: s.completionRate,
            value: summary?.completion_rate == null ? null : `${(summary.completion_rate * 100).toFixed(1)}%`,
            Icon: BarChart3,
        },
    ];
    return (
        <dl className="vy-report-stats">
            {metrics.map(({ label, value, Icon }) => (
                <div key={label}>
                    <Icon size={21} aria-hidden="true" />
                    <div>
                        <dd>{formatValue(value)}</dd>
                        <dt>{label}</dt>
                    </div>
                </div>
            ))}
        </dl>
    );
}
export function Facts({ data }: { data: ReportData }) {
    const entries = factsFor(data);
    if (!entries.length) return null;
    return (
        <section className="vy-report-panel">
            <div className="vy-report-panel-heading">
                <h2>{s.summary}</h2>
            </div>
            <ValueDetails value={Object.fromEntries(entries)} />
        </section>
    );
}
export function ValueDetails({ value, depth = 0 }: { value: unknown; depth?: number }) {
    if (Array.isArray(value))
        return (
            <div className="vy-report-detail-stack">
                {value.length ? (
                    value.map((row, i) => (
                        <div key={i}>
                            <ValueDetails value={row} depth={depth + 1} />
                        </div>
                    ))
                ) : (
                    <span>—</span>
                )}
            </div>
        );
    if (!object(value)) return <span>{formatValue(value)}</span>;
    return (
        <dl className="vy-report-facts">
            {Object.entries(value).map(([key, field]) => (
                <div key={key} className={typeof field === 'object' && field !== null ? 'vy-report-fact-wide' : ''}>
                    <dt>{humanize(key)}</dt>
                    <dd>
                        {typeof field === 'object' && field !== null && depth < 4 ? (
                            <details>
                                <summary>{Array.isArray(field) ? uiText("vidyalms.ui.14b9f3f14ffa3228", "{p0} entries", { p0: field.length }) : s.details}</summary>
                                <ValueDetails value={field} depth={depth + 1} />
                            </details>
                        ) : (
                            formatValue(field, key)
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
export function LearningChart({
    rows,
    onCourse,
}: {
    rows: SiteLearningReport['items'];
    onCourse: (id: number) => void;
}) {
    const visible = rows.slice(0, 8);
    const max = Math.max(1, ...visible.map((row) => row.enrolments ?? 0));
    return (
        <section className="vy-report-panel">
            <div className="vy-report-panel-heading">
                <div>
                    <h2>{s.distribution}</h2>
                    <p>{s.distributionHint}</p>
                </div>
                <div className="vy-report-legend">
                    <span>{s.enrolments}</span>
                    <span>{s.completed}</span>
                </div>
            </div>
            {visible.length ? (
                <div className="vy-report-chart" role="group" aria-label={s.distribution}>
                    {visible.map((row) => (
                        <button
                            key={row.course_id}
                            onClick={() => onCourse(row.course_id)}
                            aria-label={uiText("vidyalms.ui.e7c5145fb6019548", "{p0}: {p1} {p2}, {p3} {p4}", { p0: row.title, p1: s.enrolments, p2: formatValue(row.enrolments), p3: s.completed, p4: formatValue(row.completed) })}
                        >
                            <span className="vy-report-chart-bars">
                                <span style={{ height: `${Math.max(1, ((row.enrolments ?? 0) / max) * 100)}%` }}>
                                    <i>{formatValue(row.enrolments)}</i>
                                </span>
                                <span style={{ height: `${Math.max(1, ((row.completed ?? 0) / max) * 100)}%` }}>
                                    <i>{formatValue(row.completed)}</i>
                                </span>
                            </span>
                            <span className="vy-report-chart-label">{row.title}</span>
                        </button>
                    ))}
                </div>
            ) : (
                <EmptyState title={s.noData} description={s.noDataHint} />
            )}
        </section>
    );
}
export function ReportTable({
    data,
    onDetail,
    onCourse,
    page,
    perPage,
    onPage,
    onSize,
}: {
    data: ReportData;
    onDetail: (row: RecordData) => void;
    onCourse?: (id: number) => void;
    page: number;
    perPage: number;
    onPage: (page: number) => void;
    onSize: (size: number) => void;
}) {
    const rows = data.items.filter(object);
    const supplied = Array.isArray(data.columns) ? data.columns.filter(object) : [];
    const columns = supplied.length
        ? supplied.map((column) => ({
              key: String(column.key),
              label: String(column.label ?? humanize(String(column.key))),
              type: String(column.type ?? ''),
          }))
        : Array.from(new Set(rows.flatMap((row) => Object.keys(row)))).map((key) => ({
              key,
              label: humanize(key),
              type: '',
          }));
    const total = typeof data.total === 'number' ? data.total : rows.length;
    return (
        <section className="vy-report-panel vy-report-results">
            <div className="vy-report-panel-heading">
                <div>
                    <h2>{s.results}</h2>
                    <p>{s.records(total)}</p>
                </div>
                <label className="vy-report-page-size">
                    {s.pageSize}
                    <select value={perPage} onChange={(event) => onSize(Number(event.target.value))}>
                        {[20, 50, 100].map((size) => (
                            <option key={size}>{size}</option>
                        ))}
                    </select>
                </label>
            </div>
            {Number(data.suppressed_cells ?? 0) > 0 && <p className="vy-report-notice">{s.hidden}</p>}
            {data.truncated === true && <p className="vy-report-notice">{s.truncated}</p>}
            {rows.length ? (
                <div className="vy-report-table-scroll">
                    <table className="vy-report-table">
                        <caption className="sr-only">{s.results}</caption>
                        <thead>
                            <tr>
                                {columns.map((column) => (
                                    <th scope="col" key={column.key}>
                                        {column.label}
                                    </th>
                                ))}
                                <th scope="col">
                                    <span className="sr-only">{s.details}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => (
                                <tr key={index}>
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className={
                                                typeof row[column.key] === 'number' ? 'vy-report-number' : undefined
                                            }
                                        >
                                            {onCourse && column.key === 'title' && positiveId(row.course_id) ? (
                                                <button
                                                    className="vy-report-text-link"
                                                    onClick={() => onCourse(positiveId(row.course_id)!)}
                                                >
                                                    {formatValue(row[column.key])}
                                                </button>
                                            ) : /^(learner|name|title)$/.test(column.key) && row[column.key] ? (
                                                <button className="vy-report-text-link" onClick={() => onDetail(row)}>
                                                    {formatValue(row[column.key])}
                                                </button>
                                            ) : /^(status|state)$/.test(column.key) ? (
                                                <Badge
                                                    variant={
                                                        ['completed', 'active', 'published', 'passed'].includes(
                                                            String(row[column.key]),
                                                        )
                                                            ? 'ok'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {formatValue(row[column.key])}
                                                </Badge>
                                            ) : typeof row[column.key] === 'object' && row[column.key] !== null ? (
                                                <button className="vy-report-text-link" onClick={() => onDetail(row)}>
                                                    {formatValue(row[column.key])}
                                                </button>
                                            ) : (
                                                formatValue(row[column.key], column.key, column.type)
                                            )}
                                        </td>
                                    ))}
                                    <td>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: s.details, p1: String(row.learner || row.title || row.name || index + 1) })}
                                            onClick={() => onDetail(row)}
                                        >
                                            <ArrowUpRight size={15} />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <EmptyState title={s.noRows} description={s.noRowsHint} />
            )}
            <div className="vy-report-pagination">
                <span>
                    {s.visible(
                        rows.length ? (page - 1) * perPage + 1 : 0,
                        rows.length ? (page - 1) * perPage + rows.length : 0,
                        total,
                    )}
                </span>
                <Pagination label={s.pages} page={page} perPage={perPage} total={total} onPageChange={onPage} />
            </div>
        </section>
    );
}
