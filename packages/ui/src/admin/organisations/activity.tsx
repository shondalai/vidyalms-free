import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Activity as ActivityIcon } from 'lucide-react';
import { Badge, Button, EmptyState, ErrorState, Icon, Pagination, Skeleton } from '@/shared/components';
import { useOrganisationEvents } from './api';
import { OrganisationAuditExport } from './governance';
import { OrgIntro } from './workspace';
import { pageRange } from '../lib';
import { displayValue } from '../learners/workspace';
import { organisationStrings as strings } from './strings';
import { orgWorkspaceStrings as s } from './workspace-strings';

export function Activity({ id, page, change }: { id: number; page: number; change: (page: number) => void }) {
    const query = useOrganisationEvents(id, page);
    const [expanded, setExpanded] = useState<number | null>(null);
    return (
        <div className="vy-org-section-stack">
            <OrgIntro
                title={strings.activity}
                description={s.activityIntro}
                action={<OrganisationAuditExport id={id} page={page} />}
            />
            <section className="vy-org-panel" aria-label={strings.activity}>
                {query.isError ? (
                    <ErrorState
                        error={query.error}
                        onRetry={() => {
                            void query.refetch();
                        }}
                    />
                ) : query.isPending ? (
                    <Skeleton className="h-48 w-full" />
                ) : !query.data?.items.length ? (
                    <EmptyState title={strings.activityEmpty} />
                ) : (
                    <ol className="vy-org-events">
                        {query.data.items.map((event) => (
                            <li key={event.id}>
                                <span className="vy-org-event-icon">
                                    <Icon icon={ActivityIcon} size={19} />
                                </span>
                                <div className="vy-org-event-body">
                                    <div>
                                        <h3>{displayValue(event.event_key)}</h3>
                                        <Badge>{strings.revisionValue(event.organisation_revision)}</Badge>
                                    </div>
                                    <p>
                                        {strings.userId(event.actor_id)}
                                        {event.subject_id !== null
                                            ? uiText("vidyalms.ui.8e4e0a1016b28b97", " · {p0}: {p1}", { p0: strings.subject, p1: strings.userId(event.subject_id) })
                                            : ''}
                                    </p>
                                    <time>{event.created_at} {uiText("vidyalms.ui.de92d152d78269b7", " UTC")}</time>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-expanded={expanded === event.id}
                                        onClick={() => setExpanded(expanded === event.id ? null : event.id)}
                                    >
                                        {expanded === event.id ? s.closeDetails : s.eventDetails}
                                    </Button>
                                    {expanded === event.id && (
                                        <pre className="vy-org-event-evidence" aria-label={s.eventEvidence}>
                                            {JSON.stringify(event, null, 2)}
                                        </pre>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
                {query.data && (
                    <footer className="vy-org-pagination">
                        <span>{pageRange(page, 20, query.data.total, query.data.items.length)}</span>
                        <Pagination
                            label={strings.activity}
                            page={page}
                            perPage={20}
                            total={query.data.total}
                            onPageChange={(page) => {
                                setExpanded(null);
                                change(page);
                            }}
                        />
                    </footer>
                )}
            </section>
        </div>
    );
}
