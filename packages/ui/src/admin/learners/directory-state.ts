import { useHashRoute } from '../hooks';
import type { LearnerListParams } from '../api';

export function readLearnerDirectory(hash: string) {
    const params = new URLSearchParams(hash.startsWith('/?') ? hash.slice(2) : '');
    const sort = params.get('sort');
    const page = Number(params.get('page'));
    const perPage = Number(params.get('per_page'));
    return {
        search: params.get('search') ?? '',
        sort: (['last_active', 'enrolled_at', 'progress', 'courses'].includes(sort ?? '')
            ? sort
            : 'last_active') as NonNullable<LearnerListParams['sort']>,
        order: params.get('order') === 'asc' ? ('asc' as const) : ('desc' as const),
        page: Number.isSafeInteger(page) && page > 0 ? page : 1,
        perPage: [20, 50, 100].includes(perPage) ? perPage : 20,
    };
}

export function useLearnerDirectoryState() {
    const [hash, navigate] = useHashRoute();
    const state = readLearnerDirectory(hash);
    const update = (patch: Partial<typeof state>) => {
        const next = { ...state, page: 1, ...patch };
        const params = new URLSearchParams();
        if (next.search) params.set('search', next.search);
        if (next.sort !== 'last_active') params.set('sort', next.sort);
        if (next.order !== 'desc') params.set('order', next.order);
        if (next.page !== 1) params.set('page', String(next.page));
        if (next.perPage !== 20) params.set('per_page', String(next.perPage));
        navigate(params.size ? `/?${params}` : '/');
    };
    return { ...state, update };
}
