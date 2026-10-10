import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import type { AuthoringQuestion, QuizSummary } from '@vidyalms/api-sdk';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    SaveIndicator,
} from '@/shared/components';
import { useRegradeQuiz } from '../assessment-api';
import { assessmentStrings as strings } from '../assessment-strings';
import { instructorStrings as workbenchStrings } from '../strings';
import { useSaveTracker } from '../lib/save-state';
import { pointsText } from '../lib/format';
import { SaveError } from '../components/states';
import { CheckboxField, Field, Select, TextArea } from '../components/field';
import type { RegradePolicy, RegradeResult } from '../lib/assessment-types';

/*
 * Regrade: a first-class operation, not an edge case.
 *
 * It re-runs marking against the frozen manifest of what each learner was
 * actually served and the definition each answer was graded from, and it
 * APPENDS grade rows rather than mutating them, so the history stays intact
 * and every result for the same item carries its own datestamp.
 *
 * Two things make this screen honest rather than merely functional. The four
 * policies carry the names a migrating teacher already knows, and each one
 * states its consequence in a sentence before it is chosen, because
 * "corrected key only" is the one that can take marks away from people who
 * have already seen them. And the dry run comes first: the same computation,
 * nothing written, and a table of exactly which attempts would move.
 */

const POLICIES: RegradePolicy[] = ['corrected_key_only', 'both_keys', 'any_answer', 'full_credit'];

