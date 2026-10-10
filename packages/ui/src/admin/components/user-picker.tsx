import { useState } from 'react';
import type { UserAccount } from '@vidyalms/api-sdk';
import { Button, Spinner } from '@/shared/components';
import { useUserSearch } from '../api';
import { useDebounced } from '../hooks';
import { adminStrings } from '../strings';
import { TextField } from './field';

/*
 * The person picker behind Grant access and the Learners screen. Not an
 * ARIA combobox: a labelled search field and a plain list of buttons is
 * operable by keyboard and by screen reader without owning a listbox's
 * focus model, and the result count is announced through a live region.
 *
 * The search waits for two characters because the contract answers 422
 * below that, and it is debounced so typing is not one request per key.
 */

export interface UserPickerProps {
    label: string;
    placeholder: string;
    hint?: string;
    error?: string;
    /** The chosen account, or null while the reader is still searching. */
    value: UserAccount | null;
    onChange: (user: UserAccount | null) => void;
}

export function UserPicker({ label, placeholder, hint, error, value, onChange }: UserPickerProps) {
    const [query, setQuery] = useState('');
    const debounced = useDebounced(query);
    const results = useUserSearch(debounced);

    if (value !== null) {
        return (
            <div className="grid gap-1.5">
                <span className="font-display text-xs font-semibold text-fg">{label}</span>
                <div className="flex items-center gap-2 rounded border border-border bg-surface-2 px-3 py-2 text-[13px]">
                    <span className="min-w-0 flex-1 truncate">
                        {adminStrings.grant.selected(value.name, value.email)}
                    </span>
                    <Button variant="ghost" size="sm" onClick={() => onChange(null)}>
                        {adminStrings.grant.change}
                    </Button>
                </div>
            </div>
        );
    }

    const items = results.data ?? [];
    return (
        <div className="grid gap-2">
            <TextField
                type="search"
                label={label}
                placeholder={placeholder}
                hint={hint}
                error={error}
                value={query}
                autoComplete="off"
                onChange={(event) => setQuery(event.target.value)}
            />
            <div aria-live="polite" className="grid gap-1">
                {results.isFetching && <Spinner label={adminStrings.grant.searching} />}
                {!results.isFetching && results.isSuccess && items.length === 0 && (
                    <p className="text-[11.5px] text-fg-2">{adminStrings.grant.noMatches}</p>
                )}
                {items.length > 0 && (
                    <ul aria-label={adminStrings.grant.results} className="grid gap-1">
                        {items.map((user) => (
                            <li key={user.id}>
                                <button
                                    type="button"
                                    onClick={() => onChange(user)}
                                    className="w-full cursor-pointer rounded border border-border bg-surface px-3 py-2 text-left text-[13px] hover:bg-surface-2"
                                >
                                    <span className="font-semibold text-fg">{user.name}</span>{' '}
                                    <span className="text-fg-2">{user.email}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
