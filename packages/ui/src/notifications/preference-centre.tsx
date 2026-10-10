import { useEffect, useState } from 'react';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { Badge, Button, Card, ErrorState, Skeleton } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useMyNotificationPreferences, useSetMyNotificationPreferences } from './api';
import { notificationStrings as strings } from './strings';

/*
 * The preference centre.
 *
 * A (category, channel) grid, because CATEGORY and not template key is the
 * preference unit: a template key is the identity of one message, and a
 * screen with one row per message is a screen nobody reads. A package adding
 * a message inherits a category and needs no preference migration, and a
 * category no provider declares is absent here rather than shown empty.
 *
 * Two states are stated on the screen rather than discovered. A FORCED cell
 * is shown and locked, because the site has decided and pretending otherwise
 * would be a control that does nothing. And a TRANSACTIONAL category says
 * plainly that it bypasses these settings: a certificate and a password
 * reset are not marketing, a site may be obliged to send them, and a learner
 * who thinks they turned them off has been misled.
 *
 * Every control is a real labelled select in a real table, so the grid is
 * operable by keyboard and readable by row and column heading.
 */

const FREQUENCIES = ['immediate', 'daily', 'weekly', 'never'] as const;

export function PreferenceCentre() {
    const preferences = useMyNotificationPreferences();
    const save = useSetMyNotificationPreferences();
    const announce = useAnnounce();
    const [values, setValues] = useState<Record<string, string>>({});
    useNavigationGuard(save.isPending || (preferences.data?.rows ?? []).some((row) => {
        const value = values[`${row.category_key}|${row.channel_key}`];
        return value !== undefined && value !== row.frequency;
    }));

    const data = preferences.data ?? null;
    useEffect(() => {
        if (data === null) {
            return;
        }
        const seeded: Record<string, string> = {};
        for (const row of data.rows) {
            seeded[`${row.category_key}|${row.channel_key}`] = row.frequency;
        }
        setValues(seeded);
    }, [data]);

    if (preferences.isPending) {
        return (
            <Card className="p-4" aria-busy="true">
                <Skeleton className="h-4 w-40" />
                <Skeleton className="mt-3 h-32 w-full" />
            </Card>
        );
    }
    if (preferences.isError) {
        return (
            <Card className="p-4">
                <ErrorState
                    error={preferences.error}
                    title={strings.preferences.error}
                    onRetry={() => void preferences.refetch()}
                />
            </Card>
        );
    }

    const centre = preferences.data;
    const categories = centre.categories;
    const channels = centre.channels;

    if (centre.rows.length === 0) {
        return (
            <Card className="p-4">
                <p className="text-[13px] text-fg-2">{strings.preferences.empty}</p>
            </Card>
        );
    }

    const rowFor = (categoryKey: string, channelKey: string) =>
        centre.rows.find((row) => row.category_key === categoryKey && row.channel_key === channelKey) ?? null;

    return (
        <Card>
            <div className="border-b border-border p-4">
                <h2 className="font-display text-sm font-bold text-fg">{strings.preferences.title}</h2>
                <p className="mt-1 text-[12.5px] text-fg-2">{strings.preferences.intro}</p>
                <p className="mt-1 text-[12px] text-fg-2">{strings.preferences.digestNote}</p>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[520px] border-collapse text-[12.5px]">
                    <caption className="sr-only">{strings.preferences.title}</caption>
                    <thead>
                        <tr className="bg-surface-2 text-left font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2">
                            <th scope="col" className="px-4 py-2">
                                {strings.preferences.columnCategory}
                            </th>
                            {channels.map((channel) => (
                                <th key={channel.key} scope="col" className="px-4 py-2">
                                    {channel.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {categories.map((category) => (
                            <tr key={category.key} className="border-t border-border/60">
                                <th scope="row" className="px-4 py-2.5 text-left font-medium text-fg">
                                    {category.label}
                                    {category.class === 'transactional' && (
                                        <span className="mt-0.5 block">
                                            <Badge variant="neutral">{strings.preferences.transactional}</Badge>
                                            <span className="mt-0.5 block text-[11.5px] font-normal text-fg-2">
                                                {strings.preferences.transactionalHint}
                                            </span>
                                        </span>
                                    )}
                                </th>
                                {channels.map((channel) => {
                                    const row = rowFor(category.key, channel.key);
                                    if (row === null) {
                                        return (
                                            <td key={channel.key} className="px-4 py-2.5 text-fg-2">
                                                -
                                            </td>
                                        );
                                    }
                                    const key = `${category.key}|${channel.key}`;
                                    const allowed = row.available_frequencies ?? FREQUENCIES;
                                    const label = `${category.label}, ${channel.label}`;
                                    return (
                                        <td key={channel.key} className="px-4 py-2.5">
                                            {row.editable ? (
                                                <select
                                                    aria-label={label}
                                                    className="h-[34px] w-full max-w-[180px] cursor-pointer rounded border border-border bg-surface px-2 text-[12.5px] text-fg"
                                                    value={values[key] ?? row.frequency}
                                                    onChange={(event) =>
                                                        setValues((current) => ({
                                                            ...current,
                                                            [key]: event.target.value,
                                                        }))
                                                    }
                                                >
                                                    {allowed.map((frequency) => (
                                                        <option key={frequency} value={frequency}>
                                                            {strings.preferences.frequencies[frequency] ?? frequency}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : null}
                                            {row.editable && row.stored_frequency != null && (
                                                <span className="mt-0.5 block text-[11.5px] text-fg-2">
                                                    {strings.preferences.substituted(
                                                        strings.preferences.frequencies[row.stored_frequency]
                                                            ?? row.stored_frequency,
                                                    )}
                                                </span>
                                            )}
                                            {!row.editable && (
                                                <span>
                                                    <Badge variant="neutral">{strings.preferences.locked}</Badge>
                                                    <span className="mt-0.5 block text-[11.5px] text-fg-2">
                                                        {strings.preferences.lockedHint}
                                                    </span>
                                                </span>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-center gap-3 border-t border-border p-4">
                <Button
                    disabled={save.isPending}
                    onClick={() => {
                        const rows = centre.rows
                            .filter((row) => row.editable)
                            .map((row) => ({
                                category_key: row.category_key,
                                channel_key: row.channel_key,
                                frequency: (values[`${row.category_key}|${row.channel_key}`] ??
                                    row.frequency) as 'immediate',
                            }));
                        save.mutate({ rows }, { onSuccess: () => announce(strings.preferences.saved) });
                    }}
                >
                    {save.isPending ? strings.preferences.saving : strings.preferences.save}
                </Button>
                {save.isSuccess && (
                    <p role="status" className="text-[12.5px] text-fg-2">
                        {strings.preferences.saved}
                    </p>
                )}
                {save.isError && <ErrorState error={save.error} />}
            </div>
        </Card>
    );
}
