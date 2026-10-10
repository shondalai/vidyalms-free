import { studioStrings as studio } from '../studio-strings';
import { useDebounced } from '@/admin/hooks';
import { useQuestionTypes } from '../api';
import { Pagination } from '@/shared/components';
import { useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    ErrorState,
} from '@/shared/components';
import { useBankEntries } from '../assessment-api';
import { assessmentStrings as strings } from '../assessment-strings';
import { deniedFor } from '../lib/problems';
import { ListSkeleton, PermissionDenied } from '../components/states';
import { Field, Select, TextInput } from '../components/field';
import type { BankEntry } from '../lib/assessment-types';

/*
 * "Add from bank": one entry, chosen from every scope this course reaches.
 *
 * The route takes course_id and answers the course scope, the category
 * scope, the quiz scopes of its quizzes and, for a caller holding manage,
 * the site scope. Asking for one scope at a time would make an author pick
 * the scope before they can search, which is exactly the operational
 * complaint about user-scoped banks that this model exists to avoid.
 *
 * A draft is listed and not selectable, with the reason beside it, because
 * an author who cannot find the question they just wrote will write it
 * again.
 */

export function BankPickerDialog({
    courseId,
    onPick,
    onClose,
}: {
    courseId: number;
    onPick: (entry: BankEntry) => void;
    onClose: () => void;
}) {
    const [q, setQ] = useState('');
    const [type, setType] = useState('');
    const [pageNumber, setPageNumber] = useState(1);
    const [status, setStatus] = useState<'ready' | 'draft'>('ready');
    const [tag, setTag] = useState('');
    const query = useDebounced(q.trim());
    const queryTag = useDebounced(tag.trim());
    const questionTypes = useQuestionTypes();
    const entriesQuery = useBankEntries({
        course_id: courseId,
        status,
        page: pageNumber,
        tag: queryTag || undefined,
        q: query || undefined,
        type: type === '' ? undefined : type,
        per_page: 20,
    });

    const page = entriesQuery.data;
    const items = page?.items ?? [];
    const types = questionTypes.data?.types ?? [];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogTitle>{strings.slots.addQuestion}</DialogTitle>
                <DialogDescription>{strings.bank.description}</DialogDescription>
                <div className="mt-3.5 flex flex-wrap gap-3">
                    <Field label={strings.bank.searchPlaceholder} className="min-w-[200px] flex-1">
                        {({ id }) => (
                            <TextInput
                                id={id}
                                autoFocus
                                value={q}
                                onChange={(event) => {
                                    setQ(event.target.value);
                                    setPageNumber(1);
                                }}
                                placeholder={strings.bank.searchPlaceholder}
                            />
                        )}
                    </Field>
                    <Field label={strings.bank.filterType} className="w-[180px]">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={type}
                                onChange={(event) => {
                                    setType(event.target.value);
                                    setPageNumber(1);
                                }}
                            >
                                <option value="">{strings.bank.anyType}</option>
                                {types.map((entry) => (
                                    <option key={entry.key} value={entry.key}>
                                        {entry.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <Field label={studio.questionTag}>
                        {({ id }) => (
                            <TextInput
                                id={id}
                                value={tag}
                                onChange={(event) => {
                                    setTag(event.target.value);
                                    setPageNumber(1);
                                }}
                            />
                        )}
                    </Field>
                    <Field label={studio.questionStatus}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value as 'ready' | 'draft');
                                    setPageNumber(1);
                                }}
                            >
                                <option value="ready">{strings.bank.status.ready}</option>
                                <option value="draft">{strings.bank.status.draft}</option>
                            </Select>
                        )}
                    </Field>
                </div>
                <div className="mt-3 max-h-[380px] overflow-y-auto rounded border border-border">
                    {entriesQuery.isPending ? (
                        <ListSkeleton label={strings.common.loading} rows={4} />
                    ) : entriesQuery.isError ? (
                        deniedFor(entriesQuery.error) ? (
                            <PermissionDenied />
                        ) : (
                            <ErrorState
                                error={entriesQuery.error}
                                title={strings.bank.errorTitle}
                                onRetry={() => void entriesQuery.refetch()}
                            />
                        )
                    ) : items.length === 0 ? (
                        <EmptyState
                            title={
                                q.trim() === '' ? strings.bank.emptyTitle : strings.bank.emptyFilteredTitle
                            }
                            description={
                                q.trim() === '' ? strings.bank.emptyBody : strings.bank.emptyFilteredBody
                            }
                        />
                    ) : (
                        <ul>
                            {items.map((entry) => {
                                const version = entry.latest_version;
                                const prompt =
                                    typeof version?.definition?.prompt === 'string'
                                        ? version.definition.prompt
                                        : (version?.type ?? String(entry.id));
                                return (
                                    <li
                                        key={entry.id}
                                        className="flex flex-wrap items-center gap-2.5 border-b border-border/60 px-3.5 py-2.5 text-[12.5px] last:border-b-0"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="line-clamp-1">{prompt}</span>
                                            <span className="text-[11px] text-fg-2">
                                                {[
                                                    version?.type ?? '',
                                                    version !== null && version !== undefined
                                                        ? strings.bank.version(version.version)
                                                        : '',
                                                    strings.bank.status[entry.status],
                                                ]
                                                    .filter((part) => part !== '')
                                                    .join(' · ')}
                                            </span>
                                        </span>
                                        {entry.status === 'draft' ? (
                                            <span className="text-[11px] text-warn-strong">
                                                {strings.bank.draftCannotBeUsed}
                                            </span>
                                        ) : (
                                            <Button size="sm" variant="outline" onClick={() => onPick(entry)}>
                                                {strings.common.add}
                                            </Button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="mt-3.5 flex flex-wrap items-center justify-between gap-3">
                    <span className="text-xs text-fg-2" role="status">
                        {studio.questionResults(page?.total ?? 0)}
                    </span>
                    <Pagination
                        page={pageNumber}
                        perPage={20}
                        total={page?.total ?? 0}
                        onPageChange={setPageNumber}
                        label={studio.questionPages}
                    />
                    <Button variant="outline" size="sm" onClick={onClose}>
                        {strings.common.close}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
