import { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Users } from 'lucide-react';
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
import { newIdempotencyKey, usePreviewAutomationRule, automationKeys } from './api';
import { useAdminLearners } from '../api';
import { useDebounced } from '../hooks';
import { automationStrings as strings } from './strings';

/*
 * The rehearsal. It posts to the same rule the real trigger fires, with the
 * dry-run flag set, so what is shown here is what the engine would really do
 * rather than a second implementation of the same decisions.
 */
/** "482, 483" becomes [482, 483]; anything that is not a positive id is dropped. */
export function parseSubjectIds(raw: string): number[] {
    const ids: number[] = [];
    for (const part of raw.split(',')) {
        const trimmed = part.trim();
        if (!/^[1-9][0-9]*$/.test(trimmed)) continue;
        const value = Number(trimmed);
        if (Number.isSafeInteger(value) && !ids.includes(value)) ids.push(value);
    }
    return ids;
}

export function AutomationPreviewPanel({ ruleId }: { ruleId: number }) {
    const [subject, setSubject] = useState('');
    const [picker, setPicker] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const debounced = useDebounced(search);
    const learners = useAdminLearners({ q: debounced || undefined, page, per_page: 20 }, { enabled: picker });
    const cache = useQueryClient();
    const replay = useRef({ fingerprint: '', key: '' });
    const preview = usePreviewAutomationRule();
    const result = preview.data;
    const subjectIds = parseSubjectIds(subject);
    const valid =
        subject.trim() !== '' &&
        subject.split(',').every((part) => /^[1-9]\d*$/.test(part.trim()) && Number.isSafeInteger(Number(part.trim())));
    const changeSubjects = (value: string) => {
        setSubject(value);
        preview.reset();
    };

    return (
        <section className="grid gap-2 rounded-lg border border-border bg-surface p-4">
            <h3 className="font-display text-sm font-semibold text-fg">{strings.preview.heading}</h3>
            <p className="max-w-[70ch] text-xs text-fg-2">{strings.preview.body}</p>
            <div className="flex flex-wrap items-end gap-2">
                <label className="grid gap-1 text-xs font-semibold text-fg-2" htmlFor="automation-preview-subject">
                    {strings.preview.subjectLabel}
                    <input
                        id="automation-preview-subject"
                        type="text"
                        inputMode="numeric"
                        className="h-8 w-52 rounded border border-border bg-surface px-2 text-[13px] text-fg"
                        value={subject}
                        disabled={preview.isPending}
                        onChange={(event) => changeSubjects(event.target.value)}
                        aria-describedby="automation-preview-subject-help"
                    />
                    <span id="automation-preview-subject-help" className="font-normal text-fg-2">
                        {strings.preview.subjectHelp}
                    </span>
                </label>
                <Button variant="outline" disabled={preview.isPending} onClick={() => setPicker(true)}>
                    <Users size={15} />
                    {strings.workspace.chooseLearners}
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={preview.isPending || !valid || subjectIds.length === 0}
                    onClick={() => {
                        const fingerprint = JSON.stringify([ruleId, subjectIds]);
                        if (replay.current.fingerprint !== fingerprint)
                            replay.current = { fingerprint, key: newIdempotencyKey() };
                        preview.mutate(
                            {
                                id: ruleId,
                                subjectIds,
                                idempotencyKey: replay.current.key,
                            },
                            {
                                onSuccess: () => {
                                    replay.current = { fingerprint: '', key: '' };
                                    void cache.invalidateQueries({ queryKey: automationKeys.root });
                                },
                            },
                        );
                    }}
                >
                    {preview.isPending ? strings.preview.running : strings.preview.run}
                </Button>
            </div>
            {subject !== '' && !valid && <p className="text-xs text-danger">{strings.workspace.invalidSubjects}</p>}

            {preview.isError && <ErrorState error={preview.error} title={strings.preview.errorTitle} />}

            {result !== undefined && (
                <div className="grid gap-2" role="status">
                    <p className="text-[13px] font-semibold text-fg">{strings.preview.matched(result.matched_count)}</p>
                    {result.truncated && <p className="text-xs text-fg-2">{strings.preview.truncated}</p>}
                    {result.steps.length === 0 ? (
                        <p className="text-xs text-fg-2">{strings.preview.noSteps}</p>
                    ) : (
                        <ul className="grid gap-1.5">
                            {result.steps.map((step, index) => (
                                <li
                                    key={`${step.step_index}-${step.subject_id ?? 'all'}-${index}`}
                                    className="flex flex-wrap items-center gap-2 rounded border border-border px-3 py-2 text-xs"
                                >
                                    <Badge
                                        variant={
                                            step.disposition === 'executed'
                                                ? 'ok'
                                                : step.disposition === 'failed'
                                                  ? 'danger'
                                                  : 'neutral'
                                        }
                                    >
                                        {strings.preview.disposition[step.disposition]}
                                    </Badge>
                                    <span className="font-semibold text-fg">
                                        {strings.editor.stepLabel(step.step_index)}
                                    </span>
                                    <span className="text-fg-2">{step.action_key}</span>
                                    {step.subject_id !== null && step.subject_id !== undefined && (
                                        <span className="text-fg-2">#{step.subject_id}</span>
                                    )}
                                    {step.suppressed_reason !== null && step.suppressed_reason !== undefined && (
                                        <span className="text-warn-strong">{step.suppressed_reason}</span>
                                    )}
                                    {step.estimated_cost !== null && step.estimated_cost !== undefined && (
                                        <span className="text-fg-2">
                                            {strings.preview.cost(step.estimated_cost.unit, step.estimated_cost.amount)}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {result.warnings.length > 0 && (
                        <div className="text-xs text-warn-strong">
                            <p className="font-semibold">{strings.preview.warnings}</p>
                            <ul className="list-disc pl-4">
                                {result.warnings.map((warning) => (
                                    <li key={warning}>{warning}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
            <Dialog open={picker} onOpenChange={setPicker}>
                <DialogContent className="vy-auto-dialog">
                    <DialogTitle>{strings.workspace.chooseLearners}</DialogTitle>
                    <DialogDescription>{strings.workspace.chooseLearnersHint}</DialogDescription>
                    <input
                        type="search"
                        aria-label={strings.workspace.searchLearners}
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                    />
                    {learners.isError ? (
                        <ErrorState error={learners.error} onRetry={() => void learners.refetch()} />
                    ) : learners.isPending ? (
                        <Skeleton className="h-40 w-full" />
                    ) : (
                        <>
                            <ul className="vy-auto-picker-list">
                                {learners.data.items.map((learner) => (
                                    <li key={learner.user_id}>
                                        <Button
                                            variant="ghost"
                                            disabled={learners.isFetching || search !== debounced || !learner.user}
                                            aria-pressed={subjectIds.includes(learner.user_id)}
                                            onClick={() => {
                                                changeSubjects(
                                                    [...new Set([...subjectIds, learner.user_id])].join(', '),
                                                );
                                            }}
                                        >
                                            <span>
                                                {learner.user?.name || `#${learner.user_id}`}
                                                <small>{learner.user?.email}</small>
                                            </span>
                                            {subjectIds.includes(learner.user_id) && '✓'}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            {!learners.data.items.length && <EmptyState title={strings.workspace.noLearners} />}
                            <Pagination
                                label={strings.workspace.chooseLearners}
                                page={page}
                                perPage={20}
                                total={learners.data.total}
                                onPageChange={setPage}
                            />
                        </>
                    )}
                    <Button onClick={() => setPicker(false)}>{strings.workspace.done}</Button>
                </DialogContent>
            </Dialog>
        </section>
    );
}
