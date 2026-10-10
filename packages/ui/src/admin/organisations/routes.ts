export const organisationTabs = [
    'overview',
    'people',
    'units',
    'learning',
    'seats',
    'settings',
    'activity',
] as const;
export type OrganisationTab = (typeof organisationTabs)[number];
export interface OrganisationLocation {
    id: number | null;
    tab: OrganisationTab;
    page: number;
    search: string;
    state: string;
    people: 'members' | 'invitations';
    mine: boolean;
    invalid: boolean;
    pool: number | null;
    layout: 'cards' | 'table';
}
export function organisationRoute(hash: string): OrganisationLocation {
    const url = new URL(hash || '/', 'https://route.invalid');
    const match =
        /^\/organisation\/([1-9]\d*)\/(overview|people|units|learning|seats|settings|activity)$/.exec(
            url.pathname,
        );
    const id = match === null ? null : Number(match[1]);
    const page = Number(url.searchParams.get('page') ?? 1);
    const pool = Number(url.searchParams.get('pool'));
    return {
        id: Number.isSafeInteger(id) ? id : null,
        tab: (match?.[2] ?? 'overview') as OrganisationTab,
        page: Number.isSafeInteger(page) && page >= 1 && page <= 1_000_000 ? page : 1,
        search: (url.searchParams.get('search') ?? '').slice(0, 200),
        state: url.searchParams.get('state') ?? '',
        people: url.searchParams.get('people') === 'invitations' ? 'invitations' : 'members',
        mine: url.searchParams.get('scope') === 'mine',
        layout: url.searchParams.get('layout') === 'table' ? 'table' : 'cards',
        pool: Number.isSafeInteger(pool) && pool > 0 ? pool : null,
        invalid: url.pathname !== '/' && (match === null || !Number.isSafeInteger(id)),
    };
}
export function organisationHash(
    location: OrganisationLocation,
    changes: Partial<OrganisationLocation> = {},
): string {
    const next = { ...location, ...changes };
    const path = next.id === null ? '/' : `/organisation/${next.id}/${next.tab}`;
    const query = new URLSearchParams();
    if (next.page > 1) query.set('page', String(next.page));
    if (next.search) query.set('search', next.search);
    if (next.state) query.set('state', next.state);
    if (next.people === 'invitations') query.set('people', 'invitations');
    if (next.layout === 'table') query.set('layout', 'table');
    if (next.mine) query.set('scope', 'mine');
    if (next.pool !== null) query.set('pool', String(next.pool));
    return query.size > 0 ? `${path}?${query}` : path;
}