function ChangeTable({ result }: { result: RegradeResult }) {
    if (result.items.length === 0) {
        return (
            <p className="text-[12.5px] text-fg-2">
                {result.dry_run ? strings.regrade.nothingChanged : strings.regrade.nothingChangedApplied}
            </p>
        );
    }
    return (
        <div className="overflow-x-auto rounded border border-border">
            <table className="w-full min-w-[440px] border-collapse text-[12px]">
                <caption className="sr-only">
                    {result.dry_run ? strings.regrade.resultTitle : strings.regrade.resultAppliedTitle}
                </caption>
                <thead>
                    <tr className="bg-surface-2 text-left font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2">
                        <th scope="col" className="px-3 py-2">
                            {strings.regrade.columns.learner}
                        </th>
                        <th scope="col" className="px-3 py-2">
                            {strings.regrade.columns.attempt}
                        </th>
                        <th scope="col" className="px-3 py-2">
                            {strings.regrade.columns.before}
                        </th>
                        <th scope="col" className="px-3 py-2">
                            {strings.regrade.columns.after}
                        </th>
                        <th scope="col" className="px-3 py-2">
                            {strings.regrade.columns.result}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {result.items.map((change) => (
                        <tr key={change.attempt_id} className="border-t border-border/60">
                            <td className="px-3 py-2">{change.user_id}</td>
                            <td className="px-3 py-2">{strings.regrade.attemptNumber(change.attempt_number)}</td>
                            <td className="px-3 py-2 tabular-nums">
                                {change.score_before === null
                                    ? strings.regrade.noScore
                                    : pointsText(change.score_before)}
                            </td>
                            <td className="px-3 py-2 font-semibold tabular-nums">
                                {change.score_after === null
                                    ? strings.regrade.noScore
                                    : pointsText(change.score_after)}
                            </td>
                            <td className="px-3 py-2">
                                {change.passed_after === null
                                    ? strings.regrade.noScore
                                    : change.passed_after
                                      ? strings.regrade.passed
                                      : strings.regrade.notPassed}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function RegradeDialog({
    courseId,
    quiz,
    questions,
    onClose,
}: {
    courseId: number;
    quiz: QuizSummary;
    questions: readonly AuthoringQuestion[];
    onClose: () => void;
}) {
    const regrade = useRegradeQuiz();
    const save = useSaveTracker();
    const [policy, setPolicy] = useState<RegradePolicy>('both_keys');
    const [reason, setReason] = useState('');
    const [chosen, setChosen] = useState<number[]>([]);
    const [subset, setSubset] = useState(false);
    const [preview, setPreview] = useState<RegradeResult | null>(null);
    const [applied, setApplied] = useState<RegradeResult | null>(null);

    const busy = save.state === 'saving';

    const run = (dryRun: boolean) => {
        void save
            .run(async (idempotencyKey) => {
                const result = await regrade.mutateAsync({
                    courseId,
                    quizId: quiz.id,
                    body: {
                        policy,
                        dry_run: dryRun,
                        reason: reason.trim() === '' ? null : reason.trim(),
                        ...(subset && chosen.length > 0 ? { question_ids: chosen } : {}),
                    },
                    idempotencyKey,
                });
                if (dryRun) {
                    setPreview(result);
                } else {
                    setApplied(result);
                    setPreview(null);
                }
                return result;
            })
            .catch(() => undefined);
    };

    const shown = applied ?? preview;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogTitle>{strings.regrade.title}</DialogTitle>
                <DialogDescription>{strings.regrade.description}</DialogDescription>
                <div className="mt-3.5 grid max-h-[500px] gap-3.5 overflow-y-auto pr-1">
                    <Field label={strings.regrade.policy}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={policy}
                                disabled={busy || applied !== null}
                                onChange={(event) => {
                                    setPolicy(event.target.value as RegradePolicy);
                                    setPreview(null);
                                }}
                            >
                                {POLICIES.map((entry) => (
                                    <option key={entry} value={entry}>
                                        {strings.regrade.policies[entry]}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    {/* The consequence of the chosen rule, stated before it
                        runs, because one of the four can take marks away. */}
                    <p
                        role="status"
                        className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-3 py-2 text-[12px] leading-[1.55] text-fg-2"
                    >
                        {strings.regrade.consequences[policy]}
                    </p>

                    <CheckboxField
                        label={strings.regrade.questionsSome}
                        hint={strings.regrade.questionsAll}
                        checked={subset}
                        disabled={busy || applied !== null}
                        onChange={(event) => {
                            setSubset(event.target.checked);
                            setPreview(null);
                        }}
                    />
                    {subset && (
                        <fieldset className="grid gap-1.5 rounded border border-border p-3">
                            <legend className="px-1 font-display text-[11.5px] font-semibold text-fg">
                                {strings.regrade.questions}
                            </legend>
                            {questions.map((question, index) => (
                                <CheckboxField
                                    key={question.id}
                                    label={
                                        typeof question.definition.prompt === 'string'
                                            ? question.definition.prompt
                                            : uiText("vidyalms.ui.0ff30f0c47251567", "{p0}. {p1}", { p0: index + 1, p1: question.type })
                                    }
                                    checked={chosen.includes(question.id)}
                                    disabled={busy || applied !== null}
                                    onChange={(event) => {
                                        setChosen((current) =>
                                            event.target.checked
                                                ? [...current, question.id]
                                                : current.filter((id) => id !== question.id),
                                        );
                                        setPreview(null);
                                    }}
                                />
                            ))}
                        </fieldset>
                    )}

                    <Field label={strings.regrade.reason}>
                        {({ id }) => (
                            <TextArea
                                id={id}
                                rows={2}
                                value={reason}
                                disabled={busy || applied !== null}
                                placeholder={strings.regrade.reasonPlaceholder}
                                onChange={(event) => setReason(event.target.value)}
                            />
                        )}
                    </Field>

                    {shown !== null && (
                        <section className="grid gap-2">
                            <h4 className="font-display text-xs font-semibold text-fg">
                                {shown.dry_run ? strings.regrade.resultTitle : strings.regrade.resultAppliedTitle}
                            </h4>
                            <p className="text-[11.5px] text-fg-2">
                                {[
                                    strings.regrade.examined(shown.attempts_examined),
                                    strings.regrade.changed(shown.attempts_changed),
                                    strings.regrade.appended(shown.grades_appended),
                                ].join(' · ')}
                            </p>
                            <ChangeTable result={shown} />
                            <p className="text-[11.5px] text-fg-2">
                                {shown.dry_run ? strings.regrade.stillDryRun : strings.regrade.appliedNote}
                            </p>
                            {shown.dry_run && shown.attempts_changed > 0 && (
                                <p
                                    role="status"
                                    className="rounded border border-border border-l-[3px] border-l-danger bg-surface px-3 py-2 text-[12px] text-fg"
                                >
                                    {strings.regrade.confirmWarning(shown.attempts_changed)}
                                </p>
                            )}
                        </section>
                    )}

                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                </div>
                <DialogFooter className="items-center">
                    <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                    <Button type="button" variant="outline" size="sm" onClick={onClose}>
                        {applied === null ? workbenchStrings.form.cancel : strings.common.close}
                    </Button>
                    {applied === null && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() => run(true)}
                            >
                                {strings.regrade.dryRun}
                            </Button>
                            {/* The real regrade is only offered once the dry
                                run has said what it would do: an irreversible
                                change to marks people have seen is not a
                                first click. */}
                            <Button type="button" size="sm" disabled={busy || preview === null} onClick={() => run(false)}>
                                {busy ? strings.regrade.applying : strings.regrade.confirm}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
