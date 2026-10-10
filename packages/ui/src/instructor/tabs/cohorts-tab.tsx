import { translate as uiText } from '@/shared/i18n';
import { useMemo, useState } from 'react';
import { Badge, Button, Card, ErrorState, Skeleton } from '@/shared/components';
import { useArchiveCohort, useAvailabilityCatalogue, useCohort, useCohorts } from '@/shared/flow';
import { useAnnounce } from '@/shared/a11y';
import { useAuthoringCurriculum } from '../api';
import { flowStrings } from '../flow-strings';
import { conditionTargets } from '../lib/condition-targets';
import { PanelState } from '../components/states';
import { Tabs, TabPanel } from '../components/tabs';
import { CohortDialog } from './cohorts/cohort-dialog';
import { MembersPanel } from './cohorts/members-panel';
import { OverridesPanel } from './cohorts/overrides-panel';
import { SchedulePanel } from './cohorts/schedule-panel';
import type { Cohort } from '../lib/flow-types';

/*
 * Runs of one course.
 *
 * REGISTRY-DRIVEN AND NOT EDITION-DRIVEN. The tab asks the contract, and a
 * site with nothing registered gets 200 with an empty list and an
 * `available` flag rather than an error: a route that failed without a paid
 * package would be a gate by another name, and this screen would then be
 * checking an edition instead of a capability. What it renders in that case
 * says what a run would add and what the course does without one, because a
 * self-paced course is a complete product and not a degraded one.
 *
 * The design bundle carries no artboard for this screen, so the layout is
 * the workbench's own: the tab strip, the table shape and the panel states
 * every other tab already uses.
 */

const strings = flowStrings.cohorts;

const STATE_VARIANT: Record<string, 'ok' | 'warn' | 'neutral'> = {
    running: 'ok',
    open: 'ok',
    draft: 'neutral',
    closed: 'neutral',
    archived: 'neutral',
};

type DetailTab = 'schedule' | 'members' | 'overrides';

function CohortDetailView({
    cohortId,
    siblings,
    courseId,
    onClose,
}: {
    cohortId: number;
    siblings: readonly Cohort[];
    courseId: number;
    onClose: () => void;
}) {
    const detail = useCohort(cohortId);
    const catalogue = useAvailabilityCatalogue('content_gating');
    const curriculum = useAuthoringCurriculum(courseId);
    const archive = useArchiveCohort();
    const announce = useAnnounce();
    const [tab, setTab] = useState<DetailTab>('schedule');
    const [editing, setEditing] = useState(false);
    const targets = useMemo(() => conditionTargets(curriculum.data), [curriculum.data]);

    if (detail.isPending) {
        return <Skeleton className="h-40 w-full" />;
    }
    if (detail.isError) {
        return (
            <ErrorState
                error={detail.error}
                title={flowStrings.common.loadError}
                onRetry={() => void detail.refetch()}
            />
        );
    }

    const cohort = detail.data;
    return (
        <Card>
            <div className="flex flex-wrap items-start gap-2 border-b border-border p-4">
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-sm font-bold text-fg">{cohort.title}</h3>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">
                        {cohort.code} · {strings.states[cohort.state] ?? cohort.state} ·{' '}
                        {strings.pacings[cohort.pacing] ?? cohort.pacing} · {cohort.timezone}
                    </p>
                </div>
                <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                    {strings.title}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={archive.isPending || cohort.state === 'archived'}
                    title={strings.archiveConfirm}
                    onClick={() =>
                        archive.mutate(
                            { cohortId: cohort.id },
                            {
                                onSuccess: (result) => {
                                    announce(strings.archived(result.members_kept, result.tasks_cancelled));
                                    onClose();
                                },
                            },
                        )
                    }
                >
                    {strings.archive}
                </Button>
                <Button size="sm" variant="ghost" onClick={onClose}>
                    {flowStrings.common.close}
                </Button>
            </div>
            <p className="px-4 pt-2 text-[11.5px] text-fg-2">{strings.archiveConfirm}</p>
            <Tabs
                className="px-4 pt-2"
                label={strings.title}
                tabs={[
                    { id: 'schedule', label: strings.tabs.schedule },
                    { id: 'members', label: strings.tabs.members },
                    { id: 'overrides', label: strings.tabs.overrides },
                ]}
                current={tab}
                onSelect={setTab}
            />
            <div className="p-4">
                {tab === 'schedule' && (
                    <TabPanel id="schedule">
                        <SchedulePanel
                            cohortId={cohort.id}
                            targets={targets}
                            anchors={catalogue.data?.anchors ?? []}
                        />
                    </TabPanel>
                )}
                {tab === 'members' && (
                    <TabPanel id="members">
                        <MembersPanel cohort={cohort} siblings={siblings} />
                    </TabPanel>
                )}
                {tab === 'overrides' && (
                    <TabPanel id="overrides">
                        <OverridesPanel cohortId={cohort.id} targets={targets} />
                    </TabPanel>
                )}
            </div>
            {editing && (
                <CohortDialog
                    courseId={courseId}
                    cohort={cohort}
                    siteTimezone={cohort.timezone}
                    onClose={() => setEditing(false)}
                />
            )}
        </Card>
    );
}

