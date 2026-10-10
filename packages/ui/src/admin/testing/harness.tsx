import type { ReactNode } from 'react';
import { MOUNT_CONTRACT } from '@/shared/config';
import type { QueryClient } from '@tanstack/react-query';
import { QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient, type FetchLike, type VidyaLmsClient } from '@vidyalms/api-sdk';
import { ApiClientContext } from '@/shared/context';
import { SessionTracker, SessionTrackerContext, withSessionTracking } from '@/shared/session';
import { createAppQueryClient } from '@/shared/query';
import type {
    AdminDashboard,
    CertificateRecord,
    EnrolmentRecord,
    LearnerRecord,
    ManagedCourse,
    PlatformSettings,
    UserAccount,
} from '@vidyalms/api-sdk';
import { AdminConfigContext, type AdminConfig } from '../config';

/*
 * Test-only harness of the admin suites: the mount config, an SDK client
 * over a stubbed fetch (the SDK boundary is the mock seam; no network), and
 * fixtures shaped exactly like the live contract answers.
 */

export function makeConfig(overrides: Partial<AdminConfig> = {}): AdminConfig {
    return {
        contract: MOUNT_CONTRACT,
        /* Absolute base: Node's Request rejects relative URLs. */
        api: { base: 'http://localhost/v1', mode: 'prefix', headers: { 'X-CSRF-Token': 'test' } },
        user: { id: 1, name: 'Super User' },
        locale: 'en-GB',
        screen: 'dashboard',
        urls: {
            workbench: '/admin/course/{course_id}',
            preview: '/course/{slug}',
            settings: '/admin/settings',
            screens: { courses: '/admin/courses', enrolments: '/admin/enrolments' },
        },
        ...overrides,
    };
}

export interface RecordedRequest {
    method: string;
    path: string;
    body: unknown;
    /** Lower-cased, so a test can assert on Idempotency-Key without guessing its case. */
    headers: Record<string, string>;
}

export type RouteHandler = (request: RecordedRequest) => Response | null;

export interface Harness {
    wrapper: (props: { children: ReactNode }) => ReactNode;
    requests: RecordedRequest[];
    queryClient: QueryClient;
    client: VidyaLmsClient;
    config: AdminConfig;
    sessionTracker: SessionTracker;
}

/**
 * Routes requests by "METHOD pathname" through the handler; a null answer
 * falls through to 404. Bodies are recorded parsed for assertions.
 */
export function makeHarness(handler: RouteHandler, config: AdminConfig = makeConfig()): Harness {
    const requests: RecordedRequest[] = [];
    const fetchLike: FetchLike = async (request) => {
        const url = new URL(request.url, 'http://localhost/');
        const raw = request.method === 'GET' || request.method === 'HEAD' ? null : await request.text();
        const headers: Record<string, string> = {};
        request.headers.forEach((value, key) => {
            headers[key.toLowerCase()] = value;
        });
        const recorded: RecordedRequest = {
            method: request.method,
            path: url.pathname + url.search,
            body: raw !== null && raw !== '' ? JSON.parse(raw) : null,
            headers,
        };
        requests.push(recorded);
        const response = handler(recorded);
        if (response !== null) {
            return response;
        }
        return new Response(JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }), {
            status: 404,
            headers: { 'Content-Type': 'application/problem+json' },
        });
    };
    const sessionTracker = new SessionTracker();
    const client = createVidyaLmsClient(config.api, withSessionTracking(sessionTracker, fetchLike));
    // The shipped configuration, not a friendlier one. See the instructor
    // harness: a test client that retries differently from the bundle cannot
    // see what the bundle does with a refusal.
    const queryClient = createAppQueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => (
        <AdminConfigContext.Provider value={config}>
            <SessionTrackerContext.Provider value={sessionTracker}>
                <ApiClientContext.Provider value={client}>
                    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
                </ApiClientContext.Provider>
            </SessionTrackerContext.Provider>
        </AdminConfigContext.Provider>
    );
    return { wrapper, requests, queryClient, client, config, sessionTracker };
}

export function jsonResponse(payload: unknown): Response {
    return Response.json(payload);
}

