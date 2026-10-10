import { ArrowRight, BookOpen, Check, Circle, GraduationCap, Ticket, Users } from 'lucide-react';
import type { Organisation, OrganisationRolePage } from '@vidyalms/api-sdk';
import { Badge, Button, EmptyState, ErrorState, Icon, ProgressBar, Skeleton } from '@/shared/components';
import { useOrganisationPools, useOrganisationSummary } from './learning-api';
import { useOrganisationEvents } from './api';
import { OrgPanel, OrgStats, orgDate, orgType } from './workspace';
import { organisationStrings as strings } from './strings';
import { organisationLearningStrings as learning } from './learning-strings';
import { orgWorkspaceStrings as s } from './workspace-strings';
import { displayValue } from '../learners/workspace';
import type { OrganisationTab } from './routes';

export function Overview({
    organisation: org,
    roles,
    select,
}: {
    organisation: Organisation;
    roles: OrganisationRolePage;
    select: (tab: OrganisationTab) => void;
}) {
    const query = useOrganisationSummary(org.id);
    const summary = query.data;
    const can = (action: string) => roles.access.actions.includes(action);
    const unitScoped = roles.access.unit_id !== null;
    const steps = [
        {
            title: s.setupPeople,
            hint: s.setupPeopleHint,
            count: summary?.active_members,
            tab: 'people' as const,
            action: 'people',
        },
        {
            title: s.setupCourses,
            hint: s.setupCoursesHint,
            count: summary?.released_courses,
            tab: 'learning' as const,
            action: 'learning',
        },
        {
            title: s.setupAccess,
            hint: s.setupAccessHint,
            count: summary?.enrolments,
            tab: 'learning' as const,
            action: 'learning',
        },
    ].filter((step) => can(step.action));
    return (
        <div className="vy-org-section-stack">
            {query.isError && (
                <ErrorState
                    error={query.error}
                    onRetry={() => {
                        void query.refetch();
                    }}
                />
            )}
            <OrgStats
                items={[
                    {
                        label: s.activeMembers,
                        value: summary?.active_members?.toLocaleString() ?? '—',
                        icon: Users,
                        detail: unitScoped ? s.unitScope : undefined,
                    },
                    {
                        label: s.released,
                        value: summary?.released_courses?.toLocaleString() ?? '—',
                        icon: BookOpen,
                    },
                    {
                        label: s.enrolments,
                        value: summary?.enrolments?.toLocaleString() ?? '—',
                        icon: GraduationCap,
                        detail: unitScoped ? s.unitScope : undefined,
                    },
                    {
                        label: s.pools,
                        value: summary?.seat_pools?.toLocaleString() ?? '—',
                        icon: Ticket,
                        detail: summary ? strings.pendingSeats(summary.pending_seats) : undefined,
                    },
                ]}
            />
            <div className="vy-org-overview-grid">
                <div className="vy-org-section-stack">
                    <OrgPanel title={s.overview} description={s.contactHint}>
                        <dl className="vy-org-facts">
                            {[
                                [s.type, orgType(org.type, org.type_unresolved)],
                                [s.contact, org.contact_email || s.unset],
                                [s.address, org.slug],
                                [s.timezone, org.timezone || s.inherited],
                                [s.locale, org.locale || s.inherited],
                                [s.created, orgDate(org.created_at)],
                                [s.updated, orgDate(org.updated_at)],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <dt>{label}</dt>
                                    <dd>{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </OrgPanel>
                    {steps.length > 0 && (
                        <OrgPanel title={s.setup} description={s.setupIntro}>
                            <ol className="vy-org-setup">
                                {steps.map((step, index) => (
                                    <li key={step.title}>
                                        <span
                                            className={
                                                step.count !== undefined && step.count > 0
                                                    ? 'vy-org-step-done'
                                                    : ''
                                            }
                                        >
                                            <Icon
                                                icon={
                                                    step.count !== undefined && step.count > 0
                                                        ? Check
                                                        : Circle
                                                }
                                                size={17}
                                            />
                                        </span>
                                        <div>
                                            <h3>{step.title}</h3>
                                            <p>{step.hint}</p>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={step.title}
                                            onClick={() => select(step.tab)}
                                        >
                                            <Icon icon={ArrowRight} size={17} />
                                        </Button>
                                        <small aria-hidden="true">0{index + 1}</small>
                                    </li>
                                ))}
                            </ol>
                        </OrgPanel>
                    )}
                </div>
                <div className="vy-org-section-stack">
                    {can('seats') && <SeatPreview id={org.id} open={() => select('seats')} />}
                    {!unitScoped && can('events') && (
                        <RecentActivity id={org.id} open={() => select('activity')} />
                    )}
                    {can('people') && (
                        <div className="vy-org-callout">
                            <Icon icon={Users} size={23} />
                            <div>
                                <h3>{s.viewMembers}</h3>
                                <p>{s.peopleIntro}</p>
                            </div>
                            <Button variant="outline" onClick={() => select('people')}>
                                {strings.quickPeople}
                                <Icon icon={ArrowRight} size={15} />
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function SeatPreview({ id, open }: { id: number; open: () => void }) {
    const query = useOrganisationPools(id, 1);
    return (
        <OrgPanel title={s.capacity} description={s.capacityIntro} action={<Icon icon={Ticket} size={20} />}>
            {query.isError ? (
                <ErrorState
                    error={query.error}
                    onRetry={() => {
                        void query.refetch();
                    }}
                />
            ) : query.isPending ? (
                <Skeleton className="h-36 w-full" />
            ) : (
                <>
                    {!query.data?.items.length ? (
                        <EmptyState title={s.noPools} description={s.noPoolsHint} />
                    ) : (
                        <div className="vy-org-pool-preview">
                            {query.data.items.slice(0, 3).map((pool) => (
                                <div key={pool.id}>
                                    <div>
                                        <strong>{pool.name}</strong>
                                        <Badge variant={pool.state === 'active' ? 'accent' : 'neutral'}>
                                            {learning.stateLabels[pool.state]}
                                        </Badge>
                                    </div>
                                    <p>{pool.course_title ?? learning.courseId(pool.course_id)}</p>
                                    {pool.balance && (
                                        <>
                                            <ProgressBar
                                                value={
                                                    pool.balance.capacity > 0
                                                        ? (pool.balance.held / pool.balance.capacity) * 100
                                                        : 0
                                                }
                                                label={pool.name}
                                                detail={s.held(pool.balance.held)}
                                            />
                                            <p>
                                                {s.capacityAvailable(
                                                    pool.balance.available,
                                                    pool.balance.capacity,
                                                )}
                                            </p>
                                            {!pool.balance.reconciled && (
                                                <p role="status">{learning.mismatch}</p>
                                            )}
                                        </>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                    {(query.data?.total ?? 0) > 3 && <p className="vy-org-note">{s.poolPreview}</p>}
                </>
            )}
            <Button variant="ghost" className="justify-self-start" onClick={open}>
                {s.viewAllPools}
                <Icon icon={ArrowRight} size={16} />
            </Button>
        </OrgPanel>
    );
}

function RecentActivity({ id, open }: { id: number; open: () => void }) {
    const query = useOrganisationEvents(id, 1);
    return (
        <OrgPanel
            title={s.recent}
            action={
                <Button variant="ghost" size="sm" onClick={open}>
                    {s.allActivity}
                    <Icon icon={ArrowRight} size={14} />
                </Button>
            }
        >
            {query.isError ? (
                <ErrorState
                    error={query.error}
                    onRetry={() => {
                        void query.refetch();
                    }}
                />
            ) : query.isPending ? (
                <Skeleton className="h-28 w-full" />
            ) : !query.data?.items.length ? (
                <EmptyState title={s.noActivity} />
            ) : (
                <ol className="vy-org-timeline">
                    {query.data.items.slice(0, 4).map((event) => (
                        <li key={event.id}>
                            <span />
                            <div>
                                <strong>{displayValue(event.event_key)}</strong>
                                <p>
                                    {strings.userId(event.actor_id)} · {orgDate(event.created_at)}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </OrgPanel>
    );
}
