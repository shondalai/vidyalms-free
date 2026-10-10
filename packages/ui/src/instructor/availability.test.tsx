import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { resetFreeConditionEditors } from './tabs/availability/free-editors';
import { SubjectRestrictions } from './tabs/availability/subject-restrictions';
import { GrantsPanel } from './tabs/availability/grants-panel';
import { jsonResponse, makeHarness, noContent, type RecordedRequest } from './testing/harness';

/*
 * Authoring a restriction.
 *
 * Add restriction offers NAMED PRESETS rather than a raw condition list,
 * because a raw list and nothing else is why relative-date release needs a
 * plugin and a forum thread in the product this grammar comes from. What a
 * preset produces is an ordinary leaf, which is what keeps the simple path
 * and the general one the same data.
 *
 * The palette itself comes from the contract and is filtered to the surface
 * being authored, so a condition a package adds appears with no change here,
 * and one that is not applicable to content never appears at all.
 */

const CATALOGUE = {
    conditions: [
        {
            key: 'date',
            label: 'Date',
            group: 'schedule',
            order: 10,
            applicability: ['content_gating'],
            edition: 'free',
            available: true,
            advisory: false,
            schema_version: 1,
            params_schema: {},
        },
        {
            key: 'completion',
            label: 'Completion of another item',
            group: 'progress',
            order: 20,
            applicability: ['content_gating'],
            edition: 'free',
            available: true,
            advisory: false,
            schema_version: 1,
            params_schema: {},
        },
        {
            key: 'acme.forum_posts',
            label: 'Forum posts',
            group: 'progress',
            order: 30,
            applicability: ['content_gating'],
            edition: 'pro',
            available: true,
            advisory: true,
            schema_version: 1,
            params_schema: {
                type: 'object',
                properties: { minimum: { type: 'integer', title: 'Posts required' } },
            },
        },
    ],
    anchors: [{ key: 'absolute', label: 'A fixed instant', edition: 'free', available: true }],
    presets: [
        {
            key: 'available_on_date',
            label: 'Available on a date',
            condition_key: 'date',
            fields: [{ key: 'from', label: 'Opens', type: 'datetime' }],
        },
    ],
    default_visibility: 'shown',
};

const CURRICULUM = {
    course_id: 1,
    modules: [
        {
            id: 1,
            course_id: 1,
            title: 'Getting started',
            summary: null,
            position: 1,
            state: 'published',
            lessons: [
                {
                    id: 11,
                    course_id: 1,
                    module_id: 1,
                    title: 'Welcome',
                    slug: 'welcome',
                    type: 'video',
                    summary: null,
                    duration_seconds: null,
                    position: 1,
                    state: 'published',
                    free_preview: false,
                    content: {},
                    content_available: true,
                    quiz: null,
                    assignment: null,
                },
            ],
        },
    ],
};

interface World {
    stored?: unknown;
    catalogue?: unknown;
    saves: RecordedRequest[];
}