export function problemResponse(status: number, title = 'Problem', detail?: string): Response {
    return new Response(JSON.stringify({ type: 'about:blank', title, status, detail }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}

/* ── Fixtures ───────────────────────────────────────────────────────── */

export function makeDashboard(overrides: Partial<AdminDashboard> = {}): AdminDashboard {
    return {
        courses: { published: 14, draft: 3, archived: 0 },
        enrolments: { active: 1412, suspended: 61, completed: 318 },
        completions: { total: 318, this_month: 27 },
        certificates: { issued: 306, revoked: 0 },
        attention: {
            grading_waiting: { submissions: 12, attempts: 0, courses: 4, older_than_days: 3 },
            expiring_entitlements: { count: 23, within_days: 14 },
            expiry_sweep: { last_run_at: '2026-09-01 09:54:00', schedule: 'hourly' },
            lessons_without_provider: { count: 0, types: [] },
        },
        top_courses: [
            { id: 1, title: 'Foundations of Web Accessibility', slug: 'web-accessibility', active_count: 128, enrolled_count: 160, completed_count: 32, lesson_count: 12 },
            { id: 2, title: 'Inclusive Design Patterns', slug: 'inclusive-design', active_count: 95, enrolled_count: 100, completed_count: 5, lesson_count: 8 },
            { id: 3, title: 'ARIA in Practice', slug: 'aria-in-practice', active_count: 57, enrolled_count: 80, completed_count: 23, lesson_count: 9 },
        ],
        generated_at: '2026-09-01 10:00:00',
        activity: {
            period_days: 30,
            daily: Array.from({ length: 30 }, (_, index) => ({
                date: new Date(Date.UTC(2026, 7, 3 + index)).toISOString().slice(0, 10),
                enrolments: index + 1,
                completions: index % 3,
                previous_enrolments: 1,
                previous_completions: 0,
            })),
            recent: [{ kind: 'enrolled', course_id: 1, course_title: 'Learning systems', occurred_at: '2026-09-01 09:00:00' }],
        },
        ...overrides,
    };
}

export function makeCourse(overrides: Partial<ManagedCourse> & { id: number; title: string }): ManagedCourse {
    return {
        slug: `course-${overrides.id}`,
        summary: null,
        image: null,
        category_id: null,
        level: null,
        language: 'en-GB',
        visibility: 'public',
        state: 'published',
        price_mode: 'free',
        featured: false,
        completion_rule: 'all_lessons',
        completion_threshold: null,
        published_at: '2026-06-01 10:00:00',
        updated_at: '2026-08-30 10:00:00',
        lesson_count: 12,
        archived_lesson_count: 0,
        enrolled_count: 128,
        active_count: 120,
        completed_count: 31,
        suspended_count: 8,
        completion_rate: 24,
        instructors: [{ user_id: 7, name: 'R. Okafor', role: 'lead' }],
        ...overrides,
    };
}

export function makeEnrolment(
    overrides: Partial<EnrolmentRecord> & { id: number } = { id: 1 },
): EnrolmentRecord {
    return {
        user: { id: 482, name: 'Jonas Berg' },
        course: { id: 1, title: 'Foundations of Web Accessibility', slug: 'web-accessibility' },
        status: 'active',
        progress_percent: 41,
        enrolled_at: '2026-03-02 10:00:00',
        completed_at: null,
        last_activity_at: '2026-08-30 10:00:00',
        entitlement: {
            id: 900 + overrides.id,
            user_id: 482,
            course_id: 1,
            source: 'purchase',
            source_ref: 'order #1042',
            status: 'active',
            granted_by: null,
            starts_at: '2026-03-02 10:00:00',
            expires_at: '2026-09-04 23:59:59',
        },
        ...overrides,
    };
}

export function makeCertificate(
    overrides: Partial<CertificateRecord> & { id: number } = { id: 1 },
): CertificateRecord {
    return {
        course_id: 1,
        enrolment_id: 3,
        code: 'NL-7QK2-9M4X',
        uuid: '0f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f',
        issued_at: '2026-09-01 10:00:00',
        completed_at: '2026-09-01 09:59:58',
        valid_from: null,
        expires_at: null,
        revoked_at: null,
        supersedes_id: null,
        superseded_by_id: null,
        published: true,
        credential_url: null,
        credits: [],
        learner: { id: 482, name: 'Ana Costa' },
        course: { id: 1, title: 'Foundations of Web Accessibility', slug: 'web-accessibility' },
        status: 'active',
        template_id: 1,
        template_revision: 1,
        revocation_reason: null,
        verification_url: 'https://example.test/verify/NL-7QK2-9M4X',
        ...overrides,
    };
}

export function makeLearnerRecord(overrides: Partial<LearnerRecord> = {}): LearnerRecord {
    return {
        user: { id: 482, name: 'Tom Weiss', email: 'tom.weiss@example.org' },
        enrolments: [
            makeEnrolment({
                id: 5,
                status: 'suspended',
                progress_percent: 41,
                entitlement: {
                    id: 905,
                    user_id: 482,
                    course_id: 1,
                    source: 'purchase',
                    source_ref: 'order #1042',
                    status: 'expired',
                    granted_by: null,
                    starts_at: '2026-03-02 10:00:00',
                    expires_at: '2026-08-14 23:59:59',
                },
            }),
        ],
        certificates: [makeCertificate({ id: 2, code: 'NL-3FA8-P2QM', issued_at: '2026-07-12 10:00:00' })],
        ...overrides,
    };
}

export function makeSettings(overrides: Partial<PlatformSettings> = {}): PlatformSettings {
    return {
        configuration: {
            revision: 1,
            values: {
                academy_name: '', site_base_url: '', site_logo_url: '', verification_base_url: '',
                credential_base_url: '', status_list_base_url: '', unsubscribe_base_url: '', envelope_domain: '',
                catalogue_per_page: 12, self_enrolment: true, import_max_upload_mb: 64,
                upload_max_resource_mb: 64, upload_max_image_mb: 8, private_storage_path: '',
                private_storage_dir: 'vidyalms-private', import_source_retention_days: 30,
                integrations_http_allowlist: '', integrations_http_timeout: 15,
            },
        },
        certificate_template: { id: 1, name: 'Standard', renderer: 'html' },
        verification_base_url: 'https://example.test/verify',
        heartbeat: { interval_seconds: 15, max_seconds_per_heartbeat: 120 },
        expiry_sweep: { schedule: 'hourly', last_run_at: '2026-09-01 09:54:00' },
        commerce: { source: 'cms-shop', label: 'EasyCommerce', sources: [{ key: 'cms-shop', label: 'EasyCommerce', is_default: true }] },
        self_enrolment: true,
        host_settings_url: '/admin/settings',
        background_actor: { user_id: null, name: null, may_grant: false },
        issuer: {
            name: null,
            url: null,
            image: null,
            resolved: {
                id: 'https://example.test',
                name: 'Example Site',
                url: 'https://example.test',
                image: null,
            },
        },
        ...overrides,
    };
}

export const userFixtures: UserAccount[] = [
    { id: 482, name: 'Tom Weiss', email: 'tom.weiss@example.org' },
    { id: 483, name: 'Mira Sato', email: 'mira.sato@example.org' },
];