export function CohortsTab({ courseId, narrow }: { courseId: number; narrow: boolean }) {
    const cohorts = useCohorts(courseId);
    const [selected, setSelected] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    if (cohorts.isPending) {
        return <Skeleton className="h-32 w-full" />;
    }
    if (cohorts.isError) {
        return (
            <ErrorState
                error={cohorts.error}
                title={flowStrings.common.loadError}
                onRetry={() => void cohorts.refetch()}
            />
        );
    }

    const page = cohorts.data;

    /* Nothing registers runs. A normal answer, and the screen says what is
       missing and what the course does without it. */
    if (page.available === false) {
        return (
            <PanelState
                glyph="◇"
                title={page.unavailable_reason ?? strings.unavailable}
                body={strings.unavailableHint}
                className="rounded border border-border bg-surface"
            />
        );
    }

    if (selected !== null) {
        return (
            <CohortDetailView
                cohortId={selected}
                siblings={page.items}
                courseId={courseId}
                onClose={() => setSelected(null)}
            />
        );
    }

    return (
        <Card>
            <div className="flex flex-wrap items-start gap-2 border-b border-border p-4">
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-sm font-bold text-fg">{strings.title}</h3>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{strings.intro}</p>
                </div>
                <Button size="sm" onClick={() => setCreating(true)}>
                    {strings.add}
                </Button>
            </div>
            {page.items.length === 0 ? (
                <p className="p-4 text-[12.5px] text-fg-2">{strings.none}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className={narrow ? 'w-full min-w-[520px] text-[12.5px]' : 'w-full text-[12.5px]'}>
                        <thead>
                            <tr className="bg-surface-2 text-left font-display text-[10px] font-semibold uppercase tracking-wide text-fg-2">
                                <th scope="col" className="px-4 py-1.5">{strings.columns.title}</th>
                                <th scope="col" className="px-4 py-1.5">{strings.columns.state}</th>
                                <th scope="col" className="px-4 py-1.5">{strings.columns.pacing}</th>
                                <th scope="col" className="px-4 py-1.5">{strings.columns.starts}</th>
                                <th scope="col" className="px-4 py-1.5">{strings.columns.members}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {page.items.map((cohort) => (
                                <tr key={cohort.id} className="border-t border-border/60">
                                    <td className="px-4 py-2">
                                        <button
                                            type="button"
                                            className="cursor-pointer text-left font-medium text-accent underline-offset-2 hover:underline"
                                            onClick={() => setSelected(cohort.id)}
                                        >
                                            {cohort.title}
                                        </button>
                                        <span className="block text-[11px] text-fg-2">{cohort.code}</span>
                                    </td>
                                    <td className="px-4 py-2">
                                        <Badge variant={STATE_VARIANT[cohort.state] ?? 'neutral'}>
                                            {strings.states[cohort.state] ?? cohort.state}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-2">{strings.pacings[cohort.pacing] ?? cohort.pacing}</td>
                                    <td className="px-4 py-2 tabular-nums">{cohort.starts_at ?? '-'}</td>
                                    <td className="px-4 py-2 tabular-nums">
                                        {cohort.member_count}
                                        {cohort.capacity !== null && uiText("vidyalms.ui.eec3e13813c12ac8", " / {p0}", { p0: cohort.capacity })}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {creating && (
                <CohortDialog courseId={courseId} cohort={null} siteTimezone="UTC" onClose={() => setCreating(false)} />
            )}
        </Card>
    );
}
