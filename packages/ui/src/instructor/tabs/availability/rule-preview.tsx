import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import type { UserAccount } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import {
    Badge,
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
} from '@/shared/components';
import { openingReading } from '@/shared/availability';
import { usePreviewAvailability } from '@/shared/flow';
import { flowStrings } from '../../flow-strings';
import { Field, TextInput } from '../../components/field';
import { LearnerPicker } from '../../components/learner-picker';
import type {
    AvailabilityNode,
    AvailabilityPreview,
    AvailabilityPreviewLeaf,
    AvailabilitySubjectType,
} from '../../lib/flow-types';

/*
 * The rule preview.
 *
 * "Why can this student not see it" is a permanent support genre in the
 * product this grammar comes from, because there the equivalent exists only
 * as a debug string for unit tests. Here it is a screen: the composed
 * outcome, then every leaf with its own answer and its own describe()
 * sentence.
 *
 * This is the ONE surface where `never` and `exhausted` are visible. To a
 * learner an item that will never open and an item that has closed both read
 * as blocked with a reason, and publishing the difference would tell them
 * which exclusions were administrative. An author has to see it, because it
 * is exactly what decides whether the item counts towards completion, so the
 * screen states the denominator answer in words rather than leaving it to be
 * inferred from a state name.
 */

const strings = flowStrings.preview;

function OutcomeBadge({ state }: { state: string }) {
    const variant = state === 'available' ? 'ok' : state === 'never' ? 'danger' : 'warn';
    return <Badge variant={variant}>{strings.states[state] ?? state}</Badge>;
}

function LeafRow({ leaf, locale }: { leaf: AvailabilityPreviewLeaf; locale: string }) {
    const missing = leaf.available === false;
    const opening = openingReading(leaf.outcome.opens_at, leaf.outcome.opens_at_timezone, locale);
    return (
        <li className="grid gap-1 border-b border-border/60 px-3 py-2 last:border-b-0">
            <div className="flex flex-wrap items-center gap-2">
                <span
                    aria-hidden="true"
                    className={cn(
                        'inline-block h-2.5 w-2.5 shrink-0 rounded-full',
                        missing ? 'bg-warn' : leaf.passed ? 'bg-ok' : 'bg-danger',
                    )}
                />
                <span className="font-display text-[11.5px] font-semibold text-fg">{leaf.type}</span>
                <span className="text-[11px] text-fg-2">{leaf.path}</span>
                <span className="ml-auto text-[11.5px] font-medium">
                    {missing ? strings.leafMissing : leaf.passed ? strings.leafPassed : strings.leafFailed}
                </span>
            </div>
            <p className="text-[11.5px] text-fg-2">
                {leaf.description.key}
                {leaf.negated === true && uiText("vidyalms.ui.0337c2b7db52821f", " ({p0})", { p0: flowStrings.availability.negate })}
            </p>
            {opening !== null && <p className="text-[11.5px] text-fg-2">{strings.opensAt(opening.local)}</p>}
            {missing && <p className="text-[11.5px] text-warn-strong">{strings.leafMissingHint}</p>}
        </li>
    );
}

function Result({ preview, locale }: { preview: AvailabilityPreview; locale: string }) {
    const outcome = preview.outcome;
    const opening = openingReading(outcome.opens_at, outcome.opens_at_timezone, locale);
    const counts = outcome.counts_towards_completion ?? outcome.state !== 'never';
    return (
        <div className="grid gap-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-display text-xs font-semibold text-fg">{strings.outcome}</span>
                <OutcomeBadge state={outcome.state} />
                {outcome.exhausted && <Badge variant="neutral">{strings.exhausted}</Badge>}
            </div>
            <p className="text-[12px] text-fg-2">
                {opening === null ? strings.noOpensAt : strings.opensAt(opening.local)}
            </p>
            <p className="text-[12px]">
                <strong className={cn('font-semibold', counts ? 'text-fg' : 'text-warn-strong')}>
                    {counts ? strings.counts : strings.doesNotCount}
                </strong>{' '}
                <span className="text-fg-2">{strings.countsHint}</span>
            </p>
            {preview.leaves.length === 0 ? (
                <p className="text-[12px] text-fg-2">{strings.noLeaves}</p>
            ) : (
                <ul className="rounded border border-border bg-bg">
                    {preview.leaves.map((leaf) => (
                        <LeafRow key={leaf.path} leaf={leaf} locale={locale} />
                    ))}
                </ul>
            )}
        </div>
    );
}

export interface RulePreviewProps {
    open: boolean;
    onClose: () => void;
    courseId: number;
    subjectType: AvailabilitySubjectType;
    subjectId: number;
    /** The unsaved tree, when the author has one; null evaluates what is stored. */
    draft: AvailabilityNode | null;
    locale: string;
}

export function RulePreview({
    open,
    onClose,
    courseId,
    subjectType,
    subjectId,
    draft,
    locale,
}: RulePreviewProps) {
    const [learner, setLearner] = useState<UserAccount | null>(null);
    const [asOf, setAsOf] = useState('');
    const [needsLearner, setNeedsLearner] = useState(false);
    const preview = usePreviewAvailability();

    const run = () => {
        if (learner === null) {
            setNeedsLearner(true);
            return;
        }
        setNeedsLearner(false);
        preview.mutate({
            body: {
                user_id: learner.id,
                subject_type: subjectType,
                subject_id: subjectId,
                course_id: courseId,
                tree: draft,
                as_of: asOf.trim() === '' ? null : asOf.trim(),
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogTitle>{strings.title}</DialogTitle>
                <DialogDescription>{strings.description}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    <LearnerPicker
                        label={strings.learner}
                        value={learner}
                        onChange={setLearner}
                        error={needsLearner ? strings.pickLearner : undefined}
                        autoFocus
                    />
                    <Field label={strings.asOf} hint={strings.asOfHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={asOf}
                                onChange={(event) => setAsOf(event.target.value)}
                            />
                        )}
                    </Field>
                    {preview.isError && <ErrorState error={preview.error} />}
                    {preview.data !== undefined && <Result preview={preview.data} locale={locale} />}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {flowStrings.common.close}
                    </Button>
                    <Button onClick={run} disabled={preview.isPending}>
                        {preview.isPending ? strings.running : strings.run}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
