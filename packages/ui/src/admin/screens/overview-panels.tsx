import { translate as uiText } from '@/shared/i18n';
import { useId, useState, type ReactNode } from 'react';
import {
    ArrowRight,
    ArrowUpRight,
    Award,
    BookOpen,
    ChevronRight,
    CircleCheck,
    Clock3,
    GraduationCap,
    TrendingDown,
    TrendingUp,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { Icon } from '@/shared/components';
import type { AdminDashboard } from '../api';
import { screenUrl, workbenchUrl, type AdminConfig } from '../config';
import { formatAgo, formatNumber, parseApiDate } from '../lib';
import { adminStrings } from '../strings';

const s = adminStrings.dashboard;
type Metric = 'enrolments' | 'completions';
type Day = AdminDashboard['activity']['daily'][number];

function number(value: number, config: AdminConfig) {
    return formatNumber(value, config.locale);
}
export function completionRate(completed: number, total: number): number | null {
    return total === 0 ? null : Math.round((completed / total) * 1000) / 10;
}
function percent(value: number | null, config: AdminConfig) {
    return value === null
        ? '—'
        : new Intl.NumberFormat(config.locale, {
              style: 'percent',
              maximumFractionDigits: 1,
          }).format(value / 100);
}
function dateLabel(value: string, config: AdminConfig) {
    return new Intl.DateTimeFormat(config.locale, {
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${value}T00:00:00Z`));
}
export function OverviewPanel({
    id,
    title,
    subtitle,
    action,
    children,
    className = '',
}: {
    id: string;
    title: string;
    subtitle?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={`vy-overview-panel ${className}`} aria-labelledby={id}>
            <div className="vy-overview-panel-heading">
                <div>
                    <h2 id={id}>{title}</h2>
                    {subtitle && <p>{subtitle}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}
export function OverviewMetrics({ data, config }: { data: AdminDashboard; config: AdminConfig }) {
    const total = data.enrolments.active + data.enrolments.suspended + data.enrolments.completed;
    const items: {
        label: string;
        value: string;
        detail: string;
        icon: LucideIcon;
    }[] = [
        {
            label: s.counts.enrolments,
            value: number(data.enrolments.active, config),
            detail: s.counts.enrolmentsSecondary(data.enrolments.suspended),
            icon: Users,
        },
        {
            label: s.counts.courses,
            value: number(data.courses.published, config),
            detail: s.counts.coursesSecondary(data.courses.draft, data.courses.archived),
            icon: BookOpen,
        },
        {
            label: s.completionRate,
            value: percent(completionRate(data.enrolments.completed, total), config),
            detail: s.completedThisMonth(data.completions.this_month),
            icon: GraduationCap,
        },
        {
            label: s.counts.certificates,
            value: number(data.certificates.issued, config),
            detail: s.counts.certificatesSecondary(data.certificates.revoked),
            icon: Award,
        },
    ];
    return (
        <ul className="vy-overview-metrics" aria-label={s.countsRegion}>
            {items.map((item, index) => (
                <li key={item.label} className={`vy-overview-metric ${index === 3 ? 'is-featured' : ''}`}>
                    <div className="vy-overview-metric-label">
                        <span>{item.label}</span>
                        <Icon icon={item.icon} size={18} />
                    </div>
                    <div className="vy-overview-metric-value">
                        <strong>{item.value}</strong>
                    </div>
                    <div className="vy-overview-metric-detail">{item.detail}</div>
                </li>
            ))}
        </ul>
    );
}

export function LearningActivity({ data, config }: { data: AdminDashboard; config: AdminConfig }) {
    const [metric, setMetric] = useState<Metric>('enrolments');
    const [hover, setHover] = useState<number | null>(null);
    const gradient = useId();
    const days = data.activity.daily;
    const previousKey = metric === 'enrolments' ? 'previous_enrolments' : 'previous_completions';
    const current = days.reduce((sum, day) => sum + day[metric], 0);
    const previous = days.reduce((sum, day) => sum + day[previousKey], 0);
    const change = previous === 0 ? null : ((current - previous) / previous) * 100;
    const max = Math.max(4, ...days.flatMap((day) => [day[metric], day[previousKey]]));
    const ceiling = Math.ceil(max / 4) * 4;
    const x = (index: number) => 44 + (index / Math.max(1, days.length - 1)) * 622;
    const y = (value: number) => 190 - (value / ceiling) * 164;
    const path = (key: keyof Pick<Day, Metric | 'previous_enrolments' | 'previous_completions'>) =>
        days.map((day, index) => `${index === 0 ? 'M' : 'L'}${x(index)},${y(day[key])}`).join(' ');
    const selected = hover === null ? null : days[hover];
    return (
        <OverviewPanel
            id="vy-learning-activity"
            title={s.learningActivity}
            subtitle={s.learningSubtitle}
            action={
                <select
                    aria-label={s.activityMetric}
                    className="vy-overview-select"
                    value={metric}
                    onChange={(event) => {
                        setMetric(event.target.value as Metric);
                        setHover(null);
                    }}
                >
                    <option value="enrolments">{s.enrolments}</option>
                    <option value="completions">{s.completions}</option>
                </select>
            }
        >
            <div className="vy-overview-chart-summary">
                <strong>{number(current, config)}</strong>
                {change !== null && (
                    <span className={`vy-overview-delta ${change < 0 ? 'is-down' : ''}`}>
                        <Icon icon={change < 0 ? TrendingDown : TrendingUp} size={14} />
                        {percent(Math.abs(change), config)}
                    </span>
                )}
                <span>{s.inPeriod(metric)}</span>
                <div className="vy-overview-chart-legend">
                    <span>
                        <i />
                        {s.currentPeriod}
                    </span>
                    <span>
                        <i />
                        {s.previousPeriod}
                    </span>
                </div>
            </div>
            <div className="vy-overview-chart">
                <svg
                    viewBox="0 0 700 220"
                    role="img"
                    aria-label={s.chartDescription(metric, current, previous)}
                >
                    <defs>
                        <linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="currentColor" stopOpacity=".19" />
                            <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    {[0, 1, 2, 3, 4].map((tick) => (
                        <g key={tick}>
                            <line
                                x1="44"
                                x2="666"
                                y1={y((ceiling * tick) / 4)}
                                y2={y((ceiling * tick) / 4)}
                                className="vy-overview-gridline"
                            />
                            <text x="34" y={y((ceiling * tick) / 4) + 4} textAnchor="end">
                                {number((ceiling * tick) / 4, config)}
                            </text>
                        </g>
                    ))}
                    {days.length > 0 && (
                        <>
                            <path d={`${path(metric)} L666,190 L44,190 Z`} fill={`url(#${gradient})`} />
                            <path d={path(previousKey)} className="vy-overview-previous-line" />
                            <path
                                d={path(metric)}
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2.5"
                                strokeLinejoin="round"
                            />
                            {days.map((day, index) => (
                                <g
                                    key={day.date}
                                    onMouseEnter={() => setHover(index)}
                                    onMouseLeave={() => setHover(null)}
                                >
                                    <rect
                                        x={x(index) - 10}
                                        y="20"
                                        width="20"
                                        height="174"
                                        fill="transparent"
                                    />
                                    <circle
                                        cx={x(index)}
                                        cy={y(day[metric])}
                                        r={hover === index ? 4 : 0}
                                        fill="currentColor"
                                    />
                                    <title>{uiText("vidyalms.ui.9cd1eb4d3de30d08", "{p0}: {p1} / {p2}", { p0: dateLabel(day.date, config), p1: day[metric], p2: day[previousKey] })}</title>
                                </g>
                            ))}
                            {[
                                0,
                                Math.floor((days.length - 1) / 4),
                                Math.floor((days.length - 1) / 2),
                                Math.floor(((days.length - 1) * 3) / 4),
                                days.length - 1,
                            ].map((index) => (
                                <text key={index} x={x(index)} y="215" textAnchor="middle">
                                    {dateLabel(days[index]!.date, config)}
                                </text>
                            ))}
                        </>
                    )}
                </svg>
                {selected && (
                    <div className="vy-overview-chart-tooltip">
                        {dateLabel(selected.date, config)} · {selected[metric]} {metric} ·{' '}
                        {selected[previousKey]} {s.previousLower}
                    </div>
                )}
            </div>
            <div className="vy-overview-panel-footer">
                <Icon icon={TrendingUp} size={16} />
                <span>
                    {current === 0 && previous === 0
                        ? s.noActivity
                        : change === null
                          ? s.noBaseline
                          : s.comparison(current - previous, metric)}{' '}
                    {s.utcNote}
                </span>
            </div>
            <details className="vy-overview-chart-data">
                <summary>{s.viewChartData}</summary>
                <div className="vy-overview-table-scroll" tabIndex={0}>
                    <table>
                        <caption className="sr-only">{s.learningActivity}</caption>
                        <thead>
                            <tr>
                                <th>{s.date}</th>
                                <th>{s.currentPeriod}</th>
                                <th>{s.previousPeriod}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {days.map((day) => (
                                <tr key={day.date}>
                                    <th scope="row">{dateLabel(day.date, config)}</th>
                                    <td>{number(day[metric], config)}</td>
                                    <td>{number(day[previousKey], config)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </OverviewPanel>
    );
}

export function NeedsAttention({ data, config }: { data: AdminDashboard; config: AdminConfig }) {
    const { grading_waiting: grading, expiring_entitlements: expiring, expiry_sweep: sweep } = data.attention;
    const count = grading.submissions + grading.attempts;
    const items = [
        ...(count > 0
            ? [
                  {
                      icon: BookOpen,
                      tone: 'peach',
                      title: s.gradingTitle(count),
                      description: s.attention.grading(
                          grading.submissions,
                          grading.attempts,
                          grading.older_than_days,
                          grading.courses,
                      ),
                      href: screenUrl(config, 'courses'),
                      link: s.attention.gradingLink,
                  },
              ]
            : []),
        ...(expiring.count > 0
            ? [
                  {
                      icon: Clock3,
                      tone: 'violet',
                      title: s.expiringTitle(expiring.count),
                      description: s.attention.expiring(expiring.count, expiring.within_days),
                      href: screenUrl(config, 'enrolments'),
                      link: s.attention.expiringLink,
                  },
              ]
            : []),
        ...(data.courses.draft > 0
            ? [
                  {
                      icon: BookOpen,
                      tone: 'blue',
                      title: s.draftTitle(data.courses.draft),
                      description: s.draftDescription,
                      href: screenUrl(config, 'courses'),
                      link: s.draftsLink,
                  },
              ]
            : []),
    ];
    const ago = formatAgo(sweep.last_run_at, config.locale);
    return (
        <OverviewPanel
            id="vy-attention"
            title={s.attention.title}
            action={<span className="vy-overview-count">{items.length}</span>}
            className="vy-overview-attention"
        >
            <div className="vy-overview-attention-items">
                {count === 0 && expiring.count === 0 && (
                    <div className="vy-overview-clear">
                        <Icon icon={CircleCheck} size={24} />
                        <p>{s.attention.clear}</p>
                    </div>
                )}
                {items.map((item) => {
                    const content = (
                        <>
                            <span className={`vy-overview-tile ${item.tone}`}>
                                <Icon icon={item.icon} size={18} />
                            </span>
                            <span>
                                <strong>{item.title}</strong>
                                <small>{item.description}</small>
                            </span>
                            {item.href && <Icon icon={ChevronRight} size={16} />}
                        </>
                    );
                    return item.href ? (
                        <a
                            key={item.title}
                            className="vy-overview-attention-row"
                            href={item.href}
                            aria-label={item.link}
                        >
                            {content}
                        </a>
                    ) : (
                        <div className="vy-overview-attention-row" key={item.title}>
                            {content}
                        </div>
                    );
                })}
            </div>
            <div className="vy-overview-panel-footer">
                <Icon icon={Clock3} size={15} />
                <span>
                    {ago === null
                        ? s.attention.sweepNever
                        : uiText("vidyalms.ui.380e3dbd6730acee", "{p0}{p1}", { p0: s.attention.sweepRan(ago), p1: sweep.schedule ? ` (${s.attention.sweepSchedule(sweep.schedule)})` : '' })}
                </span>
            </div>
        </OverviewPanel>
    );
}

export function TopCourses({ data, config }: { data: AdminDashboard; config: AdminConfig }) {
    const href = screenUrl(config, 'courses');
    return (
        <OverviewPanel
            id="vy-top-courses"
            title={s.topCourses.title}
            subtitle={s.topCourses.subtitle}
            action={
                href && (
                    <a className="vy-overview-text-link" href={href}>
                        {s.viewCourses}
                        <Icon icon={ArrowRight} size={15} />
                    </a>
                )
            }
        >
            {data.top_courses.length === 0 ? (
                <p className="vy-overview-empty">{s.topCourses.empty}</p>
            ) : (
                <div
                    className="vy-overview-table-scroll"
                    tabIndex={0}
                    role="region"
                    aria-label={s.topCourses.title}
                >
                    <table className="vy-overview-courses">
                        <thead>
                            <tr>
                                <th>{s.courseName}</th>
                                <th>{s.activeLearners}</th>
                                <th>{s.completion}</th>
                                <th>{s.status}</th>
                                <th>
                                    <span className="sr-only">{s.openCourse}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.top_courses.map((course, index) => {
                                const rate = completionRate(course.completed_count, course.enrolled_count);
                                return (
                                    <tr key={course.id}>
                                        <td>
                                            <a
                                                href={workbenchUrl(config, course.id)}
                                                className="vy-overview-course-name"
                                            >
                                                <span
                                                    className={`vy-overview-tile ${['peach', 'violet', 'blue', 'mint'][index % 4]}`}
                                                    aria-hidden="true"
                                                >
                                                    <BookOpen size={19} />
                                                </span>
                                                <span>
                                                    <strong>{course.title}</strong>
                                                    <small>{s.lessons(course.lesson_count)}</small>
                                                </span>
                                            </a>
                                        </td>
                                        <td>{number(course.active_count, config)}</td>
                                        <td>
                                            <div className="vy-overview-completion">
                                                <span aria-hidden="true">
                                                    <i style={{ width: `${rate ?? 0}%` }} />
                                                </span>
                                                {percent(rate, config)}
                                            </div>
                                        </td>
                                        <td>
                                            <span className="vy-overview-published">
                                                <i />
                                                {s.published}
                                            </span>
                                        </td>
                                        <td>
                                            <a
                                                href={workbenchUrl(config, course.id)}
                                                aria-label={adminStrings.courses.openNamed(course.title)}
                                            >
                                                <Icon icon={ArrowUpRight} size={17} />
                                            </a>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="vy-overview-panel-footer vy-overview-split">
                <span>{s.rankedBy}</span>
                <span>{s.liveSnapshot}</span>
            </div>
        </OverviewPanel>
    );
}

export function LearnerProgress({ data, config }: { data: AdminDashboard; config: AdminConfig }) {
    const rows = [
        { label: s.active, value: data.enrolments.active, color: '#cf6038' },
        { label: s.completed, value: data.enrolments.completed, color: '#38876d' },
        { label: s.suspended, value: data.enrolments.suspended, color: '#939cae' },
    ];
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    let offset = 0;
    const href = screenUrl(config, 'learners');
    return (
        <OverviewPanel
            id="vy-learner-progress"
            title={s.learnerProgress}
            action={
                href && (
                    <a href={href} aria-label={s.exploreLearners}>
                        <Icon icon={ArrowUpRight} size={17} />
                    </a>
                )
            }
        >
            <div className="vy-overview-donut">
                <svg viewBox="0 0 200 200" aria-hidden="true">
                    <circle
                        cx="100"
                        cy="100"
                        r="77"
                        fill="none"
                        stroke="var(--_vy-surface-2)"
                        strokeWidth="18"
                    />
                    {rows.map((row) => {
                        const fraction = total === 0 ? 0 : (row.value / total) * 100;
                        const start = offset;
                        offset += fraction;
                        return (
                            <circle
                                key={row.label}
                                cx="100"
                                cy="100"
                                r="77"
                                pathLength="100"
                                fill="none"
                                stroke={row.color}
                                strokeWidth="18"
                                strokeDasharray={`${Math.max(0, fraction - (fraction < 100 ? 1.5 : 0))} 100`}
                                strokeDashoffset={-start}
                                transform="rotate(-90 100 100)"
                            />
                        );
                    })}
                </svg>
                <div>
                    <strong>{number(total, config)}</strong>
                    <span>{s.enrolmentsLower}</span>
                </div>
            </div>
            <ul className="vy-overview-progress-legend">
                {rows.map((row) => (
                    <li key={row.label}>
                        <i style={{ background: row.color }} />
                        <span>{row.label}</span>
                        <strong>{number(row.value, config)}</strong>
                        <small>{percent(completionRate(row.value, total), config)}</small>
                    </li>
                ))}
            </ul>
            <p className="vy-overview-progress-note">{s.progressNote}</p>
            {href && (
                <a className="vy-overview-panel-footer vy-overview-text-link" href={href}>
                    {s.exploreLearners}
                    <Icon icon={ArrowRight} size={15} />
                </a>
            )}
        </OverviewPanel>
    );
}

export function RecentActivity({
    data,
    config,
    full = false,
}: {
    data: AdminDashboard;
    config: AdminConfig;
    full?: boolean;
}) {
    const events = full ? data.activity.recent : data.activity.recent.slice(0, 3);
    return (
        <OverviewPanel
            id={full ? 'vy-activity-feed' : 'vy-recent-activity'}
            title={full ? s.workspaceActivity : s.recentActivity}
            subtitle={full ? s.latestEvents : undefined}
            action={!full && <span className="vy-overview-muted">{s.workspaceInMotion}</span>}
        >
            {events.length === 0 ? (
                <p className="vy-overview-empty">{s.noRecentActivity}</p>
            ) : (
                <ul className={`vy-overview-events ${full ? 'is-full' : ''}`}>
                    {events.map((event, index) => (
                        <li key={`${event.kind}-${index}`}>
                            <span
                                className={`vy-overview-tile ${event.kind === 'completed' ? 'mint' : 'peach'}`}
                            >
                                <Icon icon={event.kind === 'completed' ? GraduationCap : Users} size={18} />
                            </span>
                            <div>
                                <p>
                                    {event.kind === 'completed' ? s.courseCompleted : s.newEnrolment}{' '}
                                    <a href={workbenchUrl(config, event.course_id)}>{event.course_title}</a>
                                </p>
                                <time dateTime={parseApiDate(event.occurred_at)?.toISOString()}>
                                    {formatAgo(event.occurred_at, config.locale)}
                                </time>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </OverviewPanel>
    );
}

export function activityCsv(data: AdminDashboard): string {
    return [
        'date_utc,enrolments,completions,previous_enrolments,previous_completions',
        ...data.activity.daily.map((day) =>
            [
                day.date,
                day.enrolments,
                day.completions,
                day.previous_enrolments,
                day.previous_completions,
            ].join(','),
        ),
    ].join('\r\n');
}
