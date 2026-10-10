import type { NotificationTemplateIdentity } from './api';

export type EmailTemplateRoute =
    | { kind: 'list' }
    | { kind: 'detail'; identity: NotificationTemplateIdentity }
    | { kind: 'not-found' };

function decoded(segment: string): string | null {
    try {
        const value = decodeURIComponent(segment);
        return value.trim() === '' ? null : value;
    } catch {
        return null;
    }
}

/** Strict screen-local route parser; an incomplete identity never opens a fallback row. */
export function parseEmailTemplateRoute(hash: string): EmailTemplateRoute {
    const normalized = hash.replace(/^#/, '') || '/';
    const path = normalized.split('?', 1)[0] ?? '/';
    if (path === '/' && (normalized === '/' || normalized === '' || normalized.startsWith('/?'))) {
        return { kind: 'list' };
    }
    const match = /^\/template\/([^/]+)\/([^/]+)\/([^/]+)$/.exec(path);
    if (match === null || normalized.includes('?')) {
        return { kind: 'not-found' };
    }
    const templateKey = decoded(match[1] ?? '');
    const locale = decoded(match[2] ?? '');
    const channelKey = decoded(match[3] ?? '');
    return templateKey === null || locale === null || channelKey === null
        ? { kind: 'not-found' }
        : { kind: 'detail', identity: { templateKey, locale, channelKey } };
}

export function emailTemplateHash(identity: NotificationTemplateIdentity): string {
    return `/template/${encodeURIComponent(identity.templateKey)}/${encodeURIComponent(identity.locale)}/${encodeURIComponent(identity.channelKey)}`;
}

/** Add a screen-local deep link to an adapter-owned Joomla or WordPress URL. */
export function emailTemplateStudioUrl(base: string, identity: NotificationTemplateIdentity): string {
    return `${base.split('#', 1)[0]}#${emailTemplateHash(identity)}`;
}
