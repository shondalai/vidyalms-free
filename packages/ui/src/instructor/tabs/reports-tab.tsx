import { useState } from 'react';
import { cn } from '@/shared/cn';
import { Button, EmptyState, ErrorState, Pagination } from '@/shared/components';
import { useCourseQuizzes } from '../api';
import { useItemAnalysis } from '../assessment-api';
import { assessmentStrings as strings } from '../assessment-strings';
import { deniedFor } from '../lib/problems';
import { ListSkeleton, PermissionDenied } from '../components/states';
import { Field, Select } from '../components/field';
import type { ItemAnalysisRow, ItemDistractorRow } from '../lib/assessment-types';

/*
 * Item analysis, under the course reports.
 *
 * The inputs are a free-build obligation and the computation is a provider,
 * so a site with no provider is a NORMAL state and not an error: the route
 * answers 200 with computed false, an empty list and a reason, and this
 * screen renders a placeholder saying the data is being recorded and that
 * installing a provider later reports on every attempt already taken.
 *
 * Two rules of the design are visible in the table. A statistic below the
 * response threshold is withheld rather than shown small, because a
 * discrimination index on eleven responses is noise that reads like
 * evidence. And the row is keyed on the question VERSION and the marking
 * rule together: one banked version scored under two rules is two items as
 * far as a facility index is concerned, so the key is printed on the row
 * rather than assumed.
 */

const BAND_CLASS: Record<string, string> = {
    excellent: 'bg-ok-soft text-ok-strong',
    good: 'bg-ok-soft text-ok-strong',
    review: 'bg-warn-soft text-warn-strong',
    revise: 'bg-warn-soft text-warn-strong',
    negative: 'bg-danger-soft text-danger',
};

function figure(value: number | null | undefined): string {
    return value === null || value === undefined ? '·' : String(Math.round(value * 10) / 10);
}

