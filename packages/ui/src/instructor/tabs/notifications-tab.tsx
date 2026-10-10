import { useMemo, useState } from 'react';
import { Badge, Button, Card, ErrorState, Skeleton } from '@/shared/components';
import {
    useDeleteNotificationRule,
    useNotificationCatalogue,
    useNotificationRules,
    useUpdateNotificationRule,
} from '@/shared/flow';
import { useAuthoringCurriculum } from '../api';
import { useInstructorConfig } from '../config';
import { flowStrings } from '../flow-strings';
import { conditionTargets } from '../lib/condition-targets';
import { Tabs, TabPanel } from '../components/tabs';
import { NotificationRuleDialog } from './notifications/rule-dialog';
import { QueuePanel } from './notifications/queue-panel';
import type { NotificationRule } from '../lib/flow-types';

/*
 * Notification rules for one course, and the queue they fill.
 *
 * Two facts are on the screen rather than in a comment. A rule is created
 * SWITCHED OFF, because the research on light-touch nudges is that they
 * measure small, are context dependent and are sometimes negative; a site
 * turns one on deliberately. And a rule whose trigger, audience or channel
 * no longer resolves is reported as INERT rather than fired with a guessed
 * template, which is the published miss of that registry.
 *
 * The per-rule counters are real columns and are shown grouped by
 * suppression reason, because one suppression count cannot tell a site
 * whether a rule is throttled by the frequency cap, blocked by consent or
 * silenced by a competing rule.
 */

const strings = flowStrings.notifications;

type TabId = 'rules' | 'queue';

function Counters({ rule }: { rule: NotificationRule }) {
    const byReason = Object.entries(rule.counters.suppressed_by_reason ?? {});
    return (
        <span className="block text-[11.5px] text-fg-2">
            <span className="tabular-nums">
                {rule.counters.queued} / {rule.counters.sent} / {rule.counters.suppressed}
            </span>
            {byReason.length > 0 && (
                <span className="block">
                    {strings.counters.byReason}: {byReason.map(([reason, count]) => `${reason} ${count}`).join(', ')}
                </span>
            )}
        </span>
    );
}

function RulesPanel({ courseId, narrow }: { courseId: number; narrow: boolean }) {
    const config = useInstructorConfig();
    const catalogue = useNotificationCatalogue();
    const rules = useNotificationRules({ course_id: courseId });
    const update = useUpdateNotificationRule();
    const remove = useDeleteNotificationRule();
    const curriculum = useAuthoringCurriculum(courseId);
    const targets = useMemo(() => conditionTargets(curriculum.data), [curriculum.data]);
    const [editing, setEditing] = useState<NotificationRule | null>(null);
    const [creating, setCreating] = useState(false);

    if (catalogue.isPending || rules.isPending) {
        return <Skeleton className="h-32 w-full" />;
    }
    if (catalogue.isError || rules.isError) {
        return (
            <ErrorState
                error={catalogue.error ?? rules.error}
                title={flowStrings.common.loadError}
                onRetry={() => {
                    void catalogue.refetch();
                    void rules.refetch();
                }}
            />
        );
    }

    const page = rules.data;
    const triggerLabel = (key: string) =>
        catalogue.data.triggers.find((entry) => entry.key === key)?.label ?? key;
    const audienceLabel = (key: string) =>
        catalogue.data.audiences.find((entry) => entry.key === key)?.label ?? key;

    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-start gap-2">
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-xs font-semibold text-fg">{strings.title}</h3>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.disabledNote}</p>
                </div>
                <Button size="sm" onClick={() => setCreating(true)}>
                    {strings.add}
                </Button>
            </div>

            {page.items.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.none}</p>
            ) : (
                <div className="overflow-x-auto rounded border border-border">
                    <table className={narrow ? 'w-full min-w-[640px] text-[12.5px]' : 'w-full text-[12.5px]'}>
                        <thead>
                            <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                <th scope="col" className="px-3 py-1.5">{strings.columns.rule}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.trigger}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.audience}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.channels}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.counters}</th>
                                <th scope="col" className="px-3 py-1.5">{strings.columns.state}</th>
                                <th scope="col" className="px-3 py-1.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {page.items.map((rule) => (
                                <tr key={rule.id} className="border-t border-border/60">
                                    <td className="px-3 py-2">
                                        <button
                                            type="button"
                                            className="cursor-pointer text-left font-medium text-accent underline-offset-2 hover:underline"
                                            onClick={() => setEditing(rule)}
                                        >
                                            {rule.rule_key}
                                        </button>
                                        <span className="block text-[11px] text-fg-2">{rule.template_key}</span>
                                        {rule.inert && (
                                            <span className="mt-0.5 block text-[11px] text-warn-strong">
                                                {strings.inertHint((rule.missing_providers ?? []).join(', '))}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{triggerLabel(rule.trigger_key)}</td>
                                    <td className="px-3 py-2">{audienceLabel(rule.audience_key)}</td>
                                    <td className="px-3 py-2">{rule.channels.join(', ')}</td>
                                    <td className="px-3 py-2">
                                        <Counters rule={rule} />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Badge variant={rule.state === 'enabled' ? 'ok' : 'neutral'}>
                                            {strings.states[rule.state]}
                                        </Badge>
                                        {rule.inert && (
                                            <Badge className="ml-1" variant="warn">
                                                {strings.inert}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={update.isPending}
                                            onClick={() =>
                                                update.mutate({
                                                    ruleId: rule.id,
                                                    body: {
                                                        state: rule.state === 'enabled' ? 'disabled' : 'enabled',
                                                    },
                                                })
                                            }
                                        >
                                            {rule.state === 'enabled' ? strings.disable : strings.enable}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={remove.isPending}
                                            onClick={() => remove.mutate({ ruleId: rule.id })}
                                        >
                                            {strings.delete}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {update.isError && <ErrorState error={update.error} />}

            {(creating || editing !== null) && (
                <NotificationRuleDialog
                    catalogue={catalogue.data}
                    rule={editing}
                    courseId={courseId}
                    locale={config.locale}
                    targets={targets}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </section>
    );
}

export function NotificationsTab({ courseId, narrow }: { courseId: number; narrow: boolean }) {
    const [tab, setTab] = useState<TabId>('rules');
    return (
        <Card>
            <Tabs
                className="px-4 pt-3"
                label={strings.title}
                tabs={[
                    { id: 'rules', label: strings.title },
                    { id: 'queue', label: flowStrings.notifications.queue.title },
                ]}
                current={tab}
                onSelect={setTab}
            />
            <div className="p-4">
                {tab === 'rules' && (
                    <TabPanel id="rules">
                        <RulesPanel courseId={courseId} narrow={narrow} />
                    </TabPanel>
                )}
                {tab === 'queue' && (
                    <TabPanel id="queue">
                        <QueuePanel />
                    </TabPanel>
                )}
            </div>
        </Card>
    );
}
