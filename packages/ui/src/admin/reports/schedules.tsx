import { translate as uiText } from '@/shared/i18n';
import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import type { components } from '@vidyalms/api-sdk';
import { CalendarClock, MoreHorizontal, Pause, Play, Plus } from 'lucide-react';
import { useApiClient } from '@/shared/context';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    ErrorState,
    Pagination,
    Skeleton,
} from '@/shared/components';
import { reportKeys, runSchedule, saveSchedule, useSchedules } from './api';
import {
    formatValue,
    humanize,
    ready,
    type Catalogue,
    type Parameters,
    type ReportDefinition,
    type Schedule,
} from './model';
import { ParameterFields } from './filters';
import { reportWorkspaceStrings as s } from './strings';

type Request = components['schemas']['ReportScheduleRequest'];
const timeString = (minute: number) =>
    `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
export function ScheduleDialog({
    catalogue,
    organisation,
    report: initialReport,
    parameters = {},
    existing,
    onClose,
}: {
    catalogue: Catalogue;
    organisation: number | null;
    report?: ReportDefinition;
    parameters?: Parameters;
    existing?: Schedule;
    onClose: () => void;
}) {
    const reports = catalogue.reports.filter((report) => report.exportable);
    const formats = (catalogue.formats ?? []).filter((format) => format.available !== false);
    const [form, setForm] = useState<Request>(() => ({
        title: existing?.title ?? '',
        report_key: existing?.report_key ?? initialReport?.key ?? reports[0]?.key ?? '',
        parameters: existing?.definition ?? parameters,
        format_key: existing?.format_key ?? formats[0]?.key ?? '',
        delivery_key: existing?.delivery_key ?? catalogue.deliveries?.[0]?.key ?? '',
        audience_key: existing?.audience_key ?? 'site_managers',
        audience_config: existing?.audience_config ?? {},
        recurrence: existing?.recurrence ?? 'weekly',
        recurrence_day: existing?.recurrence_day ?? 1,
        run_at_minute: existing?.run_at_minute ?? 540,
        timezone: existing?.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
        starts_at: existing?.starts_at ?? new Date().toISOString(),
        render_as: existing?.render_as ?? 'recipient',
        empty_policy: existing?.empty_policy ?? 'skip',
        state: existing?.state ?? 'disabled',
        subject: existing?.subject ?? null,
        body: existing?.body ?? null,
    }));
    const report = reports.find((entry) => entry.key === form.report_key);
    const update = (patch: Partial<Request>) => setForm((value) => ({ ...value, ...patch }));
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const replay = useRef({ body: '', key: '' });
    const client = useApiClient();
    const cache = useQueryClient();
    const save = async () => {
        if (!report || !ready(report, form.parameters as Parameters)) return;
        const fingerprint = JSON.stringify(form);
        if (replay.current.body !== fingerprint) replay.current = { body: fingerprint, key: crypto.randomUUID() };
        setPending(true);
        setError(null);
        try {
            await saveSchedule(client, organisation, existing?.id ?? null, form, replay.current.key);
            await cache.invalidateQueries({ queryKey: [...reportKeys, 'schedules'] });
            onClose();
        } catch (failure) {
            setError(failure);
        } finally {
            setPending(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !pending) onClose();
            }}
        >
            <DialogContent className="vy-report-dialog vy-report-schedule-dialog">
                <DialogTitle>{existing ? s.editSchedule : s.newSchedule}</DialogTitle>
                <DialogDescription>{s.scheduleIntro}</DialogDescription>
                <form
                    className="vy-report-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <fieldset disabled={pending}>
                        <div className="vy-report-fields">
                            <label className="vy-report-field">
                                <span>{s.name}</span>
                                <input
                                    required
                                    maxLength={190}
                                    value={form.title}
                                    onChange={(event) => update({ title: event.target.value })}
                                />
                            </label>
                            <label className="vy-report-field">
                                <span>{s.report}</span>
                                <select
                                    value={form.report_key}
                                    onChange={(event) => update({ report_key: event.target.value, parameters: {} })}
                                >
                                    {reports.map((entry) => (
                                        <option key={entry.key} value={entry.key}>
                                            {entry.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        {report && (
                            <section className="vy-report-form-section">
                                <h3>{s.filters}</h3>
                                <ParameterFields
                                    report={report}
                                    values={form.parameters as Parameters}
                                    onChange={(values) => update({ parameters: values })}
                                />
                            </section>
                        )}
                        <section className="vy-report-form-section">
                            <h3>{s.delivery}</h3>
                            <div className="vy-report-fields">
                                <label className="vy-report-field">
                                    <span>{s.format}</span>
                                    <select
                                        value={form.format_key}
                                        required
                                        onChange={(event) => update({ format_key: event.target.value })}
                                    >
                                        {formats.map((entry) => (
                                            <option key={entry.key} value={entry.key}>
                                                {entry.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.delivery}</span>
                                    <select
                                        value={form.delivery_key}
                                        required
                                        onChange={(event) => update({ delivery_key: event.target.value })}
                                    >
                                        {catalogue.deliveries?.map((entry) => (
                                            <option key={entry.key} value={entry.key}>
                                                {entry.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.audience}</span>
                                    <input
                                        required
                                        value={form.audience_key}
                                        onChange={(event) => update({ audience_key: event.target.value })}
                                    />
                                    <small>{s.audienceHint}</small>
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.renderAs}</span>
                                    <select
                                        value={form.render_as}
                                        onChange={(event) =>
                                            update({ render_as: event.target.value as 'recipient' | 'author' })
                                        }
                                    >
                                        <option value="recipient">{s.recipient}</option>
                                        <option value="author">{s.author}</option>
                                    </select>
                                </label>
                            </div>
                            {form.render_as === 'author' && <p className="vy-report-notice">{s.authorHint}</p>}
                        </section>
                        <section className="vy-report-form-section">
                            <h3>{s.recurrence}</h3>
                            <div className="vy-report-fields">
                                <label className="vy-report-field">
                                    <span>{s.recurrence}</span>
                                    <select
                                        value={form.recurrence}
                                        onChange={(event) =>
                                            update({
                                                recurrence: event.target.value as Request['recurrence'],
                                                recurrence_day: 1,
                                            })
                                        }
                                    >
                                        <option value="daily">{s.daily}</option>
                                        <option value="weekly">{s.weekly}</option>
                                        <option value="monthly">{s.monthly}</option>
                                    </select>
                                </label>
                                {form.recurrence === 'weekly' && (
                                    <label className="vy-report-field">
                                        <span>{s.weekday}</span>
                                        <select
                                            value={form.recurrence_day}
                                            onChange={(event) => update({ recurrence_day: Number(event.target.value) })}
                                        >
                                            {Array.from({ length: 7 }, (_, i) => (
                                                <option value={i + 1} key={i}>
                                                    {new Date(Date.UTC(2026, 0, 5 + i)).toLocaleDateString(undefined, {
                                                        weekday: 'long',
                                                        timeZone: 'UTC',
                                                    })}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                {form.recurrence === 'monthly' && (
                                    <label className="vy-report-field">
                                        <span>{s.day}</span>
                                        <input
                                            type="number"
                                            min={1}
                                            max={31}
                                            required
                                            value={form.recurrence_day}
                                            onChange={(event) => update({ recurrence_day: Number(event.target.value) })}
                                        />
                                    </label>
                                )}
                                <label className="vy-report-field">
                                    <span>{s.time}</span>
                                    <input
                                        type="time"
                                        required
                                        value={timeString(form.run_at_minute ?? 540)}
                                        onChange={(event) => {
                                            const [hour = 0, minute = 0] = event.target.value.split(':').map(Number);
                                            update({ run_at_minute: hour * 60 + minute });
                                        }}
                                    />
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.timezone}</span>
                                    <input
                                        required
                                        value={form.timezone}
                                        onChange={(event) => update({ timezone: event.target.value })}
                                    />
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.starts} {uiText("vidyalms.ui.ebff8ec22a794c60", " (UTC)")}</span>
                                    <input
                                        type="datetime-local"
                                        required
                                        value={(form.starts_at ?? '').replace(' ', 'T').slice(0, 16)}
                                        onChange={(event) =>
                                            update({ starts_at: event.target.value ? `${event.target.value}:00Z` : '' })
                                        }
                                    />
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.emptyPolicy}</span>
                                    <select
                                        value={form.empty_policy}
                                        onChange={(event) =>
                                            update({ empty_policy: event.target.value as Request['empty_policy'] })
                                        }
                                    >
                                        <option value="skip">{s.skip}</option>
                                        <option value="send">{s.send}</option>
                                        <option value="send_without_file">{s.sendWithoutFile}</option>
                                    </select>
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.state}</span>
                                    <select
                                        value={form.state}
                                        onChange={(event) => update({ state: event.target.value as Request['state'] })}
                                    >
                                        <option value="disabled">{s.disabled}</option>
                                        <option value="enabled">{s.enabled}</option>
                                    </select>
                                </label>
                            </div>
                        </section>
                        <details className="vy-report-form-section">
                            <summary>{s.messageOptions}</summary>
                            <div className="vy-report-fields">
                                <label className="vy-report-field">
                                    <span>{s.subject}</span>
                                    <input
                                        maxLength={250}
                                        value={form.subject ?? ''}
                                        onChange={(event) => update({ subject: event.target.value || null })}
                                    />
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.message}</span>
                                    <textarea
                                        value={form.body ?? ''}
                                        onChange={(event) => update({ body: event.target.value || null })}
                                    />
                                </label>
                                <label className="vy-report-field">
                                    <span>{s.audienceConfiguration}</span>
                                    <textarea
                                        defaultValue={JSON.stringify(form.audience_config, null, 2)}
                                        onChange={(event) => {
                                            try {
                                                const value: unknown = JSON.parse(event.target.value);
                                                if (!value || typeof value !== 'object' || Array.isArray(value))
                                                    throw new Error();
                                                update({ audience_config: value as Record<string, unknown> });
                                                event.target.setCustomValidity('');
                                            } catch {
                                                event.target.setCustomValidity(s.invalidJson);
                                            }
                                        }}
                                    />
                                </label>
                            </div>
                        </details>
                    </fieldset>
                    {error !== null && <ErrorState error={error} title={s.scheduleFailed} />}
                    <div className="vy-report-actions">
                        <Button type="button" variant="outline" disabled={pending} onClick={onClose}>
                            {s.cancel}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                pending ||
                                !report ||
                                !formats.length ||
                                !form.delivery_key ||
                                !ready(report, form.parameters as Parameters)
                            }
                        >
                            {pending ? s.saving : s.save}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
export function Schedules({ catalogue, organisation }: { catalogue: Catalogue; organisation: number | null }) {
    const [page, setPage] = useState(1);
    const [state, setState] = useState<'' | 'enabled' | 'disabled'>('');
    const [editing, setEditing] = useState<Schedule | 'new' | null>(null);
    const [running, setRunning] = useState<Schedule | null>(null);
    const [pending, setPending] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState<unknown>(null);
    const replay = useRef({ fingerprint: '', key: '' });
    const client = useApiClient();
    const cache = useQueryClient();
    const query = useSchedules(organisation, page, state || undefined);
    const act = async (schedule: Schedule, action: 'run' | 'toggle') => {
        const fingerprint = `${schedule.id}:${action}:${schedule.state}`;
        if (replay.current.fingerprint !== fingerprint) replay.current = { fingerprint, key: crypto.randomUUID() };
        setPending(true);
        setError(null);
        setMessage('');
        try {
            if (action === 'run') {
                const result = await runSchedule(client, schedule.id, replay.current.key);
                setMessage(s.queued(result.runs.length));
                setRunning(null);
            } else
                await saveSchedule(
                    client,
                    organisation,
                    schedule.id,
                    { state: schedule.state === 'enabled' ? 'disabled' : 'enabled' },
                    replay.current.key,
                );
            replay.current = { fingerprint: '', key: '' };
            await cache.invalidateQueries({ queryKey: [...reportKeys, 'schedules'] });
        } catch (failure) {
            setError(failure);
        } finally {
            setPending(false);
        }
    };
    return (
        <section className="vy-report-panel">
            <div className="vy-report-panel-heading">
                <div>
                    <h2>{s.schedules}</h2>
                    <p>{s.scheduleIntro}</p>
                </div>
                <Button
                    onClick={() => setEditing('new')}
                    disabled={!catalogue.reports.some((report) => report.exportable)}
                >
                    <Plus size={15} />
                    {s.newSchedule}
                </Button>
            </div>
            <div className="vy-report-panel-tools">
                <label className="vy-report-field">
                    <span>{s.state}</span>
                    <select
                        value={state}
                        onChange={(event) => {
                            setState(event.target.value as typeof state);
                            setPage(1);
                        }}
                    >
                        <option value="">{s.allStatuses}</option>
                        <option value="enabled">{s.enabled}</option>
                        <option value="disabled">{s.disabled}</option>
                    </select>
                </label>
            </div>
            {message && (
                <p role="status" className="vy-report-notice">
                    {message}
                </p>
            )}
            {error !== null && !running && <ErrorState error={error} />}
            {query.isError ? (
                <ErrorState error={query.error} onRetry={() => void query.refetch()} />
            ) : query.isPending ? (
                <Skeleton className="h-40 w-full" />
            ) : query.data.items.length ? (
                <ul className="vy-report-schedule-list">
                    {query.data.items.map((schedule) => (
                        <li key={schedule.id}>
                            <span className="vy-report-category-icon blue">
                                <CalendarClock size={22} />
                            </span>
                            <div className="vy-report-schedule-main">
                                <h3>{schedule.title}</h3>
                                <p>
                                    {catalogue.reports.find((report) => report.key === schedule.report_key)?.label ??
                                        humanize(schedule.report_key)}{' '}
                                    · {schedule.format_key.toUpperCase()}
                                </p>
                                <small>
                                    {humanize(schedule.recurrence)} · {timeString(schedule.run_at_minute)}{' '}
                                    {schedule.timezone} · {s.nextRun}:{' '}
                                    {schedule.next_run_at
                                        ? formatValue(schedule.next_run_at, 'next_run_at')
                                        : s.noNextRun}
                                </small>
                                {schedule.last_error && <p className="vy-report-notice">{schedule.last_error}</p>}
                            </div>
                            <Badge variant={schedule.state === 'enabled' ? 'ok' : 'neutral'}>
                                {schedule.state === 'enabled' ? s.enabled : s.disabled}
                            </Badge>
                            <div className="vy-report-actions">
                                {organisation === null && (
                                    <Button size="sm" variant="ghost" onClick={() => setEditing(schedule)}>
                                        <MoreHorizontal size={15} />
                                        {s.editSchedule}
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={pending}
                                    onClick={() => void act(schedule, 'toggle')}
                                >
                                    {schedule.state === 'enabled' ? <Pause size={14} /> : <Play size={14} />}
                                    {schedule.state === 'enabled' ? s.pause : s.resume}
                                </Button>
                                {organisation === null && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={pending}
                                        onClick={() => {
                                            setRunning(schedule);
                                            setError(null);
                                        }}
                                    >
                                        {s.runNow}
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <EmptyState title={s.scheduleEmpty} description={s.scheduleEmptyHint} />
            )}
            <div className="vy-report-pagination">
                <span>{s.records(query.data?.total ?? 0)}</span>
                <Pagination
                    label={uiText("vidyalms.ui.2aa65a6b96889f47", "Schedule pages")}
                    page={page}
                    perPage={20}
                    total={query.data?.total ?? 0}
                    onPageChange={setPage}
                />
            </div>
            {editing && (
                <ScheduleDialog
                    catalogue={catalogue}
                    organisation={organisation}
                    existing={editing === 'new' ? undefined : editing}
                    onClose={() => setEditing(null)}
                />
            )}
            {running && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !pending) setRunning(null);
                    }}
                >
                    <DialogContent className="vy-report-dialog">
                        <DialogTitle>{s.confirmRun}</DialogTitle>
                        <DialogDescription>
                            {running.title}. {s.confirmRunHint}
                        </DialogDescription>
                        {error !== null && <ErrorState error={error} />}
                        <div className="vy-report-actions">
                            <Button variant="outline" disabled={pending} onClick={() => setRunning(null)}>
                                {s.cancel}
                            </Button>
                            <Button disabled={pending} onClick={() => void act(running, 'run')}>
                                {s.runNow}
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}
