import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Badge, Button, ErrorState, Pagination, Skeleton } from '@/shared/components';
import { useNotificationCatalogue, useNotificationRules, useUpdateNotificationRule } from '@/shared/flow';
import { useAnnounce } from '@/shared/a11y';
import { useAdminConfig, workbenchUrl } from '../config';
import { SelectField } from '../components/field';
import { channelStrings as s } from './channel-strings';
import { notificationChannelLabel } from './channel-settings';

export function NotificationRulesPanel() {
    const config = useAdminConfig();
    const [page, setPage] = useState(1);
    const [state, setState] = useState<'' | 'enabled' | 'disabled'>('');
    const query = useNotificationRules({ page, per_page: 25, state: state || undefined });
    const catalogue = useNotificationCatalogue();
    const update = useUpdateNotificationRule();
    const announce = useAnnounce();
    const label = (key: string, kind: 'triggers' | 'audiences') =>
        catalogue.data?.[kind].find((item) => item.key === key)?.label ?? key.replaceAll('_', ' ');
    return <section className="vy-notification-settings">
        <p>{s.rulesHelp}</p><p className="vy-notification-muted">{s.pauseHelp}</p>
        <SelectField label={s.ruleStatus} value={state} className="max-w-[240px]"
            onChange={(event) => {
                setState(event.target.value as typeof state);
                setPage(1);
            }}>
            <option value="">{s.allRules}</option><option value="enabled">{s.enabled}</option><option value="disabled">{s.paused}</option>
        </SelectField>
        {(query.isPending || catalogue.isPending) && <Skeleton className="h-48" />}
        {(query.isError || catalogue.isError) && <ErrorState error={query.error ?? catalogue.error} onRetry={() => {
            void query.refetch();
            void catalogue.refetch();
        }} />}
        {query.data && <>
            {query.data.items.length === 0 ? <p>{s.noRules}</p> : <div className="vy-notification-rule-list">{query.data.items.map((rule) => {
                const title = label(rule.trigger_key, 'triggers');
                return <article key={rule.id} className="vy-notification-card">
                    <div className="vy-notification-card-heading">
                        <div className="vy-notification-card-title"><h3>{title}</h3>
                            <Badge variant={rule.inert ? 'warn' : rule.state === 'enabled' ? 'ok' : 'neutral'}>
                                {rule.inert ? s.setupNeeded : rule.state === 'enabled' ? s.enabled : s.paused}
                            </Badge>
                        </div>
                        <Button size="sm" variant="outline" disabled={update.isPending || query.isPlaceholderData || rule.inert && rule.state !== 'enabled'}
                            aria-label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: rule.state === 'enabled' ? s.pause : s.enableRule, p1: title })}
                            onClick={() => update.mutate({ ruleId: rule.id, body: { state: rule.state === 'enabled' ? 'disabled' : 'enabled' } }, {
                                onSuccess: () => announce(s.saved),
                            })}>{rule.state === 'enabled' ? s.pause : s.enableRule}</Button>
                    </div>
                    <p className="vy-notification-card-description">{label(rule.audience_key, 'audiences')} · {rule.channels.map(notificationChannelLabel).join(', ')}</p>
                    <div className="vy-notification-rule-footer">
                        <span>{rule.course_id ? uiText("vidyalms.ui.9d066be43ed42a5b", "{p0} #{p1}", { p0: s.course, p1: rule.course_id }) : s.siteWide}</span>
                        {rule.course_id && <a href={`${workbenchUrl(config, rule.course_id)}/vy/notifications`}>{s.editInCourse}</a>}
                    </div>
                </article>;
            })}</div>}
            <Pagination label={s.rules} page={page} perPage={25} total={query.data.total} onPageChange={setPage} />
        </>}
        {update.isError && <ErrorState error={update.error} />}
        {config.urls.screens.courses && <a href={config.urls.screens.courses} className="text-accent">{s.courses}</a>}
    </section>;
}
