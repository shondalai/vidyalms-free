import { useState } from 'react';
import type { UserAccount } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import { Button, ErrorState, Spinner } from '@/shared/components';
import { useUserSearch } from '../api';
import { instructorStrings as strings } from '../strings';
import { Field, TextInput } from './field';

/*
 * Find one person, then act on them.
 *
 * Not an ARIA combobox: a labelled search field and a plain list of buttons
 * is operable by keyboard and by screen reader without owning a listbox's
 * focus model. The search waits for two characters because the contract
 * answers 422 below that, so the query stays idle rather than collecting
 * refusals.
 */
export interface LearnerPickerProps {
    label: string;
    hint?: string;
    error?: string;
    value: UserAccount | null;
    onChange: (user: UserAccount | null) => void;
    autoFocus?: boolean;
    /** Ids already chosen elsewhere in the form, hidden from the results. */
    excludeIds?: readonly number[];
}

export function LearnerPicker({
    label,
    hint,
    error,
    value,
    onChange,
    autoFocus = false,
    excludeIds = [],
}: LearnerPickerProps) {
    const [term, setTerm] = useState('');
    const search = useUserSearch(term);

    if (value !== null) {
        return (
            <div className="grid gap-1.5">
                <span className="font-display text-xs font-semibold text-fg">{label}</span>
                <div className="flex items-center gap-2 rounded border border-border bg-surface-2 px-3 py-2 text-[13px]">
                    <span className="min-w-0 flex-1 truncate">{value.name}</span>
                    <Button variant="ghost" size="sm" onClick={() => onChange(null)}>
                        {strings.enrolDialog.searchLabel}
                    </Button>
                </div>
            </div>
        );
    }

    const results = (search.data ?? []).filter((account) => !excludeIds.includes(account.id));
    return (
        <div className="grid gap-2">
            <Field label={label} hint={hint ?? strings.enrolDialog.searchHint} error={error}>
                {({ id, describedBy, invalid }) => (
                    <TextInput
                        id={id}
                        type="search"
                        autoComplete="off"
                        autoFocus={autoFocus}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                )}
            </Field>
            {search.isFetching && <Spinner label={strings.enrolDialog.searching} />}
            {search.isError && <ErrorState error={search.error} />}
            {search.data !== undefined && (
                <ul aria-label={strings.enrolDialog.results} className="grid gap-1.5">
                    {results.length === 0 ? (
                        <li className="text-[12.5px] text-fg-2">{strings.enrolDialog.noMatches}</li>
                    ) : (
                        results.map((account) => (
                            <li key={account.id}>
                                <button
                                    type="button"
                                    onClick={() => onChange(account)}
                                    className={cn(
                                        'flex w-full cursor-pointer items-center justify-between gap-3 rounded border border-border bg-surface px-3 py-2 text-left text-[12.5px]',
                                        'hover:bg-bg',
                                    )}
                                >
                                    <span className="min-w-0 truncate font-medium text-fg">{account.name}</span>
                                    <span className="min-w-0 truncate text-fg-2">{account.email}</span>
                                </button>
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}
