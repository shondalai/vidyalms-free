import { useState } from 'react';
import type { UserAccount } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import { useAnnounce } from '@/shared/a11y';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    SaveIndicator,
    Spinner,
} from '@/shared/components';
import { SaveError } from '../components/states';
import { useEnrolLearner, useUserSearch } from '../api';
import { instructorStrings as strings } from '../strings';
import { isApiDate } from '../lib/format';
import { useSaveTracker } from '../lib/save-state';
import { Field, TextInput } from '../components/field';

/*
 * The enrol dialog of artboard 4a: find the account, then grant. The search
 * is the contract's own (GET /admin/users, backed by the users port), which
 * needs two characters, so the field says so and the query stays idle below
 * that rather than collecting refusals.
 *
 * A future start is provisioning, not access: the entitlement exists and
 * the sweep enrols the learner when the start arrives, and the dialog says
 * which of the two happened rather than claiming an enrolment that is not
 * there yet.
 */

export function EnrolDialog({ courseId, onClose }: { courseId: number; onClose: () => void }) {
    const [term, setTerm] = useState('');
    const [selected, setSelected] = useState<UserAccount | null>(null);
    const [startsAt, setStartsAt] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [dateError, setDateError] = useState<string | null>(null);
    const [needsAccount, setNeedsAccount] = useState(false);
    const searchQuery = useUserSearch(term);
    const enrolLearner = useEnrolLearner();
    const save = useSaveTracker();
    const announce = useAnnounce();

    const submit = () => {
        if (selected === null) {
            setNeedsAccount(true);
            return;
        }
        const starts = startsAt.trim();
        const expires = expiresAt.trim();
        if ((starts !== '' && !isApiDate(starts)) || (expires !== '' && !isApiDate(expires))) {
            setDateError(strings.form.dateFormat);
            return;
        }
        setDateError(null);
        void save
            .run(async (idempotencyKey) => {
                const grant = await enrolLearner.mutateAsync({
                    courseId,
                    body: {
                        user_id: selected.id,
                        starts_at: starts === '' ? null : starts,
                        expires_at: expires === '' ? null : expires,
                    },
                    idempotencyKey,
                });
                announce(
                    grant.enrolment === null
                        ? strings.enrolDialog.provisioned(selected.name)
                        : strings.enrolDialog.done(selected.name),
                );
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
            <DialogContent className="max-w-lg">
                <DialogTitle>{strings.enrolDialog.title}</DialogTitle>
                <DialogDescription>{strings.enrolDialog.description}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    {selected === null ? (
                        <>
                            <Field
                                label={strings.enrolDialog.searchLabel}
                                hint={strings.enrolDialog.searchHint}
                                error={needsAccount ? strings.enrolDialog.chooseFirst : undefined}
                            >
                                {({ id, describedBy, invalid }) => (
                                    <TextInput
                                        id={id}
                                        type="search"
                                        autoFocus
                                        autoComplete="off"
                                        aria-describedby={describedBy}
                                        invalid={invalid}
                                        value={term}
                                        onChange={(event) => {
                                            setTerm(event.target.value);
                                            setNeedsAccount(false);
                                        }}
                                    />
                                )}
                            </Field>
                            {searchQuery.isFetching && <Spinner label={strings.enrolDialog.searching} />}
                            {searchQuery.isError && <ErrorState error={searchQuery.error} />}
                            {searchQuery.data !== undefined && (
                                <ul aria-label={strings.enrolDialog.results} className="grid gap-1.5">
                                    {searchQuery.data.length === 0 ? (
                                        <li className="text-[12.5px] text-fg-2">{strings.enrolDialog.noMatches}</li>
                                    ) : (
                                        searchQuery.data.map((account) => (
                                            <li key={account.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setSelected(account);
                                                        setNeedsAccount(false);
                                                    }}
                                                    className={cn(
                                                        'flex w-full cursor-pointer items-center justify-between gap-3 rounded border border-border bg-surface px-3 py-2 text-left text-[12.5px]',
                                                        'hover:bg-bg',
                                                    )}
                                                >
                                                    <span className="min-w-0 truncate font-medium text-fg">
                                                        {account.name}
                                                    </span>
                                                    <span className="min-w-0 truncate text-fg-2">{account.email}</span>
                                                </button>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            )}
                        </>
                    ) : (
                        <div className="flex flex-wrap items-center gap-2 rounded border border-border bg-bg px-3 py-2.5 text-[12.5px]">
                            <span className="font-medium text-fg">{strings.enrolDialog.selected(selected.name)}</span>
                            <span className="text-fg-2">{selected.email}</span>
                            <Button
                                className="ml-auto"
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setSelected(null);
                                    setTerm('');
                                }}
                            >
                                {strings.enrolDialog.clearSelection}
                            </Button>
                        </div>
                    )}

                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label={strings.enrolDialog.startsAt}
                            hint={strings.enrolDialog.startsAtHint}
                            error={dateError ?? undefined}
                        >
                            {({ id, describedBy, invalid }) => (
                                <TextInput
                                    id={id}
                                    placeholder="2026-10-01 09:00:00"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    value={startsAt}
                                    onChange={(event) => setStartsAt(event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label={strings.enrolDialog.expiresAt} hint={strings.enrolDialog.expiresAtHint}>
                            {({ id, describedBy }) => (
                                <TextInput
                                    id={id}
                                    placeholder="2027-10-01 09:00:00"
                                    aria-describedby={describedBy}
                                    value={expiresAt}
                                    onChange={(event) => setExpiresAt(event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}

                    <DialogFooter className="items-center">
                        <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                        <Button variant="outline" size="sm" onClick={onClose}>
                            {strings.form.cancel}
                        </Button>
                        <Button size="sm" onClick={submit} disabled={save.state === 'saving'}>
                            {strings.enrolDialog.submit}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}
