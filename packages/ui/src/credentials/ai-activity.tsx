import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ProblemError } from '@vidyalms/api-sdk';
import { Badge, Card, EmptyState, ErrorState, Pagination, Skeleton } from '@/shared/components';
import { useApiClient } from '@/shared/context';
import { aiActivityStrings as strings } from './ai-activity-strings';

/*
 * The learner's right to know, as a screen rather than a paragraph.
 *
 * It answers for the signed-in person and for nobody else, and it lists every
 * run that used their data rather than only the ones they started. That is why
 * the server reads the run subject rows: one nullable column on the run cannot
 * record the subjects of a cohort narrative or a bulk feedback job, which are
 * exactly the runs that touch the most people.
 *
 * Three things it deliberately does not show. It never shows a draft: a draft is
 * not reachable by any learner read path in the contract, and this page is the
 * one place somebody might expect it to be. It never shows a score, a confidence
 * or a risk value, because no such field exists in the design and a screen that
 * invented one would be the first place it appeared. And it never turns a null
 * manifest into an empty list: "nothing was sent" and "we did not record what
 * was sent" are different answers, and only one of them is honest here.
 */

const PER_PAGE = 10;

function useMyAiActivity(page: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: ['vidyalms', 'credentials', 'ai-activity', page],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/me/ai-activity', {
                params: { query: { page, per_page: PER_PAGE } },
                signal,
            });
            if (data === undefined) {
                throw new Error(uiText("vidyalms.ui.79715b15d77cc409", "The server answered OK with an empty body where content was expected."));
            }
            return data;
        },
    });
}

export function AiActivityPage() {
    const [page, setPage] = useState(1);
    const activity = useMyAiActivity(page);

    if (activity.isError) {
        if (activity.error instanceof ProblemError && activity.error.status === 401) {
            return (
                <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                    <h1 className="font-display text-lg font-semibold text-fg">{strings.signedOut.title}</h1>
                    <p className="mt-1.5 max-w-[70ch] text-sm text-fg-2">{strings.signedOut.body}</p>
                </section>
            );
        }
        return <ErrorState error={activity.error} title={strings.error} onRetry={() => void activity.refetch()} />;
    }

    if (activity.data === undefined) {
        return <Skeleton className="h-64 w-full" aria-label={strings.loading} />;
    }

    const report = activity.data;

    return (
        <div className="grid gap-4">
            <header className="grid gap-1">
                <h1 className="font-display text-xl font-semibold text-fg">{strings.title}</h1>
                <p className="max-w-[70ch] text-sm text-fg-2">{strings.intro}</p>
                <p className="text-xs text-fg-2">{strings.asOf(report.as_of)}</p>
            </header>

            {report.items.length === 0 ? (
                <EmptyState title={strings.empty.title} description={strings.empty.body} />
            ) : (
                <ul className="grid gap-3">
                    {report.items.map((entry) => (
                        <li key={entry.run_id}>
                            <Card className="grid gap-2 p-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="font-display text-base font-semibold text-fg">
                                        {entry.task_label ?? strings.entry.unnamedTask}
                                    </h2>
                                    <Badge variant={entry.my_role === 'subject' ? 'accent' : 'neutral'}>
                                        {entry.my_role === 'subject'
                                            ? strings.entry.roleSubject
                                            : strings.entry.roleContributor}
                                    </Badge>
                                </div>

                                <dl className="grid gap-x-4 gap-y-1 text-xs text-fg-2 sm:grid-cols-2">
                                    <div className="flex gap-1.5">
                                        <dt className="font-semibold">{strings.entry.when}</dt>
                                        <dd>{entry.occurred_at}</dd>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <dt className="font-semibold">{strings.entry.invokedBy}</dt>
                                        <dd>
                                            {entry.actor_kind === 'automation'
                                                ? strings.entry.invokedByAutomation
                                                : (entry.invoked_by_name ?? strings.entry.invokedByUnknown)}
                                        </dd>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <dt className="font-semibold">{strings.entry.model}</dt>
                                        <dd>{entry.model ?? strings.entry.noModel}</dd>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <dt className="font-semibold">{strings.entry.state}</dt>
                                        <dd>{entry.state}</dd>
                                    </div>
                                    <div className="flex gap-1.5">
                                        <dt className="font-semibold">{strings.entry.outcome}</dt>
                                        <dd>{strings.entry.outcomes[entry.outcome]}</dd>
                                    </div>
                                </dl>

                                {entry.was_preview ? (
                                    <p className="text-xs font-semibold text-fg">{strings.entry.preview}</p>
                                ) : null}

                                <section className="grid gap-1">
                                    <h3 className="text-xs font-semibold text-fg">{strings.entry.fieldsTitle}</h3>
                                    {entry.fields_sent === null || entry.fields_sent === undefined ? (
                                        <p className="text-xs text-fg-2">{strings.entry.fieldsEmpty}</p>
                                    ) : entry.fields_sent.length === 0 ? (
                                        <p className="text-xs text-fg-2">{strings.entry.fieldsNone}</p>
                                    ) : (
                                        <ul className="grid gap-0.5 text-xs text-fg-2">
                                            {entry.fields_sent.map((label) => (
                                                <li key={label} className="font-mono text-fg">
                                                    {label}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>

                                <p className="text-xs text-fg-2">
                                    {strings.entry.retainedUntil(entry.retained_until)}
                                </p>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}

            <Pagination
                page={page}
                perPage={report.per_page}
                total={report.total}
                onPageChange={setPage}
                label={strings.pagination.label}
            />
        </div>
    );
}
