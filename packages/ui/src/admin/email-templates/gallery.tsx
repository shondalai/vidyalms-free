import { useQueryClient } from '@tanstack/react-query';
import { FileText, Mail, MailCheck, PencilLine, LayoutGrid, List, RefreshCw } from 'lucide-react';
import { Badge, Button, DataTable, EmptyState, ErrorState, Pagination, Skeleton } from '@/shared/components';
import { useNotificationCatalogue } from '@/shared/flow';
import { useAdminConfig } from '../config';
import { useDebounced } from '../hooks';
import { PageHeader } from '../components/page-header';
import { FilterChips } from '../components/filter-chips';
import { useEmailTemplateListState } from './list-state';
import {
    notificationTemplateKeys,
    useNotificationTemplateCount,
    useNotificationTemplatePage,
    type NotificationTemplate,
    type NotificationTemplateListParams,
    type NotificationTemplateStatus,
} from './api';
import { emailTemplateHash } from './routes';
import { emailTemplateStrings as s } from './strings';
import { languageName, TemplateCard, templateLabel, templateStatus } from './workspace';

const PER_PAGE = 18;
export function EmailTemplateGallery({ navigate }: { navigate: (hash: string) => void }) {
    const config = useAdminConfig();
    const list = useEmailTemplateListState();
    const cache = useQueryClient();
    const catalogue = useNotificationCatalogue();
    const search = useDebounced(list.search).trim();
    const locale = useDebounced(list.locale).trim();
    const params: NotificationTemplateListParams = {
        search: search || undefined,
        status: list.filter === 'all' ? undefined : (list.filter as NotificationTemplateStatus),
        locale: locale || undefined,
        channel_key: list.channel === 'all' ? undefined : list.channel,
        trigger_key: list.trigger,
        page: list.page,
        per_page: PER_PAGE,
    };
    const templates = useNotificationTemplatePage(params);
    const scope = { locale: params.locale, channel_key: params.channel_key };
    const all = useNotificationTemplateCount(undefined, scope);
    const published = useNotificationTemplateCount('published', scope);
    const changed = useNotificationTemplateCount('draft_changes', scope);
    const draftOnly = useNotificationTemplateCount('draft_only', scope);
    const items = templates.data?.items ?? [];
    const stale = templates.isPlaceholderData || search !== list.search.trim() || locale !== list.locale.trim();
    const filtered = Boolean(
        list.search || list.locale || list.trigger || list.filter !== 'all' || list.channel !== 'email',
    );
    const open = (item: NotificationTemplate) =>
        navigate(
            emailTemplateHash({ templateKey: item.template_key, locale: item.locale, channelKey: item.channel_key }),
        );
    const channels = catalogue.data?.channels ?? [];
    return (
        <div className="vy-email-library">
            <PageHeader
                title={
                    <>
                        {s.screen}
                        <span className="vy-email-dot">.</span>
                    </>
                }
                description={s.workspace.intro}
                actions={
                    <Button
                        variant="outline"
                        onClick={() => void cache.invalidateQueries({ queryKey: notificationTemplateKeys.root })}
                    >
                        <RefreshCw size={15} />
                        {s.workspace.refresh}
                    </Button>
                }
            />
            <dl className="vy-email-stats" aria-label={s.gallery.summary}>
                {[
                    { label: s.gallery.total, query: all, Icon: Mail },
                    { label: s.gallery.published, query: published, Icon: MailCheck },
                    { label: s.gallery.draftChanges, query: changed, Icon: PencilLine },
                    { label: s.gallery.draftOnly, query: draftOnly, Icon: FileText },
                ].map(({ label, query, Icon }) => (
                    <div key={label}>
                        <Icon size={21} />
                        <div>
                            <dd>{query.data?.toLocaleString(config.locale) ?? '—'}</dd>
                            <dt>{label}</dt>
                        </div>
                    </div>
                ))}
            </dl>
            <div className="vy-email-library-tools">
                <label className="vy-email-field vy-email-search">
                    <span>{s.gallery.search}</span>
                    <input
                        type="search"
                        value={list.search}
                        placeholder={s.gallery.searchPlaceholder}
                        onChange={(e) => list.setSearch(e.target.value)}
                    />
                </label>
                <label className="vy-email-field">
                    <span>{s.workspace.trigger}</span>
                    <select value={list.trigger ?? ''} onChange={(e) => list.setTrigger(e.target.value)}>
                        <option value="">{s.workspace.allNotifications}</option>
                        {list.trigger && !catalogue.data?.triggers.some((item) => item.key === list.trigger) && (
                            <option value={list.trigger}>{templateLabel(list.trigger)}</option>
                        )}
                        {catalogue.data?.triggers.map((item) => (
                            <option key={item.key} value={item.key}>
                                {templateLabel(item.label)}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="vy-email-field">
                    <span>{s.gallery.locale}</span>
                    <input
                        value={list.locale}
                        onChange={(e) => list.setLocale(e.target.value)}
                        placeholder={s.gallery.allLocales}
                        maxLength={20}
                    />
                </label>
                <label className="vy-email-field">
                    <span>{s.gallery.channel}</span>
                    <select value={list.channel} onChange={(e) => list.setChannel(e.target.value)}>
                        <option value="all">{s.gallery.allChannels}</option>
                        <option value="email">{s.gallery.emailChannel}</option>
                        {list.channel !== 'all' &&
                            list.channel !== 'email' &&
                            !channels.some((item) => item.key === list.channel) && (
                                <option value={list.channel}>{templateLabel(list.channel)}</option>
                            )}
                        {channels
                            .filter((item) => item.key !== 'email')
                            .map((item) => (
                                <option key={item.key} value={item.key}>
                                    {templateLabel(item.label)}
                                </option>
                            ))}
                    </select>
                </label>
            </div>
            {catalogue.isError && (
                <ErrorState
                    title={s.workspace.catalogueError}
                    error={catalogue.error}
                    onRetry={() => void catalogue.refetch()}
                />
            )}
            <div className="vy-email-toolbar">
                <FilterChips
                    label={s.gallery.filters}
                    value={list.filter}
                    onChange={list.setFilter}
                    chips={[
                        { value: 'all', label: s.gallery.allStatuses, count: all.data },
                        { value: 'published', label: s.gallery.published, count: published.data },
                        { value: 'draft_changes', label: s.gallery.draftChanges, count: changed.data },
                        { value: 'draft_only', label: s.gallery.draftOnly, count: draftOnly.data },
                    ]}
                />
                {filtered && (
                    <Button variant="ghost" size="sm" onClick={list.clear}>
                        {s.workspace.clear}
                    </Button>
                )}
                <div className="vy-email-view">
                    {templates.isSuccess && (
                        <p className="vy-email-muted">{s.gallery.totalCount(templates.data.total, config.locale)}</p>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label={s.workspace.grid}
                        aria-pressed={!list.view}
                        onClick={() => list.setView(undefined)}
                    >
                        <LayoutGrid size={16} />
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label={s.workspace.table}
                        aria-pressed={list.view === 'table'}
                        onClick={() => list.setView('table')}
                    >
                        <List size={16} />
                    </Button>
                </div>
            </div>
            <div role="region" aria-label={s.gallery.tableLabel} aria-busy={templates.isFetching}>
                {templates.isError ? (
                    <ErrorState error={templates.error} onRetry={() => void templates.refetch()} />
                ) : templates.isPending ? (
                    <div className="vy-email-grid">
                        {Array.from({ length: 6 }, (_, index) => (
                            <Skeleton key={index} className="h-80 w-full" />
                        ))}
                    </div>
                ) : !items.length ? (
                    <EmptyState
                        title={filtered ? s.gallery.empty : s.workspace.empty}
                        description={s.workspace.emptyHint}
                        action={
                            filtered ? (
                                <Button variant="outline" onClick={list.clear}>
                                    {s.workspace.clear}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : list.view === 'table' ? (
                    <DataTable<NotificationTemplate>
                        caption={s.gallery.tableLabel}
                        rows={items}
                        rowKey={(item) => `${item.template_key}:${item.locale}:${item.channel_key}`}
                        columns={[
                            {
                                key: 'name',
                                header: s.screen,
                                cell: (item) => (
                                    <button disabled={stale} className="vy-email-text-link" onClick={() => open(item)}>
                                        {templateLabel(item.label || item.template_key)}
                                    </button>
                                ),
                            },
                            {
                                key: 'status',
                                header: s.workspace.status,
                                cell: (item) => (
                                    <Badge variant={item.status === 'published' ? 'ok' : 'neutral'}>
                                        {templateStatus(item.status)}
                                    </Badge>
                                ),
                            },
                            { key: 'locale', header: s.gallery.locale, cell: (item) => languageName(item.locale) },
                            {
                                key: 'channel',
                                header: s.gallery.channel,
                                cell: (item) => templateLabel(item.channel_key),
                            },
                            {
                                key: 'delivery',
                                header: s.delivery.label,
                                cell: (item) =>
                                    item.delivery_ready === true
                                        ? s.delivery.compactReady
                                        : item.delivery_ready === false
                                          ? s.delivery.setupNeeded
                                          : s.delivery.compactUnknown,
                            },
                        ]}
                    />
                ) : (
                    <div className="vy-email-grid">
                        {items.map((item) => (
                            <TemplateCard
                                key={`${item.template_key}:${item.locale}:${item.channel_key}`}
                                item={item}
                                disabled={stale}
                                onOpen={() => open(item)}
                            />
                        ))}
                    </div>
                )}
            </div>
            {templates.data && (
                <Pagination
                    label={s.gallery.tableLabel}
                    page={list.page}
                    perPage={PER_PAGE}
                    total={templates.data.total}
                    onPageChange={list.setPage}
                />
            )}
            <p className="vy-email-library-hint">{s.workspace.previewHint}</p>
        </div>
    );
}
