import { useState } from 'react';
import type { EnrolmentRecord } from '@vidyalms/api-sdk';
import { useAnnounce } from '@/shared/a11y';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    SaveIndicator,
} from '@/shared/components';
import { SaveError } from '../components/states';
import { useExtendEntitlement } from '../api';
import { instructorStrings as strings } from '../strings';
import { isApiDate } from '../lib/format';
import { useSaveTracker } from '../lib/save-state';
import { Field, TextInput } from '../components/field';

/*
 * Re-activation (artboard 4a, the suspended row's action). Extending the
 * entitlement is what resumes a suspended enrolment, and it resumes it with
 * every lesson, score and submission intact, which is what the dialog says
 * before the person commits to it. A revoked entitlement answers 409: that
 * was a decision, and the recovery is a new grant, not an extension.
 */

export function ExtendDialog({
    courseId,
    enrolment,
    onClose,
}: {
    courseId: number;
    enrolment: EnrolmentRecord;
    onClose: () => void;
}) {
    const extendEntitlement = useExtendEntitlement();
    const save = useSaveTracker();
    const announce = useAnnounce();
    const [expiresAt, setExpiresAt] = useState('');
    const [dateError, setDateError] = useState<string | null>(null);
    const name = enrolment.user.name ?? strings.settings.instructors.unknown;

    const submit = () => {
        const expires = expiresAt.trim();
        if (expires !== '' && !isApiDate(expires)) {
            setDateError(strings.form.dateFormat);
            return;
        }
        setDateError(null);
        void save
            .run(async (idempotencyKey) => {
                const grant = await extendEntitlement.mutateAsync({
                    courseId,
                    entitlementId: enrolment.entitlement.id,
                    expiresAt: expires === '' ? null : expires,
                    idempotencyKey,
                });
                announce(strings.extendDialog.done(name));
                return grant;
            })
            .then((ok) => {
                if (ok) {
                    onClose();
                }
            });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>{strings.extendDialog.title}</DialogTitle>
                <DialogDescription>{strings.extendDialog.description}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    <Field
                        label={strings.extendDialog.expiresAt}
                        hint={strings.extendDialog.expiresAtHint}
                        error={dateError ?? undefined}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                autoFocus
                                placeholder="2027-01-31 23:59:00"
                                aria-describedby={describedBy}
                                invalid={invalid}
                                value={expiresAt}
                                onChange={(event) => setExpiresAt(event.target.value)}
                            />
                        )}
                    </Field>
                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
                    <DialogFooter className="items-center">
                        <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                        <Button variant="outline" size="sm" onClick={onClose}>
                            {strings.form.cancel}
                        </Button>
                        <Button size="sm" onClick={submit} disabled={save.state === 'saving'}>
                            {strings.extendDialog.submit}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}
