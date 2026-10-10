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
import { useRevokeCertificate } from '../api';
import { SelectField } from './field';
import { adminStrings } from '../strings';
import { credentialStrings } from '../credential-strings';

/*
 * Revoking a certificate is destructive in effect but not in storage: the
 * row stays and the public verification page keeps answering with the
 * reason "revoked". The dialog says exactly that, and the confirm button is
 * the destructive variant so nobody clicks it by momentum.
 */

const strings = adminStrings.certificates.confirm;

export interface RevokeDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    certificateId: number;
    code: string;
    /**
     * Who holds it and for what. Optional, because a caller with only the
     * code still gets a working ceremony, but a confirmation that names the
     * person and the course is what stops the wrong row being revoked from a
     * list where every code looks alike.
     */
    learner?: string | null;
    course?: string | null;
    onDone: (message: string) => void;
}

const reasonKeys = Object.keys(strings.reasons);

export function RevokeDialog({
    open,
    onOpenChange,
    certificateId,
    code,
    learner,
    course,
    onDone,
}: RevokeDialogProps) {
    const revoke = useRevokeCertificate();
    const [reason, setReason] = useState(reasonKeys[0] ?? 'issued_in_error');

    const close = (next: boolean) => {
        if (!next) {
            revoke.reset();
            setReason(reasonKeys[0] ?? 'issued_in_error');
        }
        onOpenChange(next);
    };

    const confirm = async () => {
        // The banner renders the refusal from the mutation's own state; an
        // uncaught rejection here would reach the host's error reporting as
        // something nobody threw.
        try {
            await revoke.mutateAsync({ certificateId, reason });
        } catch {
            return;
        }

        onDone(adminStrings.certificates.revoked(code));
        close(false);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogTitle>{strings.title}</DialogTitle>
                <DialogDescription>{strings.body(code)}</DialogDescription>
                {typeof learner === 'string' && typeof course === 'string' && (
                    <p className="mt-2 text-[12.5px] text-fg-2">
                        {credentialStrings.revokeContext(learner, course)}
                    </p>
                )}
                <SelectField
                    className="mt-3"
                    label={strings.reasonLabel}
                    hint={strings.reasonHint}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                >
                    {reasonKeys.map((key) => (
                        <option key={key} value={key}>
                            {strings.reasons[key]}
                        </option>
                    ))}
                </SelectField>
                {revoke.isError && <ErrorState className="mt-3" error={revoke.error} />}
                <DialogFooter>
                    <Button variant="outline" size="sm" onClick={() => close(false)}>
                        {strings.cancel}
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        disabled={revoke.isPending}
                        onClick={() => {
                            void confirm();
                        }}
                    >
                        {revoke.isPending ? strings.submitting : strings.submit}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