function DistractorTable({ rows, label }: { rows: readonly ItemDistractorRow[]; label: string }) {
    return (
        <div className="overflow-x-auto rounded border border-border">
            <table className="w-full min-w-[420px] border-collapse text-[11.5px]">
                <caption className="sr-only">{label}</caption>
                <thead>
                    <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                        <th scope="col" className="px-2.5 py-1.5">
                            {strings.reports.distractorColumns.option}
                        </th>
                        <th scope="col" className="px-2.5 py-1.5">
                            {strings.reports.distractorColumns.upper}
                        </th>
                        <th scope="col" className="px-2.5 py-1.5">
                            {strings.reports.distractorColumns.lower}
                        </th>
                        <th scope="col" className="px-2.5 py-1.5">
                            {strings.reports.distractorColumns.biserial}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.option_key} className="border-t border-border/60">
                            <td className="px-2.5 py-1.5">
                                <span className={cn(row.keyed && 'font-semibold text-fg')}>
                                    {row.label ?? row.option_key}
                                </span>
                                {row.keyed && <span className="ml-1.5 text-[10px]">{strings.reports.keyed}</span>}
                                {row.flagged && (
                                    <span className="ml-1.5 rounded bg-warn-soft px-1.5 py-px text-[10px] font-semibold text-warn-strong">
                                        {row.flag_reason ?? strings.reports.flagged}
                                    </span>
                                )}
                            </td>
                            <td className="px-2.5 py-1.5 tabular-nums">{figure(row.rate_upper)}</td>
                            <td className="px-2.5 py-1.5 tabular-nums">{figure(row.rate_lower)}</td>
                            <td className="px-2.5 py-1.5 tabular-nums">{figure(row.point_biserial)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ItemRow({ row }: { row: ItemAnalysisRow }) {
    const [open, setOpen] = useState(false);
    const label = row.prompt ?? row.type ?? String(row.question_id);
    const distractors = row.distractors ?? [];
    const band = row.discrimination_band ?? null;

    return (
        <li className="border-b border-border/60 last:border-b-0">
            <div className="flex flex-wrap items-center gap-2.5 px-4 py-2.5 text-[12.5px]">
                <span className="min-w-0 flex-1">
                    <span className="line-clamp-1">{label}</span>
                    <span className="block text-[11px] text-fg-2">
                        {[
                            row.version === null || row.version === undefined
                                ? null
                                : strings.reports.version(row.version),
                            strings.reports.policyKey(row.policy_key),
                            row.below_threshold === true ? strings.reports.belowThreshold(row.n) : null,
                        ]
                            .filter((part): part is string => part !== null)
                            .join(' · ')}
                    </span>
                </span>
                <span className="w-10 shrink-0 text-right tabular-nums">{row.n}</span>
                <span className="w-16 shrink-0 text-right tabular-nums">
                    {figure(row.facility)}
                    {row.facility_ci_low !== null &&
                        row.facility_ci_low !== undefined &&
                        row.facility_ci_high !== null &&
                        row.facility_ci_high !== undefined && (
                            <span className="block text-[10px] font-normal text-fg-2">
                                {strings.reports.confidence(
                                    Math.round(row.facility_ci_low),
                                    Math.round(row.facility_ci_high),
                                )}
                            </span>
                        )}
                </span>
                <span className="w-14 shrink-0 text-right tabular-nums">{figure(row.standard_deviation)}</span>
                <span className="w-[130px] shrink-0 text-right">
                    <span className="tabular-nums">{figure(row.discrimination)}</span>
                    {band !== null && (
                        <span
                            className={cn(
                                'ml-1.5 rounded px-1.5 py-px text-[10px] font-semibold',
                                BAND_CLASS[band] ?? 'bg-surface-2 text-fg-2',
                            )}
                        >
                            {strings.reports.bands[band]}
                        </span>
                    )}
                </span>
                {distractors.length > 0 && (
                    <Button variant="ghost" size="sm" onClick={() => setOpen((current) => !current)}>
                        <span className="sr-only">{strings.reports.showDistractors(label)}</span>
                        <span aria-hidden="true">{strings.reports.distractorsTitle}</span>
                    </Button>
                )}
            </div>
            {open && distractors.length > 0 && (
                <div className="px-4 pb-3">
                    <DistractorTable rows={distractors} label={strings.reports.showDistractors(label)} />
                </div>
            )}
        </li>
    );
}

export function ReportsTab({ courseId }: { courseId: number; narrow?: boolean }) {
    const quizzesQuery = useCourseQuizzes(courseId);
    const [quizId, setQuizId] = useState<number | null>(null);
    const [page, setPage] = useState(1);
    const report = useItemAnalysis(courseId, {
        quiz_id: quizId ?? undefined,
        page,
        per_page: 25,
    });

    if (report.isPending) {
        return <ListSkeleton label={strings.common.loading} rows={5} />;
    }
    if (report.isError) {
        return deniedFor(report.error) ? (
            <PermissionDenied />
        ) : (
            <ErrorState
                error={report.error}
                title={strings.reports.errorTitle}
                onRetry={() => void report.refetch()}
            />
        );
    }

    const data = report.data;

    return (
        <section aria-label={strings.reports.title} className="rounded-lg border border-border bg-surface">
            <div className="flex flex-wrap items-end gap-3 border-b border-border px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-[13px] font-bold text-fg">{strings.reports.title}</h3>
                    <p className="text-[11.5px] text-fg-2">{strings.reports.description}</p>
                </div>
                <Field label={strings.reports.quiz} className="w-[220px]">
                    {({ id }) => (
                        <Select
                            id={id}
                            value={quizId === null ? '' : String(quizId)}
                            onChange={(event) => {
                                setQuizId(event.target.value === '' ? null : Number(event.target.value));
                                setPage(1);
                            }}
                        >
                            <option value="">{strings.reports.allQuizzes}</option>
                            {(quizzesQuery.data ?? []).map((quiz) => (
                                <option key={quiz.id} value={String(quiz.id)}>
                                    {quiz.title}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            </div>

            {!data.computed ? (
                <EmptyState
                    title={strings.reports.notComputedTitle}
                    description={data.reason ?? strings.reports.notComputedBody}
                />
            ) : data.items.length === 0 ? (
                <EmptyState title={strings.reports.emptyTitle} description={strings.reports.emptyBody} />
            ) : (
                <>
                    <div className="grid gap-1 border-b border-border px-4 py-2.5 text-[11.5px] text-fg-2">
                        <p>{strings.reports.minResponses(data.min_responses)}</p>
                        <p>{strings.reports.bandsHint}</p>
                        {data.internal_consistency !== null && data.internal_consistency !== undefined && (
                            <p>{strings.reports.internalConsistency(Math.round(data.internal_consistency * 10) / 10)}</p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2.5 border-b border-border bg-surface-2 px-4 py-1.5 font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                        <span className="min-w-0 flex-1">{strings.reports.columns.question}</span>
                        <span className="w-10 shrink-0 text-right">{strings.reports.columns.n}</span>
                        <span className="w-16 shrink-0 text-right">{strings.reports.columns.facility}</span>
                        <span className="w-14 shrink-0 text-right">{strings.reports.columns.sd}</span>
                        <span className="w-[130px] shrink-0 text-right">
                            {strings.reports.columns.discrimination}
                        </span>
                    </div>
                    <ul>
                        {data.items.map((row) => (
                            <ItemRow key={`${row.question_version_id ?? row.question_id}-${row.policy_key}`} row={row} />
                        ))}
                    </ul>
                    {data.total > data.per_page && (
                        <div className="p-3">
                            <Pagination
                                page={data.page}
                                perPage={data.per_page}
                                total={data.total}
                                label={strings.reports.title}
                                onPageChange={setPage}
                            />
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
