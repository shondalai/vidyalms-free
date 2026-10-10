import { describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { VidyaLmsClient } from '@vidyalms/api-sdk';
import { AutomationsScreen } from './screen';
import { bodyOf, draftFrom } from './draft';
import { automationKeys } from './api';
import type { AutomationActionLogEntry, AutomationCatalogue, AutomationRule, AutomationRun } from './api';
import type { AdminScreenRuntime } from '../screens/registry';
import {
    jsonResponse,
    makeConfig,
    makeHarness,
    problemResponse,
    type Harness,
    type RecordedRequest,
} from '../testing/harness';

const CATALOGUE: AutomationCatalogue = {
    triggers: [
        {
            key: 'vidyalms.trigger.enrolled',
            label: 'A learner is enrolled',
            kind: 'event',
            subject_types: ['user'],
            placeholders: ['learner_name'],
            sentence: { template: 'When {0} is enrolled', readable: 'When a learner is enrolled' },
        },
        {
            key: 'vidyalms.sweep.entitlement_expiring',
            label: 'Access is about to expire',
            kind: 'sweep',
            subject_types: ['user'],
            sentence: null,
        },
    ],
    conditions: [
        {
            key: 'completion',
            label: 'Course completion',
            params_schema: {
                type: 'object',
                required: ['course_id'],
                properties: { course_id: { type: 'integer', title: 'Course' } },
            },
            sentence: {
                template: 'and they completed course {0}',
                readable: 'and they completed a course',
                placeholders: ['course_id'],
            },
        },
    ],
    actions: [
        {
            key: 'enrol',
            label: 'Enrol the learner',
            params_schema: {
                type: 'object',
                properties: { course_id: { type: 'integer', title: 'Course' } },
            },
            reversible: true,
            reverses: 'unenrol',
            capabilities: ['SupportsDryRun', 'ValidatesPayload'],
            payload_versions: [1],
            sentence: {
                template: 'enrol them in course {0}',
                readable: 'enrol them in a course',
                placeholders: ['course_id'],
            },
        },
        {
            key: 'emit_event',
            label: 'Emit an event',
            params_schema: { type: 'object', properties: { event_key: { type: 'string' } } },
            reversible: false,
            reverses: null,
            capabilities: ['SupportsDryRun'],
            payload_versions: [1],
            sentence: null,
        },
    ],
    retry_policies: [
        { key: 'immediate_only', label: 'One attempt', ladder: [], max_attempts: 1, jitter: false },
        { key: 'external_default', label: 'The external ladder', ladder: [0, 5, 300], max_attempts: 8, jitter: true },
    ],
    failure_policies: [
        { key: 'park', label: 'Park for manual resume', behaviour: 'park', cancels_retries: false },
        { key: 'continue', label: 'Carry on', behaviour: 'continue', cancels_retries: true },
    ],
    anchors: [{ key: 'relative', label: 'A fixed wait', params_schema: { type: 'object', properties: {} } }],
    limits: { cooldown_seconds_default: 300, cascade_depth_ceiling: 5 },
};

function makeRule(overrides: Partial<AutomationRule> = {}): AutomationRule {
    return {
        id: 12,
        rule_key: 'welcome-pack',
        title: 'Welcome pack',
        trigger_key: 'vidyalms.trigger.enrolled',
        state: 'disabled',
        active: false,
        version: 1,
        definition_version: 1,
        conditions: null,
        conditions_version: 1,
        schedule_kind: 'immediate',
        anchor_key: null,
        schedule: null,
        schedule_version: 1,
        subject_binding: 'learner',
        reentry: 'never',
        times_per_subject: null,
        total_completions: null,
        cooldown_seconds: 300,
        run_order: 100,
        scope_type: 'site',
        scope_id: 0,
        course_id: null,
        cohort_id: null,
        organisation_id: null,
        max_ai_runs_per_occurrence: null,
        max_ai_runs_per_arming: null,
        armed_by: null,
        armed_at: null,
        armed_digest: null,
        last_dry_run_at: null,
        counters: { queued: 3, executed: 2, suppressed: 1, failed: 0 },
        actions: [
            {
                step_index: 0,
                action_key: 'enrol',
                params: { course_id: 4 },
                params_version: 1,
                guard_conditions: null,
                guard_conditions_version: 1,
                delay_anchor_key: null,
                delay: null,
                delay_version: 1,
                retry_policy_key: 'external_default',
                failure_policy_key: 'park',
                requires_approval_before_effect: false,
                produces_review_item: false,
                armed_prompt_ref: null,
                owner_user_id: null,
                sentence: null,
                missing: false,
            },
        ],
        missing_keys: [],
        last_test_result: null,
        versions: [{ version: 1, active: false }],
        created_by: 1,
        created_at: '2026-09-01 10:00:00',
        updated_at: '2026-09-01 10:00:00',
        ...overrides,
    };
}

function makeRun(overrides: Partial<AutomationRun> = {}): AutomationRun {
    return {
        id: 44,
        rule_id: 12,
        rule_version: 1,
        trigger_key: 'vidyalms.trigger.enrolled',
        event_ref: 'enrolment:900',
        occurrence_key: 'once',
        subject_type: 'user',
        subject_id: 482,
        actor_user_id: 1,
        dry_run: false,
        state: 'completed',
        cascade_depth: 0,
        rule_chain: null,
        counters: { matched: 1, executed: 1, skipped: 0, failed: 0 },
        started_at: '2026-09-02 10:00:00',
        finished_at: '2026-09-02 10:00:01',
        created_at: '2026-09-02 10:00:00',
        ...overrides,
    };
}

function makeLogEntry(overrides: Partial<AutomationActionLogEntry> = {}): AutomationActionLogEntry {
    return {
        id: 700,
        queue_id: 90,
        run_id: 44,
        rule_id: 12,
        rule_version: 1,
        step_index: 0,
        action_key: 'enrol',
        subject_type: 'user',
        subject_id: 482,
        actor_user_id: 1,
        idem_key: 'a'.repeat(64),
        success: true,
        before_state: null,
        after_state: null,
        state_version: 1,
        error_code: null,
        error_message: null,
        ai_run_id: null,
        executed_at: '2026-09-02 10:00:01',
        ...overrides,
    };
}

interface World {
    hash?: string;
    rules?: AutomationRule[];
    ruleStatus?: number;
    listStatus?: number;
    catalogueStatus?: number;
    runs?: AutomationRun[];
    log?: AutomationActionLogEntry[];
    saveStatus?: number;
    navigate?: (hash: string) => void;
    slowList?: boolean;
}

function renderScreen(world: World = {}) {
    const rules = world.rules ?? [makeRule()];
    const navigate = world.navigate ?? vi.fn();
    const harness: Harness = makeHarness(
        (request: RecordedRequest) => {
            const url = new URL(request.path, 'http://localhost');
            const path = url.pathname.replace('/v1', '');
            if (request.method === 'GET' && path === '/manage/automations/catalogue') {
                if (world.catalogueStatus !== undefined) {
                    return problemResponse(world.catalogueStatus, 'Unavailable');
                }
                return jsonResponse(CATALOGUE);
            }
            if (request.method === 'GET' && path === '/manage/automation-rules') {
                if (world.listStatus !== undefined) {
                    return problemResponse(world.listStatus, world.listStatus === 403 ? 'Refused' : 'Unavailable');
                }
                const selected = rules.filter(
                    (rule) =>
                        (!url.searchParams.get('state') || rule.state === url.searchParams.get('state')) &&
                        (!url.searchParams.get('search') ||
                            rule.title.toLowerCase().includes(url.searchParams.get('search')!.toLowerCase())),
                );
                const page = Number(url.searchParams.get('page') ?? 1);
                const perPage = Number(url.searchParams.get('per_page') ?? 25);
                return jsonResponse({
                    items: selected.slice((page - 1) * perPage, page * perPage),
                    total: selected.length,
                    page,
                    per_page: perPage,
                });
            }
            if (request.method === 'POST' && path === '/manage/automation-rules') {
                if (world.saveStatus !== undefined) {
                    return problemResponse(world.saveStatus, 'Refused');
                }
                return jsonResponse(makeRule({ id: 31 }));
            }
            if (request.method === 'GET' && /^\/manage\/automation-rules\/\d+$/.test(path)) {
                if (world.ruleStatus !== undefined) {
                    return problemResponse(world.ruleStatus, 'Not found');
                }
                return jsonResponse(rules[0] ?? makeRule());
            }
            if (request.method === 'PATCH' && /^\/manage\/automation-rules\/\d+$/.test(path)) {
                if (world.saveStatus !== undefined) {
                    return problemResponse(world.saveStatus, 'Conflict');
                }
                return jsonResponse(makeRule({ version: 2 }));
            }
            if (request.method === 'POST' && /\/arm$/.test(path)) {
                return jsonResponse(makeRule({ armed_by: 1, armed_at: '2026-09-03 10:00:00', state: 'enabled' }));
            }
            if (request.method === 'POST' && /\/disarm$/.test(path)) {
                return jsonResponse(makeRule({ armed_by: null, state: 'disabled' }));
            }
            if (request.method === 'DELETE' && /^\/manage\/automation-rules\/\d+$/.test(path)) {
                return jsonResponse({ id: 12, state: 'archived', cancelled_queue_rows: 2, runs_retained: 5 });
            }
            if (request.method === 'POST' && /\/preview$/.test(path)) {
                return jsonResponse({
                    rule_id: 12,
                    rule_version: 1,
                    run_id: 900,
                    dry_run: true,
                    occurrence_key: 'once',
                    matched_count: 2,
                    subjects: [{ subject_type: 'user', subject_id: 482, label: 'Ana Costa' }],
                    truncated: false,
                    steps: [
                        {
                            step_index: 0,
                            action_key: 'enrol',
                            subject_type: 'user',
                            subject_id: 482,
                            disposition: 'executed',
                            suppressed_reason: null,
                            payload: { course_id: 4 },
                            payload_version: 1,
                        },
                    ],
                    warnings: ['One subject has no email address.'],
                });
            }
            if (request.method === 'GET' && /\/runs$/.test(path)) {
                const items = world.runs ?? [makeRun()];
                return jsonResponse({ items, total: items.length, page: 1, per_page: 25 });
            }
            if (request.method === 'GET' && /\/runs\/\d+\/actions$/.test(path)) {
                const items = world.log ?? [makeLogEntry()];
                return jsonResponse({ items, total: items.length, page: 1, per_page: 25 });
            }
            return null;
        },
        makeConfig({ screen: 'automations' }),
    );

    const runtime: AdminScreenRuntime = {
        config: harness.config,
        client: harness.client as VidyaLmsClient,
        hash: world.hash ?? '/',
        navigate,
        replace: vi.fn(),
        mountEl: null,
    };
    const view = render(<AutomationsScreen runtime={runtime} />, { wrapper: harness.wrapper });
    return { ...view, harness, navigate, runtime };
}

describe('the host screen key', () => {
    it('registers the rule builder under "automations" and leaves "notifications" alone', async () => {
        const { registerFreeAdminScreens } = await import('../screens');
        const { adminScreen, adminScreenKeys, resetAdminScreens } = await import('../screens/registry');
        resetAdminScreens();
        registerFreeAdminScreens();
        expect(adminScreenKeys()).toContain('automations');
        expect(adminScreen('automations')?.label).toBe('Automations');
        expect(adminScreen('notifications')).toBeDefined();
        expect(adminScreen('notifications')?.label).not.toBe('Automations');
        resetAdminScreens();
    });
});

describe('the automations screen', () => {
    it('shows the loading state before the rules arrive', () => {
        renderScreen();
        expect(screen.getByRole('region', { name: 'Automation rules' })).toHaveAttribute('aria-busy', 'true');
    });

    it('lists rules with their state, run order and four counters', async () => {
        renderScreen();
        const row = await screen.findByRole('article', { name: /Welcome pack/ });
        expect(within(row).getByText('Disabled')).toBeInTheDocument();
        for (const count of ['3', '2', '1', '0']) expect(within(row).getByText(count)).toBeInTheDocument();
        expect(within(row).getByText(/Not armed · Run order 100/)).toBeInTheDocument();
    });

    it('names the missing keys of a degraded rule rather than only marking it broken', async () => {
        renderScreen({
            rules: [makeRule({ state: 'degraded', missing_keys: ['webhook', 'gentle_ladder'] })],
        });
        expect(await screen.findByText('Missing on this site: webhook, gentle_ladder')).toBeInTheDocument();
        const row = screen.getByRole('article', { name: /Welcome pack/ });
        expect(within(row).getByText('Degraded')).toBeInTheDocument();
    });

    it('shows the empty state with the one action that leaves it', async () => {
        renderScreen({ rules: [] });
        expect(await screen.findByText('No automation rules yet.')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Create automation' }).length).toBeGreaterThan(0);
    });

    it('shows an error with a retry when the list fails', async () => {
        renderScreen({ listStatus: 500 });
        const alert = await screen.findByRole('alert');
        expect(within(alert).getByText('The rules could not be loaded.')).toBeInTheDocument();
        expect(within(alert).getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });

    it('explains a refusal rather than showing an empty list', async () => {
        renderScreen({ listStatus: 403, catalogueStatus: 403 });
        expect(await screen.findByText('You may not manage automations.')).toBeInTheDocument();
    });

    it('offers signing in again when the host session expired under a working app', async () => {
        const view = renderScreen();
        await screen.findByRole('article', { name: /Welcome pack/ });
        view.harness.sessionTracker.observe(200);
        view.harness.sessionTracker.observe(401);
        await waitFor(() => {
            expect(screen.queryByRole('article', { name: /Welcome pack/ })).not.toBeInTheDocument();
        });
    });

    it('reaches the editor from the keyboard alone', async () => {
        const user = userEvent.setup();
        const navigate = vi.fn();
        renderScreen({ navigate });
        await screen.findByRole('article', { name: /Welcome pack/ });
        const open = screen.getAllByRole('button', { name: 'Create automation' })[0] as HTMLElement;
        open.focus();
        expect(open).toHaveFocus();
        await user.keyboard('{Enter}');
        expect(navigate).toHaveBeenCalledWith('/rules/new');
    });

    it('answers an unreadable hash with a named refusal rather than the list', () => {
        renderScreen({ hash: '/rules/0' });
        expect(screen.getByRole('alert')).toHaveTextContent('That rule is not on this site.');
    });
});

describe('the rule editor', () => {
    it('retains an unsaved editor when browser history requests a different automation route', async () => {
        const view = renderScreen({ hash: '/rules/12' });
        await userEvent.type(await screen.findByLabelText('Title'), ' unsaved');
        view.rerender(<AutomationsScreen runtime={{ ...view.runtime, hash: '/' }} />);
        expect(await screen.findByRole('dialog')).toHaveTextContent('Leave with unsaved changes?');
        expect(view.runtime.replace).toHaveBeenCalledWith('/rules/12');
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Title')).toHaveValue('Welcome pack unsaved');
        expect(view.harness.requests.some((request) => request.method !== 'GET')).toBe(false);
    });
    it('returns to the filtered library after editing a rule', async () => {
        const view = renderScreen({ hash: '/?search=Welcome&state=disabled&page=1' });
        await screen.findByRole('article', { name: /Welcome pack/ });
        view.rerender(<AutomationsScreen runtime={{ ...view.runtime, hash: '/rules/12' }} />);
        await screen.findByLabelText('Title');
        await userEvent.click(screen.getByRole('button', { name: 'Back to rules' }));
        expect(view.navigate).toHaveBeenCalledWith('/?search=Welcome&state=disabled&page=1');
    });
    it('blocks an empty nested condition group, then saves its populated logic and parameters', async () => {
        const { harness } = renderScreen({ hash: '/rules/12' });
        await screen.findByLabelText('Title');
        await userEvent.click(screen.getAllByRole('button', { name: 'Add condition group' })[0]!);
        await userEvent.click(screen.getByRole('button', { name: 'Save rule' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Add a condition inside each group');
        expect(harness.requests.some((request) => request.method === 'PATCH')).toBe(false);
        const group = screen
            .getByLabelText('Match conditions condition-0')
            .closest('.vy-auto-condition-group')! as HTMLElement;
        await userEvent.selectOptions(within(group).getByLabelText('Match conditions condition-0'), 'or');
        await userEvent.selectOptions(within(group).getByLabelText('Add a condition'), 'completion');
        await userEvent.type(within(group).getByRole('spinbutton', { name: 'Course' }), '42');
        await userEvent.click(screen.getByRole('button', { name: 'Save rule' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PATCH')?.body).toMatchObject({
                conditions: { op: 'and', c: [{ op: 'or', c: [{ type: 'completion', payload: { course_id: 42 } }] }] },
            }),
        );
    });
    it('preserves nested conditions, step guards, owners, delays, and payload versions on a normal title edit', () => {
        const rule = makeRule();
        rule.conditions = { op: 'or', c: [{ op: 'not_or', c: [{ type: 'completion', payload: { course_id: 4 } }] }] };
        rule.actions[0] = {
            ...rule.actions[0]!,
            params_version: 3,
            guard_conditions_version: 2,
            delay_anchor_key: 'relative',
            delay: { seconds: 3600 },
            delay_version: 4,
            owner_user_id: 92,
            guard_conditions: { op: 'and', c: [{ type: 'completion', payload: { course_id: 8 } }] },
        };
        const body = bodyOf({ ...draftFrom(rule, CATALOGUE), title: 'Updated welcome' }, rule.version);
        expect(body.conditions).toEqual(rule.conditions);
        expect(body.actions?.[0]).toMatchObject({
            params_version: 3,
            guard_conditions_version: 2,
            delay: { seconds: 3600 },
            delay_version: 4,
            owner_user_id: 92,
            guard_conditions: rule.actions[0].guard_conditions,
        });
    });
    it('retains the originally edited version after a background refresh', async () => {
        const rules = [makeRule()];
        const { harness } = renderScreen({ hash: '/rules/12', rules });
        await userEvent.type(await screen.findByLabelText('Title'), ' edited');
        rules[0] = makeRule({ version: 2 });
        await act(() => harness.queryClient.invalidateQueries({ queryKey: automationKeys.rule(12) }));
        await userEvent.click(screen.getByRole('button', { name: 'Save rule' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PATCH')?.body).toMatchObject({
                expected_version: 1,
                title: 'Welcome pack edited',
            }),
        );
    });
    it('reuses the idempotency key when retrying an unchanged failed save', async () => {
        const { harness } = renderScreen({ hash: '/rules/12', saveStatus: 503 });
        await screen.findByLabelText('Title');
        await userEvent.click(screen.getByRole('button', { name: 'Save rule' }));
        await screen.findByText('The rule was not saved.');
        await userEvent.click(screen.getByRole('button', { name: 'Save rule' }));
        await waitFor(() => expect(harness.requests.filter((request) => request.method === 'PATCH')).toHaveLength(2));
        const requests = harness.requests.filter((request) => request.method === 'PATCH');
        expect(requests[0]?.headers['idempotency-key']).toBe(requests[1]?.headers['idempotency-key']);
    });
    it('keeps unsaved changes out of arming and rehearsal and asks before leaving the editor', async () => {
        const { navigate, harness } = renderScreen({ hash: '/rules/12' });
        await userEvent.type(await screen.findByLabelText('Title'), ' changed');
        expect(screen.getByRole('button', { name: 'Arm this rule' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Rehearse' }));
        expect(screen.queryByRole('button', { name: 'Preview' })).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Back to rules' }));
        expect(await screen.findByRole('dialog')).toHaveTextContent('Leave with unsaved changes?');
        expect(navigate).not.toHaveBeenCalled();
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(harness.requests.filter((request) => request.method !== 'GET')).toHaveLength(0);
    });
    it('builds a rule as sentences and posts it with a required idempotency key', async () => {
        const user = userEvent.setup();
        const { harness } = renderScreen({ hash: '/rules/new' });
        await screen.findByLabelText('Trigger');

        await user.type(screen.getByLabelText('Title'), 'Welcome pack');
        await user.selectOptions(screen.getByLabelText('Trigger'), 'vidyalms.trigger.enrolled');
        expect(screen.getByText('When a learner is enrolled')).toBeInTheDocument();

        await user.selectOptions(screen.getByLabelText('Add an action'), 'enrol');
        await user.selectOptions(screen.getByLabelText('Add an action'), 'emit_event');
        expect(screen.getByText('Step 1: enrol them in a course')).toBeInTheDocument();
        expect(screen.getByText('Step 2: Emit an event')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Remove this step' })).toHaveLength(2);

        await user.click(screen.getByRole('button', { name: 'Save rule' }));
        await waitFor(() => {
            expect(
                harness.requests.some(
                    (request) => request.method === 'POST' && request.path === '/v1/manage/automation-rules',
                ),
            ).toBe(true);
        });
        const post = harness.requests.find(
            (request) => request.method === 'POST' && request.path === '/v1/manage/automation-rules',
        );
        expect(post?.headers['idempotency-key']).toMatch(/.+/);
        const body = post?.body as { title: string; trigger_key: string; actions: { action_key: string }[] };
        expect(body.title).toBe('Welcome pack');
        expect(body.trigger_key).toBe('vidyalms.trigger.enrolled');
        expect(body.actions.map((action) => action.action_key)).toEqual(['enrol', 'emit_event']);
    });

    it('reorders the steps, because order is the semantics and not a display preference', async () => {
        const user = userEvent.setup();
        const { harness } = renderScreen({ hash: '/rules/new' });
        await screen.findByLabelText('Trigger');
        await user.type(screen.getByLabelText('Title'), 'Two steps');
        await user.selectOptions(screen.getByLabelText('Trigger'), 'vidyalms.trigger.enrolled');
        await user.selectOptions(screen.getByLabelText('Add an action'), 'enrol');
        await user.selectOptions(screen.getByLabelText('Add an action'), 'emit_event');
        await user.click(screen.getAllByRole('button', { name: 'Move this step earlier' })[1] as HTMLElement);
        await user.click(screen.getByRole('button', { name: 'Save rule' }));
        await waitFor(() => {
            const post = harness.requests.find(
                (request) => request.method === 'POST' && request.path === '/v1/manage/automation-rules',
            );
            const body = post?.body as { actions: { action_key: string }[] } | undefined;
            expect(body?.actions.map((action) => action.action_key)).toEqual(['emit_event', 'enrol']);
        });
    });

    it('refuses a cooldown of zero on a rule that emits an event, in the builder', async () => {
        const user = userEvent.setup();
        renderScreen({ hash: '/rules/new' });
        await screen.findByLabelText('Trigger');
        await user.selectOptions(screen.getByLabelText('Add an action'), 'emit_event');
        await user.click(screen.getByRole('button', { name: 'Run settings' }));
        const cooldown = screen.getByLabelText(/Cooldown, in seconds/);
        await user.clear(cooldown);
        await user.type(cooldown, '0');
        expect(
            screen.getByText(
                'A rule that emits an event may not run at a cooldown of zero, because two such rules can drive each other forever.',
            ),
        ).toBeInTheDocument();
    });

    it('names the pair of policies that cancel each other rather than accepting them', async () => {
        const user = userEvent.setup();
        renderScreen({ hash: '/rules/new' });
        await screen.findByLabelText('Trigger');
        await user.selectOptions(screen.getByLabelText('Add an action'), 'enrol');
        await user.click(screen.getByText('Timing, conditions & recovery'));
        await user.selectOptions(screen.getByLabelText('Retry ladder'), 'external_default');
        await user.selectOptions(screen.getByLabelText('On failure'), 'continue');
        expect(
            screen.getByText(
                'This failure policy cancels the retry ladder. Choose a ladder of one attempt, or a policy that does not continue.',
            ),
        ).toBeInTheDocument();
    });

    it('says an irreversible action parks by default when it is added', async () => {
        const user = userEvent.setup();
        renderScreen({ hash: '/rules/new' });
        await screen.findByLabelText('Trigger');
        await user.selectOptions(screen.getByLabelText('Add an action'), 'emit_event');
        expect(
            screen.getByText('This action cannot be undone, so parking is the default failure policy.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('On failure')).toHaveValue('park');
    });

    it('arms a rule behind a confirmation that carries the version and the acknowledgement', async () => {
        const user = userEvent.setup();
        const { harness } = renderScreen({ hash: '/rules/12' });
        await screen.findByRole('button', { name: 'Arm this rule' });
        await user.click(screen.getByRole('button', { name: 'Arm this rule' }));
        await user.click(screen.getByRole('button', { name: 'Yes, arm it' }));
        await waitFor(() => {
            const post = harness.requests.find((request) => request.path.endsWith('/arm'));
            expect(post?.body).toEqual({
                expected_version: 1,
                acknowledgement: 'this_rule_may_act_on_people',
            });
            expect(post?.headers['idempotency-key']).toMatch(/.+/);
        });
    });

    it('offers disarming instead once the rule is armed', async () => {
        const user = userEvent.setup();
        const { harness } = renderScreen({
            hash: '/rules/12',
            rules: [makeRule({ armed_by: 7, armed_at: '2026-09-02 09:00:00', state: 'enabled' })],
        });
        await screen.findByRole('button', { name: 'Disarm' });
        await user.click(screen.getByRole('button', { name: 'Disarm' }));
        await waitFor(() => {
            expect(harness.requests.some((request) => request.path.endsWith('/disarm'))).toBe(true);
        });
    });

    it('tells the author to reload rather than overwriting somebody else', async () => {
        const user = userEvent.setup();
        renderScreen({ hash: '/rules/12', saveStatus: 409 });
        await screen.findByLabelText('Title');
        await user.click(screen.getByRole('button', { name: 'Save rule' }));
        expect(
            await screen.findByText(
                'Somebody else saved this rule while you were editing. Reload it and apply your change again.',
            ),
        ).toBeInTheDocument();
    });

    it('rehearses on the same rule and reports the subjects and the warnings', async () => {
        const user = userEvent.setup();
        const { harness } = renderScreen({ hash: '/rules/12' });
        await user.click(await screen.findByRole('button', { name: 'Rehearse' }));
        await screen.findByRole('button', { name: 'Preview' });
        await user.type(screen.getByLabelText(/Subject ids to rehearse for/), '482, 900');
        await user.click(screen.getByRole('button', { name: 'Preview' }));
        expect(await screen.findByText('2 subjects would be touched.')).toBeInTheDocument();
        expect(screen.getByText('Would run')).toBeInTheDocument();
        expect(screen.getByText('One subject has no email address.')).toBeInTheDocument();
        const post = harness.requests.find((request) => request.path.endsWith('/preview'));
        expect(post?.headers['idempotency-key']).toMatch(/.+/);
    });

    it('stops the rule and cancels pending work behind a confirmation without promising to remove its definition', async () => {
        const user = userEvent.setup();
        const navigate = vi.fn();
        const { harness } = renderScreen({ hash: '/rules/12', navigate });
        await screen.findByRole('button', { name: 'Stop & cancel queued work' });
        await user.click(screen.getByRole('button', { name: 'Stop & cancel queued work' }));
        expect(screen.getByRole('dialog')).toHaveTextContent('The rule stays in your library');
        await user.click(screen.getByRole('button', { name: 'Stop automation' }));
        await waitFor(() => {
            expect(harness.requests.some((request) => request.method === 'DELETE')).toBe(true);
            expect(navigate).toHaveBeenCalledWith('/');
        });
    });

    it('says a rule is gone rather than showing an empty form', async () => {
        renderScreen({ hash: '/rules/12', ruleStatus: 404 });
        expect(await screen.findByText('That rule is not on this site.')).toBeInTheDocument();
    });

    it('reports a vocabulary it could not load, because a builder with no registry is not an empty builder', async () => {
        renderScreen({ hash: '/rules/new', catalogueStatus: 500 });
        expect(await screen.findByText('The automation vocabulary could not be loaded.')).toBeInTheDocument();
    });
});

describe('the runs view and its action log', () => {
    it('lists the runs with their cascade depth and four counters', async () => {
        renderScreen({ hash: '/rules/12/runs' });
        const row = await screen.findByRole('row', { name: /Completed/ });
        expect(within(row).getByText('1 / 1 / 0 / 0')).toBeInTheDocument();
        expect(within(row).getByText('user #482')).toBeInTheDocument();
    });

    it('marks a rehearsal apart from a real run', async () => {
        renderScreen({ hash: '/rules/12/runs', runs: [makeRun({ dry_run: true })] });
        expect(await screen.findByText('Rehearsal')).toBeInTheDocument();
    });

    it('shows the empty state when a rule has never run', async () => {
        renderScreen({ hash: '/rules/12/runs', runs: [] });
        expect(await screen.findByText('This rule has not run yet.')).toBeInTheDocument();
    });

    it('opens one run action log, naming the identity the step ran under', async () => {
        renderScreen({ hash: '/rules/12/runs/44' });
        const row = await screen.findByRole('row', { name: /Enrol/ });
        expect(within(row).getByText('Succeeded')).toBeInTheDocument();
        expect(within(row).getByText('#1')).toBeInTheDocument();
    });

    it('shows a failed step with its error code', async () => {
        renderScreen({
            hash: '/rules/12/runs/44',
            log: [makeLogEntry({ success: false, error_code: 'enrolment.refused' })],
        });
        expect(await screen.findByText('Failed')).toBeInTheDocument();
        expect(screen.getByText('enrolment.refused')).toBeInTheDocument();
    });

    it('shows the empty action log of a run that executed nothing', async () => {
        renderScreen({ hash: '/rules/12/runs/44', log: [] });
        expect(await screen.findByText('This run executed no step.')).toBeInTheDocument();
    });
});
