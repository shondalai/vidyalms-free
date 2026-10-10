export type QuestionBankRoute =
    | { kind: 'banks'; query: URLSearchParams }
    | { kind: 'new-bank' }
    | { kind: 'edit-bank'; bankId: number }
    | { kind: 'questions'; bankId: number; query: URLSearchParams }
    | { kind: 'new-question'; bankId: number; preset: string | null }
    | { kind: 'edit-question'; bankId: number; questionId: number }
    | { kind: 'not-found' };

function positiveId(raw: string): number | null {
    if (!/^[1-9]\d*$/.test(raw)) {
        return null;
    }
    const id = Number(raw);
    return Number.isSafeInteger(id) ? id : null;
}

function splitHash(hash: string): { path: string; query: URLSearchParams } {
    const normalized = hash.replace(/^#/, '') || '/';
    const question = normalized.indexOf('?');
    if (question < 0) {
        return { path: normalized, query: new URLSearchParams() };
    }
    return {
        path: normalized.slice(0, question) || '/',
        query: new URLSearchParams(normalized.slice(question + 1)),
    };
}

/** Strict parser so a stale bookmark never silently opens a different bank. */
export function parseQuestionBankRoute(hash: string): QuestionBankRoute {
    const { path, query } = splitHash(hash);
    if (path === '/') {
        return { kind: 'banks', query };
    }
    if (path === '/new') {
        return query.size === 0 ? { kind: 'new-bank' } : { kind: 'not-found' };
    }

    let match = /^\/banks\/([^/]+)$/.exec(path);
    if (match !== null) {
        const bankId = positiveId(match[1] ?? '');
        return bankId !== null && query.size === 0 ? { kind: 'edit-bank', bankId } : { kind: 'not-found' };
    }

    match = /^\/banks\/([^/]+)\/questions$/.exec(path);
    if (match !== null) {
        const bankId = positiveId(match[1] ?? '');
        return bankId === null ? { kind: 'not-found' } : { kind: 'questions', bankId, query };
    }

    match = /^\/banks\/([^/]+)\/questions\/new$/.exec(path);
    if (match !== null) {
        const bankId = positiveId(match[1] ?? '');
        if (bankId === null || [...query.keys()].some((key) => key !== 'type')) {
            return { kind: 'not-found' };
        }
        return { kind: 'new-question', bankId, preset: query.get('type') };
    }

    match = /^\/banks\/([^/]+)\/questions\/([^/]+)\/edit$/.exec(path);
    if (match !== null) {
        const bankId = positiveId(match[1] ?? '');
        const questionId = positiveId(match[2] ?? '');
        return bankId !== null && questionId !== null && query.size === 0
            ? { kind: 'edit-question', bankId, questionId }
            : { kind: 'not-found' };
    }

    return { kind: 'not-found' };
}

export function questionBankHash(path: string, values: Record<string, string | number | boolean | null | undefined> = {}): string {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
        if (value !== undefined && value !== null && value !== '' && value !== false) {
            query.set(key, String(value));
        }
    }
    const encoded = query.toString();
    return encoded === '' ? path : `${path}?${encoded}`;
}

export function replaceQuestionBankHash(next: string): void {
    const url = new URL(window.location.href);
    url.hash = next;
    window.history.replaceState(window.history.state, '', url);
    window.dispatchEvent(new HashChangeEvent('hashchange'));
}
