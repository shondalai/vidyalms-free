import { useHashRoute } from '../hooks';

export interface EmailTemplateListState {
    search: string;
    filter: string;
    locale: string;
    channel: string;
    page: number;
    trigger?: string;
    view?: 'table';
}

export function emailTemplateListValues(hash: string): EmailTemplateListState {
    const query = new URLSearchParams(hash.replace(/^#/, '').split('?')[1] ?? '');
    const page = Number(query.get('page') ?? 1);
    const filter = query.get('filter') ?? 'all';
    return {
        search: (query.get('search') ?? '').slice(0, 200),
        filter: ['published', 'draft_changes', 'draft_only'].includes(filter) ? filter : 'all',
        locale: (query.get('locale') ?? '').slice(0, 20),
        channel: /^[a-z][a-z0-9_]{0,79}$/.test(query.get('channel') ?? '') ? query.get('channel')! : 'email',
        page: Number.isSafeInteger(page) && page >= 1 && page <= 1000000 ? page : 1,
        ...(query.get('trigger') ? { trigger: query.get('trigger')!.slice(0, 200) } : {}),
        ...(query.get('view') === 'table' ? { view: 'table' as const } : {}),
    };
}

export function emailTemplateListHash(values: EmailTemplateListState): string {
    const query = new URLSearchParams();
    if (values.search !== '') query.set('search', values.search);
    if (values.filter !== 'all') query.set('filter', values.filter);
    if (values.locale !== '') query.set('locale', values.locale);
    if (values.channel !== 'email') query.set('channel', values.channel);
    if (values.page !== 1) query.set('page', String(values.page));
    if (values.trigger) query.set('trigger', values.trigger);
    if (values.view) query.set('view', values.view);
    return query.size === 0 ? '/' : `/?${query.toString()}`;
}

/** The complete filter identity is bookmarkable; no client-only locale/channel state. */
export function useEmailTemplateListState() {
    const [hash, navigate] = useHashRoute();
    const values = emailTemplateListValues(hash);
    const commit = (patch: Partial<EmailTemplateListState>, replace = false) => {
        const next = emailTemplateListHash({ ...values, page: 1, ...patch });
        if (!replace) {
            navigate(next);
            return;
        }
        const url = new URL(window.location.href);
        url.hash = next;
        window.history.replaceState(window.history.state, '', url);
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    };
    return {
        ...values,
        setSearch: (search: string) => commit({ search: search.slice(0, 200) }, true),
        setFilter: (filter: string) => commit({ filter }),
        setLocale: (locale: string) => commit({ locale: locale.slice(0, 20) }, true),
        setChannel: (channel: string) => commit({ channel }),
        setPage: (page: number) => commit({ page }),
        setTrigger: (trigger: string) => commit({ trigger: trigger || undefined }),
        setView: (view: 'table' | undefined) => commit({ view, page: values.page }, true),
        clear: () => commit({ search: '', filter: 'all', locale: '', channel: 'email', trigger: undefined }),
    };
}
