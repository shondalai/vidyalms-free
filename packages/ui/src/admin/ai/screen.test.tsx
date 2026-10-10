import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AiScreen } from './screen';
import type { AiEnablement, AiPolicyDocument, AiRoute, AiRun, AiUsageReport } from './api';
import type { AdminScreenRuntime } from '../screens/registry';
import {
    jsonResponse,
    makeConfig,
    makeHarness,
    problemResponse,
    type Harness,
    type RecordedRequest,
} from '../testing/harness';

/*
 * Every state of the AI screen, driven from the contract shapes rather than
 * from a rendered tree.
 *
 * The states that matter most are the empty ones, because on a free build they
 * are the whole product: no route configured, no policy active, no run
 * recorded, and a spend that is unmetered rather than zero. A screen that only
 * looks right once a paid package is installed is a screen nobody shipping the
 * free build ever sees working.
 */

const PLACEMENT = {
    key: 'question_bank',
    label: 'Question bank',
    description: 'Review suggested questions before adding them to your bank.',
    surface_key: 'instructor_workbench',
    surface_label: 'Instructor workbench',
    enabled: false,
    package: null,
    edition: 'free' as const,
};

function makeEnablement(overrides: Partial<AiEnablement> = {}): AiEnablement {
    return {
        site_enabled: false,
        stop: {
            stopped: false,
            reason: null,
            set_by: null,
            set_at: null,
            cleared_by: null,
            cleared_at: null,
        },
        // Derived from the registered policies rather than enumerated, so a
        // vendor gate publishes its own row instead of the screen showing four
        // green levels while a fifth one refuses every run.
        levels: [
            { level: 'site', setting: 'enabled', stored_in: 'provider_settings', refused_by: 'placement_enabled' },
            {
                level: 'placement',
                setting: 'placement.{placement_key}',
                stored_in: 'provider_settings',
                refused_by: 'placement_enabled',
            },
            { level: 'route', setting: 'ai_routes.enabled', stored_in: 'ai_routes', refused_by: 'route_available' },
            {
                level: 'person',
                setting: 'ai_policy_acceptances.policy_version',
                stored_in: 'ai_policy_acceptances',
                refused_by: 'policy_accepted',
            },
        ],
        ceilings: {},
        placements: [PLACEMENT],
        routes: { total: 0, enabled: 0, tasks_with_enabled_route: 0 },
        policy: null,
        ...overrides,
    };
}

function makePolicy(overrides: Partial<AiPolicyDocument> = {}): AiPolicyDocument {
    return {
        version: 1,
        title: 'How this site uses AI',
        body: 'Accuracy varies and outputs must be double-checked.',
        locale: 'en-GB',
        redaction_level: 'strict',
        retention_days: 400,
        training_reuse: 'declined',
        enabled_tasks: ['check.schema_validity'],
        enabled_tasks_version: 1,
        body_hash: 'a'.repeat(64),
        effective_from: '2026-09-01 00:00:00',
        state: 'draft',
        created_by: 1,
        acceptance_count: 0,
        // The parts still empty. Activation refuses while this is non-empty,
        // because a version that discloses nothing cannot be the one people
        // are recorded as having accepted.
        missing_parts: [],
        ...overrides,
    };
}

function makeRun(overrides: Partial<AiRun> = {}): AiRun {
    return {
        id: 5,
        run_key: 'run-5',
        job_id: null,
        task_key: 'check.item_writing_flaws',
        task_version: 1,
        placement_key: 'question_bank',
        route_id: null,
        bridge_key: 'null',
        model: null,
        prompt_ref: null,
        policy_version: 1,
        principal_user_id: 10,
        actor_kind: 'person',
        rule_id: null,
        on_behalf_of_user_id: null,
        subject_type: null,
        subject_id: null,
        course_id: 3,
        organisation_id: null,
        locale: 'en-GB',
        inputs_digest: 'b'.repeat(64),
        redaction_manifest: ['lesson:88', 'submission:412'],
        redaction_manifest_version: 1,
        dry_run: true,
        state: 'previewed',
        refusal: { kind: 'not_configured', message_key: 'vidyalms.ai.refusal.not_configured', parameters: {} },
        error_code: null,
        error_message: null,
        tokens_in: null,
        tokens_out: null,
        cost_unit: 'none',
        cost_reserved: null,
        cost_charged: null,
        unbilled: false,
        schema_valid: null,
        schema_unenforced: [],
        provider_request_id: null,
        finish_reason: null,
        duration_ms: null,
        started_at: null,
        completed_at: null,
        settled_at: null,
        retain_until: '2027-10-13 00:00:00',
        created_at: '2026-09-08 09:00:00',
        subject_count: 0,
        attempts: [
            {
                attempt_no: 1,
                route_id: null,
                bridge_key: 'null',
                model: null,
                success: false,
                refusal_kind: 'not_configured',
                error_code: null,
                error_message: null,
                tokens_in: null,
                tokens_out: null,
                cost_unit: 'none',
                cost_charged: null,
                latency_ms: 4,
                started_at: '2026-09-08 09:00:00',
                completed_at: '2026-09-08 09:00:00',
            },
        ],
        drafts: [],
        ...overrides,
    };
}

