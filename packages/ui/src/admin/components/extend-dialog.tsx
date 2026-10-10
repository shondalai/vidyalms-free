import { useEffect, useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
} from '@/shared/components';
import { useExtendEntitlement } from '../api';
import { endOfDayUtc, expiryText, parseApiDate, todayIsoDate } from '../lib';
import { adminStrings } from '../strings';
import { RadioGroup, TextField } from './field';

/*
 * Extend, and its other name on the learner record, Re-activate. One field
 * with two shapes: never, or a date. The contract reactivates an expired
 * entitlement and resumes the suspended enrolment with its progress, which
 * is why the same dialog serves both buttons.
 */

const strings = adminStrings.extend;

export interface ExtendDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    entitlementId: number;
    learnerName: string;
    courseTitle: string;
    /** The expiry as it stands, shown so the reader knows what they change. */
    currentExpiry: string | null;
    locale: string;
    /** Label of the confirm button; "Re-activate" on the learner record. */
    submitLabel?: string;
    onDone: (message: string) => void;
}

export function ExtendDialog({
    open,
    onOpenChange,
    entitlementId,
    learnerName,
    courseTitle,
    currentExpiry,
    locale,
    submitLabel,
    onDone,
}: ExtendDialogProps) {
    const extend = useExtendEntitlement();
    const [mode, setMode] = useState<'never' | 'date'>(currentExpiry === null ? 'never' : 'date');
    const [date, setDate] = useState(() => parseApiDate(currentExpiry)?.toISOString().slice(0, 10) ?? '');
    const [error, setError] = useState<string | undefined>(undefined);

    /* Reopening for another row must not keep the previous row's answer. */
    useEffect(() => {
        if (open) {
            setMode(currentExpiry === null ? 'never' : 'date');
            setDate(parseApiDate(currentExpiry)?.toISOString().slice(0, 10) ?? '');
            setError(undefined);
        }
    }, [open, currentExpiry, entitlementId]);

    const close = (next: boolean) => {
        if (!next) {
            extend.reset();
        }
        onOpenChange(next);
    };

    const submit = async () => {
        if (mode === 'date' && date.trim() === '') {
            setError(adminStrings.grant.expiryDateRequired);
            return;
        }
        setError(undefined);

        // Caught here rather than left to the page: mutateAsync rejects on a
        // refused or failed write, the banner below already renders that from
        // the mutation's own state, and an uncaught rejection would only
        // reach the host's error reporting as something nobody threw.
        try {
            await extend.mutateAsync({
                entitlementId,
                expiresAt: mode === 'date' ? endOfDayUtc(date) : null,
            });
        } catch {
            return;
        }

        onDone(strings.done);
        close(false);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogTitle>{strings.title}</DialogTitle>
                <DialogDescription>{strings.description(learnerName, courseTitle)}</DialogDescription>
                <form
                    className="mt-3.5 grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <p className="text-[11.5px] text-fg-2">
                        {strings.currentExpiry(expiryText(currentExpiry, locale).text)}
                    </p>
                    <RadioGroup
                        legend={adminStrings.grant.expiryLabel}
                        name="extend-expiry"
                        value={mode}
                        options={[
                            { value: 'never', label: adminStrings.grant.expiryNever },
                            { value: 'date', label: adminStrings.grant.expiryOn },
                        ]}
                        onChange={(next) => setMode(next === 'date' ? 'date' : 'never')}
                    />
                    {mode === 'date' && (
                        <TextField
                            type="date"
                            min={todayIsoDate()}
                            label={adminStrings.grant.expiryDateLabel}
                            value={date}
                            error={error}
                            onChange={(event) => setDate(event.target.value)}
                        />
                    )}
                    {extend.isError && <ErrorState error={extend.error} />}
                    <DialogFooter>
                        <Button variant="outline" size="sm" onClick={() => close(false)}>
                            {strings.cancel}
                        </Button>
                        <Button type="submit" size="sm" disabled={extend.isPending}>
                            {extend.isPending ? strings.submitting : (submitLabel ?? strings.submit)}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
