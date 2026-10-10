import type { ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient, type FetchLike } from '@vidyalms/api-sdk';
import { MOUNT_CONTRACT } from '@/shared/config';
import { ApiClientContext } from '@/shared/context';
import { SessionTracker, SessionTrackerContext, withSessionTracking } from '@/shared/session';
import { createAppQueryClient } from '@/shared/query';
import { NotificationConfigContext, type NotificationConfig } from '../config';

/*
 * Test-only harness of the notification site pages: the mount config, an SDK
 * client over a stubbed fetch, and nothing else. The unsubscribe page is
 * reached with no session at all, so the harness deliberately carries no
 * user of its own beyond the guest id.
 */

export function makeConfig(overrides: Partial<NotificationConfig> = {}): NotificationConfig {
    return {
        contract: MOUNT_CONTRACT,
        api: { base: 'http://localhost/v1', mode: 'prefix', headers: {} },
        user: { id: 0, name: '' },
        locale: 'en-GB',
        screen: 'preferences',
        token: '',
        urls: { home: '/', preferences: '/notifications' },
        ...overrides,
    };
}

export interface RecordedRequest {
    method: string;
    path: string;
    body: unknown;
}

export type RouteHandler = (request: RecordedRequest) => Response | null;

export function makeHarness(handler: RouteHandler, config: NotificationConfig = makeConfig()) {
    const requests: RecordedRequest[] = [];
    const fetchLike: FetchLike = async (request) => {
        const url = new URL(request.url, 'http://localhost/');
        const raw = request.method === 'GET' || request.method === 'HEAD' ? null : await request.text();
        const recorded: RecordedRequest = {
            method: request.method,
            path: url.pathname + url.search,
            body: raw !== null && raw !== '' ? JSON.parse(raw) : null,
        };
        requests.push(recorded);
        return (
            handler(recorded) ??
            new Response(JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }), {
                status: 404,
                headers: { 'Content-Type': 'application/problem+json' },
            })
        );
    };
    const sessionTracker = new SessionTracker();
    const client = createVidyaLmsClient(config.api, withSessionTracking(sessionTracker, fetchLike));
    const queryClient = createAppQueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => (
        <NotificationConfigContext.Provider value={config}>
            <SessionTrackerContext.Provider value={sessionTracker}>
                <ApiClientContext.Provider value={client}>
                    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
                </ApiClientContext.Provider>
            </SessionTrackerContext.Provider>
        </NotificationConfigContext.Provider>
    );
    return { wrapper, requests, queryClient, config };
}

export function jsonResponse(payload: unknown, status = 200): Response {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

export function problemResponse(status: number, title = 'Problem'): Response {
    return new Response(JSON.stringify({ type: 'about:blank', title, status }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}