function makeUsage(overrides: Partial<AiUsageReport> = {}): AiUsageReport {
    return {
        from: null,
        to: null,
        totals: [{ key: 'all', label: 'All runs', runs: 2, refusals: 1, cost_unit: 'none', cost_charged: null }],
        by_task: [
            {
                key: 'check.item_writing_flaws',
                label: 'Item writing flaws',
                runs: 2,
                refusals: 1,
                cost_unit: 'none',
                cost_charged: null,
            },
        ],
        by_placement: [],
        by_principal: [],
        by_course: [],
        by_organisation: [],
        refusals: [{ kind: 'not_configured', count: 1 }],
        oversight: [
            {
                task_key: 'check.item_writing_flaws',
                label: 'Item writing flaws',
                accepted: 8,
                accepted_unedited: 6,
                accept_unedited_rate: '0.7500',
                median_edit_distance: '0.1100',
            },
        ],
        ceilings: [{ policy_key: 'per_run_cost', label: 'Cost per run', decision: 'allow', headroom: null }],
        policy_version: 1,
        acceptance_count: 3,
        unmetered: true,
        ...overrides,
    };
}

interface World {
    hash?: string;
    enablement?: AiEnablement;
    policies?: AiPolicyDocument[];
    runs?: AiRun[];
    routes?: AiRoute[];
    usage?: AiUsageReport;
    drafts?: unknown[];
    enablementStatus?: number;
    navigate?: (hash: string) => void;
}

function renderScreen(world: World = {}) {
    const navigate = world.navigate ?? vi.fn();
    const enablement = world.enablement ?? makeEnablement();
    const runs = world.runs ?? [];

    const harness: Harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        const path = url.pathname.replace('/v1', '');

        if (request.method === 'GET' && path === '/manage/ai/enablement') {
            if (world.enablementStatus !== undefined) {
                return problemResponse(world.enablementStatus, 'Refused');
            }
            return jsonResponse(enablement);
        }
        if (request.method === 'PUT' && path === '/manage/ai/enablement') {
            return jsonResponse(
                makeEnablement({
                    site_enabled: true,
                    placements: [{ ...PLACEMENT, enabled: true }],
                }),
            );
        }
        if (request.method === 'POST' && path === '/manage/ai/stop') {
            return jsonResponse({
                stopped: true,
                reason: 'Reviewing the statement.',
                set_by: 1,
                set_at: '2026-09-08 12:00:00',
                cleared_by: null,
                cleared_at: null,
            });
        }
        if (request.method === 'GET' && path === '/manage/ai/policies') {
            const items = world.policies ?? [];
            return jsonResponse({ items, total: items.length, page: 1, per_page: 25 });
        }
        if (request.method === 'PATCH' && /^\/manage\/ai\/policies\/\d+$/.test(path)) {
            return jsonResponse(makePolicy({ state: 'active' }));
        }
        if (request.method === 'GET' && path === '/manage/ai/routes') {
            const items = world.routes ?? [];
            return jsonResponse({ items, total: items.length, page: 1, per_page: 25 });
        }
        if (request.method === 'GET' && path === '/manage/ai/runs') {
            return jsonResponse({ items: runs, total: runs.length, page: 1, per_page: 25 });
        }
        if (request.method === 'GET' && /^\/manage\/ai\/runs\/\d+$/.test(path)) {
            return jsonResponse(runs[0] ?? makeRun());
        }
        if (request.method === 'GET' && path === '/ai/drafts') {
            const items = world.drafts ?? [];
            return jsonResponse({ items, total: items.length, page: 1, per_page: 25 });
        }
        if (request.method === 'GET' && path === '/manage/ai/usage') {
            return jsonResponse(world.usage ?? makeUsage());
        }
        return null;
    }, makeConfig({ screen: 'ai' }));

    const runtime: AdminScreenRuntime = {
        config: harness.config,
        client: harness.client,
        hash: world.hash ?? '/',
        navigate,
        replace: vi.fn(),
        mountEl: null,
    };

    const view = render(<AiScreen runtime={runtime} />, { wrapper: harness.wrapper });
    return { ...view, harness, navigate };
}

