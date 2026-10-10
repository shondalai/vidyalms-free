import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
} from '@/shared/components';
import { useCreateCohort, useUpdateCohort } from '@/shared/flow';
import { flowStrings } from '../../flow-strings';
import { CheckboxField, Field, Select, TextInput } from '../../components/field';
import type { CohortDetail } from '../../lib/flow-types';

/*
 * Create or edit one run.
 *
 * Pacing is editable after the run has started, deliberately. The one
 * platform that forbids it does so because its absolute and relative date
 * systems were welded together late; here a pacing change is a schedule
 * change that recomputes and re-dedupes, and the previously set fixed dates
 * are kept so they can be restored.
 */

const strings = flowStrings.cohorts;

export function CohortDialog({
    courseId,
    cohort,
    siteTimezone,
    onClose,
}: {
    courseId: number;
    cohort: CohortDetail | null;
    siteTimezone: string;
    onClose: () => void;
}) {
    const create = useCreateCohort();
    const update = useUpdateCohort();
    const [title, setTitle] = useState(cohort?.title ?? '');
    const [code, setCode] = useState(cohort?.code ?? '');
    const [timezone, setTimezone] = useState(cohort?.timezone ?? siteTimezone);
    const [state, setState] = useState(cohort?.state ?? 'draft');
    const [pacing, setPacing] = useState(cohort?.pacing ?? 'fixed');
    const [startsAt, setStartsAt] = useState(cohort?.starts_at ?? '');
    const [endsAt, setEndsAt] = useState(cohort?.ends_at ?? '');
    const [enrolOpens, setEnrolOpens] = useState(cohort?.enrol_opens_at ?? '');
    const [enrolCloses, setEnrolCloses] = useState(cohort?.enrol_closes_at ?? '');
    const [capacity, setCapacity] = useState(cohort?.capacity === null || cohort === null ? '' : String(cohort.capacity));
    const [waitlist, setWaitlist] = useState(cohort?.waitlist_enabled ?? false);

    const optional = (value: string) => (value.trim() === '' ? null : value.trim());
    const pending = create.isPending || update.isPending;
    const error = create.error ?? update.error;

    const submit = () => {
        const body = {
            title: title.trim(),
            code: code.trim(),
            timezone: timezone.trim(),
            state,
            pacing,
            starts_at: optional(startsAt),
            ends_at: optional(endsAt),
            enrol_opens_at: optional(enrolOpens),
            enrol_closes_at: optional(enrolCloses),
            capacity: capacity.trim() === '' ? null : Number(capacity),
            waitlist_enabled: waitlist,
        };
        if (cohort === null) {
            create.mutate({ courseId, body }, { onSuccess: onClose });
            return;
        }
        update.mutate({ cohortId: cohort.id, body }, { onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogTitle>{cohort === null ? strings.add : strings.title}</DialogTitle>
                <DialogDescription>{strings.intro}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5 sm:grid-cols-2">
                    <Field label={strings.fields.title}>
                        {({ id }) => (
                            <TextInput id={id} autoFocus value={title} onChange={(event) => setTitle(event.target.value)} />
                        )}
                    </Field>
                    <Field label={strings.fields.code} hint={strings.fields.codeHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                value={code}
                                onChange={(event) => setCode(event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label={strings.fields.timezone} hint={strings.fields.timezoneHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                value={timezone}
                                onChange={(event) => setTimezone(event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label={strings.columns.state}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={state}
                                onChange={(event) => setState(event.target.value as typeof state)}
                            >
                                {Object.entries(strings.states).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field className="sm:col-span-2" label={strings.columns.pacing} hint={strings.pacingHint}>
                        {({ id, describedBy }) => (
                            <Select
                                id={id}
                                aria-describedby={describedBy}
                                value={pacing}
                                onChange={(event) => setPacing(event.target.value as typeof pacing)}
                            >
                                {Object.entries(strings.pacings).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                    <Field label={strings.fields.starts}>
                        {({ id }) => (
                            <TextInput
                                id={id}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={startsAt}
                                onChange={(event) => setStartsAt(event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label={strings.fields.ends}>
                        {({ id }) => (
                            <TextInput
                                id={id}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={endsAt}
                                onChange={(event) => setEndsAt(event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label={strings.fields.enrolOpens}>
                        {({ id }) => (
                            <TextInput
                                id={id}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={enrolOpens}
                                onChange={(event) => setEnrolOpens(event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label={strings.fields.enrolCloses}>
                        {({ id }) => (
                            <TextInput
                                id={id}
                                placeholder={uiText("vidyalms.ui.e17fab55b416d452", "YYYY-MM-DD hh:mm:ss")}
                                value={enrolCloses}
                                onChange={(event) => setEnrolCloses(event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label={strings.fields.capacity} hint={strings.fields.capacityHint}>
                        {({ id, describedBy }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                inputMode="numeric"
                                value={capacity}
                                onChange={(event) => setCapacity(event.target.value)}
                            />
                        )}
                    </Field>
                    <CheckboxField
                        label={strings.fields.waitlist}
                        checked={waitlist}
                        onChange={(event) => setWaitlist(event.target.checked)}
                    />
                    {error !== null && (
                        <div className="sm:col-span-2">
                            <ErrorState error={error} />
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {flowStrings.common.cancel}
                    </Button>
                    <Button onClick={submit} disabled={pending || title.trim() === '' || code.trim() === ''}>
                        {strings.create}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
