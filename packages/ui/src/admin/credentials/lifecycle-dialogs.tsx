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
import type { CertificateRecord } from '@vidyalms/api-sdk';
import { SelectField, TextField } from '../components/field';
import { CheckboxField } from './fields';
import { credentialStrings } from '../credential-strings';
import { adminStrings } from '../strings';
import { useReinstateCertificate, useReissueCertificate, useSuspendCertificate } from './api';

/*
 * The three lifecycle ceremonies beside Revoke, which already has one.
 *
 * Each is focus trapped and verb labelled, and each says what the public
 * verification page will report afterwards, because that is the consequence
 * an administrator is actually deciding about. Suspension asks for a reason
 * for the same evidentiary purpose revocation does: a reversible hold that
 * nobody recorded a reason for is unanswerable at the next audit.
 */

const strings = credentialStrings;

export interface CertificateDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    certificate: CertificateRecord;
    onDone: (message: string) => void;
}

const suspendReasonKeys = Object.keys(strings.suspend.reasons);

export function SuspendDialog({ open, onOpenChange, certificate, onDone }: CertificateDialogProps) {
    const suspend = useSuspendCertificate();
    const [reason, setReason] = useState(suspendReasonKeys[0] ?? 'under_investigation');

    const close = (next: boolean) => {
        if (!next) {
            suspend.reset();
            setReason(suspendReasonKeys[0] ?? 'under_investigation');
        }
        onOpenChange(next);
    };

    const confirm = async () => {
        try {
            await suspend.mutateAsync({ certificateId: certificate.id, reason });
        } catch {
            return;
        }
        onDone(strings.suspend.done(certificate.code));
        close(false);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogTitle>{strings.suspend.title}</DialogTitle>
                <DialogDescription>
                    {strings.suspend.body(
                        certificate.code,
                        certificate.learner.name ?? adminStrings.enrolments.unknownLearner,
                        certificate.course.title,
                    )}
                </DialogDescription>
                <SelectField
                    fieldClassName="mt-3"
                    label={strings.suspend.reasonLabel}
                    hint={strings.suspend.reasonHint}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                >
                    {suspendReasonKeys.map((key) => (
                        <option key={key} value={key}>
                            {strings.suspend.reasons[key]}
                        </option>
                    ))}
                </SelectField>
                {suspend.isError && <ErrorState className="mt-3" error={suspend.error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => close(false)}>
                        {strings.suspend.cancel}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={suspend.isPending}
                        onClick={() => {
                            void confirm();
                        }}
                    >
                        {suspend.isPending ? strings.suspend.submitting : strings.suspend.submit}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ReinstateDialog({ open, onOpenChange, certificate, onDone }: CertificateDialogProps) {
    const reinstate = useReinstateCertificate();

    const close = (next: boolean) => {
        if (!next) {
            reinstate.reset();
        }
        onOpenChange(next);
    };

    const confirm = async () => {
        try {
            await reinstate.mutateAsync({ certificateId: certificate.id });
        } catch {
            return;
        }
        onDone(strings.reinstate.done(certificate.code));
        close(false);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogTitle>{strings.reinstate.title}</DialogTitle>
                <DialogDescription>{strings.reinstate.body(certificate.code)}</DialogDescription>
                {reinstate.isError && <ErrorState className="mt-3" error={reinstate.error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => close(false)}>
                        {strings.reinstate.cancel}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={reinstate.isPending}
                        onClick={() => {
                            void confirm();
                        }}
                    >
                        {reinstate.isPending ? strings.reinstate.submitting : strings.reinstate.submit}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ReissueDialog({ open, onOpenChange, certificate, onDone }: CertificateDialogProps) {
    const reissue = useReissueCertificate();
    const [renewal, setRenewal] = useState(false);
    const [reason, setReason] = useState('');

    const close = (next: boolean) => {
        if (!next) {
            reissue.reset();
            setRenewal(false);
            setReason('');
        }
        onOpenChange(next);
    };

    const confirm = async () => {
        try {
            await reissue.mutateAsync({
                certificateId: certificate.id,
                body: { renewal, reason: reason.trim() === '' ? null : reason.trim() },
            });
        } catch {
            return;
        }
        onDone(strings.reissue.done(certificate.code));
        close(false);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogTitle>{strings.reissue.title}</DialogTitle>
                <DialogDescription>{strings.reissue.body(certificate.code)}</DialogDescription>
                <p className="mt-2 text-[12px] text-fg-2">{strings.reissue.carryForward}</p>
                <CheckboxField
                    className="mt-3"
                    label={strings.reissue.renewal}
                    hint={strings.reissue.renewalHint}
                    checked={renewal}
                    onChange={(event) => setRenewal(event.target.checked)}
                />
                <TextField
                    fieldClassName="mt-3"
                    label={strings.reissue.reasonLabel}
                    hint={strings.reissue.reasonHint}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                />
                {reissue.isError && <ErrorState className="mt-3" error={reissue.error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => close(false)}>
                        {strings.reissue.cancel}
                    </Button>
                    <Button
                        variant="primary"
                        size="sm"
                        disabled={reissue.isPending}
                        onClick={() => {
                            void confirm();
                        }}
                    >
                        {reissue.isPending ? strings.reissue.submitting : strings.reissue.submit}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