describe('the admin AI screen', () => {
    it('draws its tab list and marks the current section', async () => {
        renderScreen({ hash: '/usage' });

        const tabs = await screen.findAllByRole('tab');
        expect(tabs.map((tab) => tab.textContent)).toEqual([
            'Enablement',
            'Data-use policy',
            'Routes',
            'Runs',
            'Drafts',
            'Usage',
        ]);
        expect(screen.getByRole('tab', { name: 'Usage' })).toHaveAttribute('aria-selected', 'true');
    });

    it('moves between tabs with the arrow keys, so the screen is keyboard operable', async () => {
        const navigate = vi.fn();
        renderScreen({ navigate });

        const first = await screen.findByRole('tab', { name: 'Enablement' });
        first.focus();
        await userEvent.keyboard('{ArrowRight}');

        expect(navigate).toHaveBeenCalledWith('/policy');
        expect(screen.getByRole('tab', { name: 'Data-use policy' })).toHaveFocus();

        await userEvent.keyboard('{ArrowLeft}');
        expect(navigate).toHaveBeenLastCalledWith('/');
        expect(first).toHaveFocus();
        await userEvent.keyboard('{ArrowLeft}');
        expect(navigate).toHaveBeenLastCalledWith('/usage');
    });

    it('shows the four levels off, with the reason each one is off', async () => {
        renderScreen();

        expect(await screen.findByText(/Level three\. No route is configured/)).toBeInTheDocument();
        expect(screen.getByText(/Level four\. No data-use policy is active/)).toBeInTheDocument();
        expect(screen.getByLabelText(/AI is enabled on this site/)).not.toBeChecked();
        expect(screen.getByTestId('ai-stop-state')).toHaveTextContent('AI is not stopped.');
    });

    it('generates the helper switches from what is registered rather than from a fixed list', async () => {
        renderScreen({
            enablement: makeEnablement({
                placements: [
                    PLACEMENT,
                    { ...PLACEMENT, key: 'vendor_panel', label: 'A vendor panel', edition: 'third_party' },
                ],
            }),
        });

        expect(await screen.findByLabelText(/Question bank/)).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Question bank' })).toHaveAccessibleDescription(
            `${PLACEMENT.description} ${PLACEMENT.surface_label}`,
        );
        expect(screen.queryByText('instructor_workbench')).not.toBeInTheDocument();
        expect(screen.getByLabelText(/A vendor panel/)).toBeInTheDocument();
        expect(screen.getByText('Third party')).toBeInTheDocument();
    });

    it('saves the site switch and the helper switches as one act', async () => {
        const { harness } = renderScreen();

        await userEvent.click(await screen.findByLabelText(/AI is enabled on this site/));
        await userEvent.click(screen.getByLabelText(/Question bank/));
        await userEvent.click(screen.getByRole('button', { name: 'Save enablement' }));

        await waitFor(() => {
            const put = harness.requests.find((request) => request.method === 'PUT');
            expect(put).toBeDefined();
            expect(put?.body).toEqual({ site_enabled: true, placements: { question_bank: true } });
            expect(put?.headers['idempotency-key']).toBeTruthy();
        });
    });

    it('asks before it stops every task, and records who stopped it', async () => {
        const { harness } = renderScreen();

        await userEvent.click(await screen.findByRole('button', { name: 'Stop all AI' }));
        expect(screen.getByText('Stop every AI task on this site?')).toBeInTheDocument();

        await userEvent.type(screen.getByLabelText(/Reason/), 'Reviewing the statement.');
        const confirm = screen.getAllByRole('button', { name: 'Stop all AI' });
        await userEvent.click(confirm[confirm.length - 1] as HTMLElement);

        await waitFor(() => {
            const post = harness.requests.find((request) => request.path.includes('/manage/ai/stop'));
            expect(post?.body).toEqual({ reason: 'Reviewing the statement.' });
        });
    });

    it('shows a stopped site as stopped, with who set it', async () => {
        renderScreen({
            enablement: makeEnablement({
                stop: {
                    stopped: true,
                    reason: 'Reviewing the statement.',
                    set_by: 1,
                    set_at: '2026-09-08 12:00:00',
                    cleared_by: null,
                    cleared_at: null,
                },
            }),
        });

        expect(await screen.findByTestId('ai-stop-state')).toHaveTextContent('AI is stopped on this site.');
        expect(screen.getByText('Stopped by user 1 at 2026-09-08 12:00:00.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Resume AI' })).toBeInTheDocument();
    });

    it('renders a refused enablement read as an error rather than an empty screen', async () => {
        renderScreen({ enablementStatus: 403 });

        expect(await screen.findByText('The AI screen could not load.')).toBeInTheDocument();
    });

    it('lists policy versions and offers activation only on a draft', async () => {
        renderScreen({
            hash: '/policy',
            policies: [makePolicy(), makePolicy({ version: 2, state: 'active', acceptance_count: 12 })],
        });

        expect((await screen.findAllByText('How this site uses AI')).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('button', { name: 'Activate' })).toHaveLength(1);
        expect(screen.getByText('12')).toBeInTheDocument();
    });

    it('says why the route table is empty rather than showing a blank grid', async () => {
        renderScreen({ hash: '/routes' });

        expect(
            await screen.findByText('No route is configured. Every task refuses with "not configured".'),
        ).toBeInTheDocument();
    });

    it('shows translated route names and keeps routes readable after a provider is removed', async () => {
        const route: AiRoute = {
            id: 1,
            task_key: 'lesson.draft_from_sources',
            task_label: 'Draft a lesson from course sources',
            bridge_key: 'null',
            bridge_label: 'No model (nothing leaves this site)',
            bridge_available: true,
            data_handling: { declared: false },
            params_version: 1,
            position: 0,
            enabled: false,
            scope_type: 'site',
            scope_id: 0,
            max_cost_per_run: 0,
        };
        renderScreen({
            hash: '/routes',
            routes: [route, {
                ...route, id: 2, task_key: 'vendor.removed_task', task_label: null,
                bridge_key: 'removed_bridge', bridge_label: null, bridge_available: false,
            }],
        });

        expect(await screen.findByText('Draft a lesson from course sources')).toBeVisible();
        expect(screen.getByText('No model (nothing leaves this site)')).toBeVisible();
        expect(screen.queryByText('lesson.draft_from_sources')).not.toBeInTheDocument();
        expect(screen.queryByText('ai.bridge.null.label')).not.toBeInTheDocument();
        expect(screen.getByText('vendor.removed_task')).toBeVisible();
        expect(screen.getByText('No installed package answers this bridge key.')).toBeVisible();
    });

    it('lists runs including the previewed and refused ones', async () => {
        renderScreen({ hash: '/runs', runs: [makeRun()] });

        expect(await screen.findByText('check.item_writing_flaws')).toBeInTheDocument();
        expect(screen.getAllByText('previewed').length).toBeGreaterThan(0);
    });

    it('shows a run with its attempts and the manifest of the dry run', async () => {
        renderScreen({ hash: '/runs/5', runs: [makeRun()] });

        expect(await screen.findByText('Run 5')).toBeInTheDocument();
        expect(screen.getAllByText('not_configured').length).toBeGreaterThan(0);
        expect(screen.getByText('lesson:88')).toBeInTheDocument();
        expect(screen.getByText('submission:412')).toBeInTheDocument();
        expect(
            screen.getByText('This bridge reported no usage, so the cost is unknown rather than zero.'),
        ).toBeInTheDocument();
    });

    it('says the review queue is empty rather than drawing an empty list', async () => {
        renderScreen({ hash: '/drafts' });

        expect(await screen.findByText('No draft is waiting for a decision.')).toBeInTheDocument();
    });

    it('shows the oversight measurement and says the spend is unmetered', async () => {
        renderScreen({ hash: '/usage' });

        expect(await screen.findByText('0.7500')).toBeInTheDocument();
        expect(screen.getByText('0.1100')).toBeInTheDocument();
        expect(
            screen.getByText('No bridge on this site meters usage, so the spend is unmetered rather than zero.'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Unknown').length).toBeGreaterThan(0);
    });

    it('answers an address it does not publish with a way back', async () => {
        const navigate = vi.fn();
        renderScreen({ hash: '/spend', navigate });

        await userEvent.click(await screen.findByRole('button', { name: 'Back to enablement' }));
        expect(navigate).toHaveBeenCalledWith('/');
    });
});
