import type { ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient, type FetchLike } from '@vidyalms/api-sdk';
import type { BadgeAward, Certificate, CertificateVerification } from '@vidyalms/api-sdk';
import { MOUNT_CONTRACT } from '@/shared/config';
import { ApiClientContext } from '@/shared/context';
import { SessionTracker, SessionTrackerContext, withSessionTracking } from '@/shared/session';
import { createAppQueryClient } from '@/shared/query';
import { CredentialConfigContext, type CredentialConfig } from '../config';

/*
 * Test-only harness of the credential site pages: the mount config, an SDK
 * client over a stubbed fetch, and fixtures shaped exactly like the live
 * contract answers.
 *
 * The verification page is reached with no session at all, so the harness
 * deliberately carries no user of its own beyond the guest id.
 */

export function makeConfig(overrides: Partial<CredentialConfig> = {}): CredentialConfig {
    return {
        contract: MOUNT_CONTRACT,
        api: { base: 'http://localhost/v1', mode: 'prefix', headers: {} },
        user: { id: 0, name: '' },
        locale: 'en-GB',
        screen: 'credentials',
        subject: 'certificate',
        code: '',
        urls: { home: '/', verify: '/verify', myLearning: '/my-learning' },
        ...overrides,
    };
}

export interface RecordedRequest {
    method: string;
    path: string;
    body: unknown;
}

export type RouteHandler = (request: RecordedRequest) => Response | null;

export function makeHarness(handler: RouteHandler, config: CredentialConfig = makeConfig()) {
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
        <CredentialConfigContext.Provider value={config}>
            <SessionTrackerContext.Provider value={sessionTracker}>
                <ApiClientContext.Provider value={client}>
                    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
                </ApiClientContext.Provider>
            </SessionTrackerContext.Provider>
        </CredentialConfigContext.Provider>
    );
    return { wrapper, requests, queryClient, config };
}

export function jsonResponse(payload: unknown, status = 200): Response {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

export function problemResponse(status: number, title = 'Problem', detail?: string): Response {
    return new Response(JSON.stringify({ type: 'about:blank', title, status, detail }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}

/* ── Fixtures ───────────────────────────────────────────────────────── */

export function makeVerification(
    overrides: Partial<CertificateVerification> = {},
): CertificateVerification {
    return {
        status: 'active',
        valid: true,
        reason: null,
        revoked: false,
        expired: false,
        course_title: 'Foundations of Web Accessibility',
        learner_name: 'Ana Costa',
        issued_at: '2026-09-01 10:00:00',
        completed_at: '2026-09-01 09:59:58',
        valid_from: null,
        expires_at: null,
        revoked_at: null,
        issuer: { name: 'Northlight Academy', url: 'https://example.test' },
        credits: [],
        credential_url: 'https://example.test/api/v1/credentials/0f1c',
        ...overrides,
    };
}

export function makeMyCertificate(overrides: Partial<Certificate> & { id: number } = { id: 1 }): Certificate {
    return {
        course_id: 1,
        enrolment_id: 3,
        code: 'NL7QK29M4X12',
        code_display: 'NL-7QK2-9M4X',
        uuid: '0f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f',
        status: 'active',
        issued_at: '2026-09-01 10:00:00',
        completed_at: '2026-09-01 09:59:58',
        valid_from: null,
        expires_at: null,
        revoked_at: null,
        supersedes_id: null,
        superseded_by_id: null,
        published: true,
        credential_url: 'https://example.test/api/v1/credentials/0f1c',
        credits: [],
        ...overrides,
    };
}

export function makeBadgeAward(overrides: Partial<BadgeAward> & { id: number } = { id: 1 }): BadgeAward {
    return {
        badge_id: 4,
        badge_name: 'Accessible Forms',
        user_id: 482,
        uuid: 'a1b2c3d4-0000-0000-0000-000000000000',
        code: 'NL9BADGE1234',
        status: 'active',
        criteria_version: 1,
        issued_at: '2026-08-01 10:00:00',
        valid_from: null,
        expires_at: null,
        revoked_at: null,
        revocation_reason: null,
        supersedes_id: null,
        superseded_by_id: null,
        verification_url: 'https://example.test/verify/badge/NL9BADGE1234',
        credential_url: null,
        ...overrides,
    };
}