function renderBox(world: World, subjectId = 12) {
    resetFreeConditionEditors();
    const harness = makeHarness((request: RecordedRequest) => {
        const path = request.path.split('?')[0] ?? '';
        if (request.method === 'GET' && path === '/v1/manage/availability/conditions') {
            return jsonResponse(world.catalogue ?? CATALOGUE);
        }
        if (request.method === 'GET' && path === `/v1/manage/modules/${subjectId}/availability`) {
            return jsonResponse(
                world.stored ?? {
                    subject_type: 'module',
                    subject_id: subjectId,
                    surface: 'content_gating',
                    tree: null,
                    rule_version: 1,
                    default_visibility: 'shown',
                    updated_at: null,
                },
            );
        }
        if (request.method === 'PUT' && path === `/v1/manage/modules/${subjectId}/availability`) {
            world.saves.push(request);
            return jsonResponse({
                subject_type: 'module',
                subject_id: subjectId,
                surface: 'content_gating',
                tree: (request.body as { tree?: unknown }).tree ?? null,
                rule_version: 1,
                default_visibility: 'shown',
                updated_at: '2026-09-04 09:00:00',
                warnings: [],
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/courses/1/curriculum') {
            return jsonResponse(CURRICULUM);
        }
        return null;
    });
    render(<SubjectRestrictions subjectType="module" subjectId={subjectId} courseId={1} />, {
        wrapper: harness.wrapper,
    });
    return harness;
}

describe('the restriction box', () => {
    it('offers presets and conditions in one picker with the declared group order', async () => {
        renderBox({ saves: [] });
        const picker = await screen.findByRole('combobox', { name: 'Add restriction' });
        expect(screen.getAllByRole('combobox')).toHaveLength(1);
        expect(within(picker).getByRole('option', { name: 'Available on a date' })).toHaveValue('preset:available_on_date');
        expect(within(picker).getByRole('option', { name: 'Date' })).toHaveValue('condition:date');
        expect(within(picker).getByRole('option', { name: 'Forum posts' })).toHaveValue('condition:acme.forum_posts');
        expect(within(picker).getAllByRole('group').map((group) => group.getAttribute('label'))).toEqual([
            'Quick setup', 'Dates and schedule', 'Progress and grades',
        ]);
        expect(within(within(picker).getByRole('group', { name: 'Progress and grades' }))
            .getAllByRole('option').map((option) => option.textContent)).toEqual([
            'Completion of another item', 'Forum posts',
        ]);
    });

    it('keeps an unrestricted item free of irrelevant combination, visibility and save controls', async () => {
        renderBox({ saves: [] });
        await screen.findByRole('combobox', { name: 'Add restriction' });

        expect(screen.queryByRole('combobox', { name: 'How these combine' })).not.toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'When a learner does not meet this' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save restrictions' })).not.toBeInTheDocument();
        expect(screen.getByText('Advanced rules').closest('details')).not.toHaveAttribute('open');
    });

    it('still offers conditions when the catalogue has no quick setup presets', async () => {
        renderBox({ saves: [], catalogue: { ...CATALOGUE, presets: [] } });
        const picker = await screen.findByRole('combobox', { name: 'Add restriction' });

        expect(screen.getAllByRole('combobox')).toHaveLength(1);
        expect(within(picker).queryByRole('group', { name: 'Quick setup' })).not.toBeInTheDocument();
        await userEvent.selectOptions(picker, 'condition:date');
        expect(await screen.findByLabelText('Available from')).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Done' })).toHaveAttribute('aria-expanded', 'true');
    });

    it('omits unavailable conditions and presets from new restrictions', async () => {
        renderBox({
            saves: [],
            catalogue: {
                ...CATALOGUE,
                conditions: CATALOGUE.conditions.map((condition) => ({
                    ...condition,
                    available: condition.key !== 'acme.forum_posts',
                })),
                presets: CATALOGUE.presets.map((preset) => ({ ...preset, available: false })),
            },
        });
        const picker = await screen.findByRole('combobox', { name: 'Add restriction' });

        expect(within(picker).queryByRole('option', { name: 'Forum posts' })).not.toBeInTheDocument();
        expect(within(picker).queryByRole('option', { name: 'Available on a date' })).not.toBeInTheDocument();
        expect(within(picker).getByRole('option', { name: 'Date' })).toBeInTheDocument();
    });

    it('cancels a preset draft without creating restrictions or pending changes', async () => {
        const world: World = { saves: [] };
        renderBox(world);
        const user = userEvent.setup();

        await user.selectOptions(
            await screen.findByRole('combobox', { name: 'Add restriction' }),
            'preset:available_on_date',
        );
        expect(await screen.findByLabelText('Opens')).toHaveFocus();
        await user.type(await screen.findByLabelText('Opens'), '2026-03-03 09:00:00');
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.getByRole('combobox', { name: 'Add restriction' })).toHaveValue('');
        expect(screen.queryByLabelText('Opens')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save restrictions' })).not.toBeInTheDocument();
        expect(world.saves).toHaveLength(0);
    });

    it('compiles a preset into an ordinary leaf and sends the whole tree in one write', async () => {
        const world: World = { saves: [] };
        renderBox(world);
        const user = userEvent.setup();

        await user.selectOptions(
            await screen.findByRole('combobox', { name: 'Add restriction' }),
            'preset:available_on_date',
        );
        await user.type(await screen.findByLabelText('Opens'), '2026-03-03 09:00:00');
        await user.click(screen.getByRole('button', { name: 'Add restriction' }));
        await user.click(await screen.findByRole('button', { name: 'Save restrictions' }));

        await waitFor(() => expect(world.saves).toHaveLength(1));
        expect(world.saves[0]?.body).toMatchObject({
            default_visibility: 'shown',
            tree: {
                op: 'and',
                showc: [true],
                c: [{ type: 'date', preset: 'available_on_date', payload: { from: '2026-03-03 09:00:00' } }],
            },
        });
    });

    it('clears the rule with a null tree rather than storing a shell that gates nothing', async () => {
        const world: World = {
            saves: [],
            stored: {
                subject_type: 'module',
                subject_id: 12,
                surface: 'content_gating',
                tree: { op: 'and', showc: [true], c: [{ type: 'date', payload: { from: '2026-03-03 09:00:00' } }] },
                rule_version: 1,
                default_visibility: 'shown',
                updated_at: '2026-09-01 09:00:00',
            },
        };
        renderBox(world);
        const user = userEvent.setup();

        await user.click(await screen.findByRole('button', { name: 'Remove all restrictions' }));
        await user.click(screen.getByRole('button', { name: 'Save restrictions' }));

        await waitFor(() => expect(world.saves).toHaveLength(1));
        expect((world.saves[0]?.body as { tree: unknown }).tree).toBeNull();
    });

    it('keeps saving available after removing the last restriction row', async () => {
        const world: World = {
            saves: [],
            stored: {
                subject_type: 'module',
                subject_id: 12,
                surface: 'content_gating',
                tree: { op: 'and', showc: [true], c: [{ type: 'date', payload: { from: '2026-03-03 09:00:00' } }] },
                rule_version: 1,
                default_visibility: 'shown',
                updated_at: null,
            },
        };
        renderBox(world);
        const user = userEvent.setup();

        await user.click(await screen.findByRole('button', { name: /^Remove$/ }));
        expect(screen.queryByRole('combobox', { name: 'How these combine' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save restrictions' })).toBeEnabled();
        await user.click(screen.getByRole('button', { name: 'Save restrictions' }));

        await waitFor(() => expect(world.saves).toHaveLength(1));
        expect((world.saves[0]?.body as { tree: unknown }).tree).toBeNull();
    });

    it('authors a nested negated set through advanced rules and preserves its tree on save', async () => {
        const world: World = { saves: [] };
        renderBox(world);
        const user = userEvent.setup();

        await user.click(await screen.findByText('Advanced rules'));
        await user.click(screen.getByRole('button', { name: 'Add restriction set' }));
        const set = screen.getByRole('listitem');
        await user.selectOptions(within(set).getByRole('combobox', { name: 'Add restriction' }), 'condition:date');
        await user.type(await within(set).findByLabelText('Available from'), '2026-03-03 09:00:00');
        await user.selectOptions(within(set).getByRole('combobox', { name: 'How these combine' }), 'not_and');
        await user.selectOptions(screen.getByRole('combobox', { name: 'When a learner does not meet this' }), 'hidden');
        await user.click(screen.getByRole('button', { name: 'Save restrictions' }));

        await waitFor(() => expect(world.saves).toHaveLength(1));
        expect(world.saves[0]?.body).toEqual({
            default_visibility: 'hidden',
            tree: {
                op: 'and',
                showc: [true],
                c: [{
                    op: 'not_and',
                    show: true,
                    c: [{ type: 'date', payload: { from: '2026-03-03 09:00:00' } }],
                }],
            },
        });
    });

    it('draws a condition nobody shipped an editor for from its own declared schema', async () => {
        renderBox({ saves: [] });
        const user = userEvent.setup();

        await user.selectOptions(
            await screen.findByRole('combobox', { name: 'Add restriction' }),
            'condition:acme.forum_posts',
        );

        /* The schema said "Posts required"; nothing in this bundle has heard
           of the condition, which is the whole point of the fallback. */
        expect(await screen.findByLabelText('Posts required')).toBeInTheDocument();
        /* A condition reading a learner-editable value is marked as advisory,
           so nobody builds a paywall on one. */
        expect(screen.getByText(/not a security control/)).toBeInTheDocument();
    });

    it('shows a stored leaf whose provider is missing rather than dropping it on the next save', async () => {
        const world: World = {
            saves: [],
            stored: {
                subject_type: 'module',
                subject_id: 12,
                surface: 'content_gating',
                tree: { op: 'and', showc: [true], c: [{ type: 'gone.condition', payload: { a: 1 } }] },
                rule_version: 1,
                default_visibility: 'shown',
                updated_at: null,
            },
        };
        renderBox(world);
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Edit' }));
        expect(await screen.findByText(/is not installed/)).toBeInTheDocument();
        await user.selectOptions(screen.getByRole('combobox', { name: 'When a learner does not meet this' }), 'hidden');
        await user.click(screen.getByRole('button', { name: 'Save restrictions' }));

        await waitFor(() => expect(world.saves).toHaveLength(1));
        expect(world.saves[0]?.body).toEqual({
            default_visibility: 'hidden',
            tree: { op: 'and', showc: [true], c: [{ type: 'gone.condition', payload: { a: 1 } }] },
        });
    });

    it('carries the per-child visibility flag under and, and one flag under or', async () => {
        renderBox({
            saves: [],
            stored: {
                subject_type: 'module',
                subject_id: 12,
                surface: 'content_gating',
                tree: {
                    op: 'and',
                    showc: [false],
                    c: [{ type: 'date', payload: { from: '2026-03-03 09:00:00' } }],
                },
                rule_version: 1,
                default_visibility: 'shown',
                updated_at: null,
            },
        });
        expect(await screen.findByRole('button', { name: /Hidden entirely/ })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });
});

describe('individual exceptions', () => {
    it('prints the order of precedence and warns that an open-ended closure excuses the learner', async () => {
        const harness = makeHarness((request: RecordedRequest) => {
            const path = request.path.split('?')[0] ?? '';
            if (request.method === 'GET' && path === '/v1/manage/courses/1/availability/grants') {
                return jsonResponse({
                    items: [],
                    total: 0,
                    page: 1,
                    per_page: 25,
                    scope_order: ['user', 'cohort', 'course', 'site'],
                });
            }
            return null;
        });
        render(<GrantsPanel courseId={1} targets={[{ kind: 'lesson', id: 11, title: 'Welcome' }]} />, {
            wrapper: harness.wrapper,
        });

        expect(await screen.findByText(/user, cohort, course, site/)).toBeInTheDocument();

        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: 'Add an exception' }));
        await user.selectOptions(await screen.findByLabelText('Effect'), 'deny');
        expect(await screen.findByRole('status')).toHaveTextContent(/removes this item from the completion total/);
    });

    it('revokes an exception through the contract rather than editing it in place', async () => {
        const deletes: RecordedRequest[] = [];
        const harness = makeHarness((request: RecordedRequest) => {
            const path = request.path.split('?')[0] ?? '';
            if (request.method === 'GET' && path === '/v1/manage/courses/1/availability/grants') {
                return jsonResponse({
                    items: [
                        {
                            id: 5,
                            scope_type: 'course',
                            scope_id: 1,
                            subject_type: 'lesson',
                            subject_id: 11,
                            user_id: 42,
                            learner: { id: 42, name: 'Priya Nair' },
                            mode: 'deny',
                            reason: 'Excused',
                            granted_by: 7,
                            expires_at: null,
                            created_at: '2026-09-01 09:00:00',
                            active: true,
                            excludes_from_completion: true,
                        },
                    ],
                    total: 1,
                    page: 1,
                    per_page: 25,
                });
            }
            if (request.method === 'DELETE' && path === '/v1/manage/availability/grants/5') {
                deletes.push(request);
                return noContent();
            }
            return null;
        });
        render(<GrantsPanel courseId={1} targets={[{ kind: 'lesson', id: 11, title: 'Welcome' }]} />, {
            wrapper: harness.wrapper,
        });

        expect(await screen.findByText('Priya Nair')).toBeInTheDocument();
        expect(screen.getByText('Excused from completion')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Revoke' }));
        await waitFor(() => expect(deletes).toHaveLength(1));
    });
});
