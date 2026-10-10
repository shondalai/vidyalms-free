export type CertificateRoute =
    | { kind: 'certificates' | 'templates' | 'credits' | 'badges' | 'not-found' }
    | { kind: 'editor'; templateId: number }
    | { kind: 'record'; certificateId: number };

export function parseCertificateRoute(hash: string): CertificateRoute {
    const normalized = hash.replace(/^#/, '') || '/';
    const path = normalized.split('?')[0];
    if (path === '/') return { kind: 'certificates' };
    const record = /^\/certificate\/([1-9]\d*)$/.exec(path ?? '');
    if (record !== null && Number.isSafeInteger(Number(record[1])))
        return { kind: 'record', certificateId: Number(record[1]) };
    if (path === '/templates') return { kind: 'templates' };
    if (path === '/credits' || path === '/badges') return { kind: path.slice(1) as 'credits' | 'badges' };
    const match = /^\/templates\/([1-9]\d*)\/edit$/.exec(normalized);
    const id = Number(match?.[1]);
    return match !== null && Number.isSafeInteger(id)
        ? { kind: 'editor', templateId: id }
        : { kind: 'not-found' };
}

export interface CertificateLibraryState {
    filter: 'all' | 'draft' | 'published' | 'archived';
    search: string;
    category: string;
    page: number;
}

export function certificateLibraryState(hash: string): CertificateLibraryState {
    const query = new URLSearchParams(hash.split('?')[1] ?? '');
    const filter = query.get('filter');
    const page = Number(query.get('page') ?? 1);
    return {
        filter: filter === 'draft' || filter === 'published' || filter === 'archived' ? filter : 'all',
        search: (query.get('search') ?? '').slice(0, 200),
        category: (query.get('category') ?? '').slice(0, 80),
        page: Number.isSafeInteger(page) && page > 0 && page <= 1000000 ? page : 1,
    };
}

export function certificateLibraryHash(state: CertificateLibraryState): string {
    const query = new URLSearchParams();
    if (state.filter !== 'all') query.set('filter', state.filter);
    if (state.search !== '') query.set('search', state.search);
    if (state.category !== '') query.set('category', state.category);
    if (state.page !== 1) query.set('page', String(state.page));
    return '/templates' + (query.size > 0 ? `?${query.toString()}` : '');
}
