import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Check, Target, X } from 'lucide-react';
import { Button, ErrorState, Icon, Pagination, Spinner } from '@/shared/components';
import { useLearningObjectivePage } from '@/instructor/assessment-api';
import { useDebounced } from '../hooks';
import { BankInput } from './fields';

export interface ObjectiveChoice {
    id: number;
    label: string;
}

/** Searchable, paged objective selection without exposing database ids. */
export function ObjectivePicker({
    value,
    onChange,
    multiple = false,
    emptyLabel = 'Any objective',
}: {
    value: readonly ObjectiveChoice[];
    onChange: (value: ObjectiveChoice[]) => void;
    multiple?: boolean;
    emptyLabel?: string;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const search = useDebounced(q).trim();
    const objectives = useLearningObjectivePage(
        { q: search || undefined, page, per_page: 20 },
        open,
    );
    const selected = new Set(value.map((objective) => objective.id));
    const summary = value.length === 0
        ? emptyLabel
        : value.length === 1
          ? value[0]?.label ?? 'Selected objective'
          : `${value.length} objectives`;

    const choose = (choice: ObjectiveChoice) => {
        if (!multiple) {
            onChange([choice]);
            setOpen(false);
            setQ('');
            setPage(1);
            return;
        }
        onChange(selected.has(choice.id)
            ? value.filter((objective) => objective.id !== choice.id)
            : [...value, choice]);
    };

    if (!open) {
        return (
            <button
                type="button"
                className="inline-flex h-10 min-w-44 max-w-72 items-center justify-between gap-3 rounded border border-border bg-bg px-3 text-left text-[13px] text-fg"
                onClick={() => setOpen(true)}
            >
                <span className="flex min-w-0 items-center gap-2"><Icon icon={Target} size={14} /><span className="truncate">{summary}</span></span>
                <span aria-hidden="true">⌄</span>
            </button>
        );
    }

    return (
        <div className="relative z-30 grid min-w-[290px] gap-2 rounded-lg border border-border bg-surface p-3 shadow-card">
            <BankInput
                autoFocus
                type="search"
                aria-label={uiText("vidyalms.ui.cebf7a651880095b", "Search objectives")}
                placeholder={uiText("vidyalms.ui.cebf7a651880095b", "Search objectives")}
                value={q}
                onChange={(event) => { setQ(event.target.value); setPage(1); }}
            />
            {objectives.isFetching && <Spinner label={uiText("vidyalms.ui.7fe3e1a5f1050f6a", "Searching objectives")} />}
            {objectives.isError && (
                <ErrorState error={objectives.error} onRetry={() => void objectives.refetch()} />
            )}
            {!objectives.isError && <ul className="grid max-h-56 overflow-y-auto rounded border border-border">
                {(objectives.data?.items ?? []).map((objective) => {
                    const active = selected.has(objective.id);
                    return (
                        <li key={objective.id} className="border-b border-border last:border-b-0">
                            <button
                                type="button"
                                aria-pressed={active}
                                disabled={objectives.isFetching}
                                className="flex w-full cursor-pointer items-start gap-2 px-3 py-2 text-left text-xs text-fg hover:bg-surface-2 disabled:opacity-50"
                                onClick={() => choose({ id: objective.id, label: objective.label })}
                            >
                                <span className="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border border-border text-accent">{active && <Icon icon={Check} size={12} />}</span>
                                <span className="min-w-0"><span className="block font-medium">{objective.label}</span><span className="block truncate text-[10.5px] text-fg-2">{objective.identifier}</span></span>
                            </button>
                        </li>
                    );
                })}
                {objectives.isSuccess && !objectives.isFetching && objectives.data.items.length === 0 && (
                    <li className="p-3 text-xs text-fg-2">{uiText("vidyalms.ui.bb8b4cc5748dd18c", "No matching objectives.")}</li>
                )}
            </ul>}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Pagination
                    page={objectives.data?.page ?? page}
                    perPage={objectives.data?.per_page ?? 20}
                    total={objectives.data?.total ?? 0}
                    onPageChange={setPage}
                    label={uiText("vidyalms.ui.1c5dc485629c0388", "Objective pages")}
                />
                <div className="flex items-center gap-1">
                    {value.length > 0 && <Button type="button" variant="ghost" size="sm" onClick={() => onChange([])}><Icon icon={X} size={13} />{uiText("vidyalms.ui.83b12c2216efb4fd", "Clear")}</Button>}
                    <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)}>{uiText("vidyalms.ui.11a6767d5674c7e4", "Done")}</Button>
                </div>
            </div>
        </div>
    );
}
