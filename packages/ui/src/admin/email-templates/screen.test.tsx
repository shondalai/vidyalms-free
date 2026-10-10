import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { components } from '@vidyalms/api-sdk';
import { EmailTemplatesScreen } from './screen';
import { notificationTemplateKeys } from './api';
import type { AdminScreenRuntime } from '../screens/registry';
import { jsonResponse, makeConfig, makeHarness, problemResponse, type RecordedRequest } from '../testing/harness';

type Schemas = components['schemas'];
type Detail = Schemas['NotificationTemplateDetail'];
type Source = Schemas['NotificationTemplateSource'];

const SOURCE: Source = {
    subject: 'You completed {course.title}',
    preheader: 'Your certificate is ready',
    heading: 'Well done, {learner.name}',
    paragraphs: ['You completed {course.title}.'],
    action_label: 'View certificate',
    action_url: '{certificate.url}',
    footer: 'Sent by {site.name}',
    logo_url: '{site.logo_url}',
    logo_alt: 'VidyaLMS',
    accent_color: '#3157d5',
    short_body: 'Course completed',
};

function detail(overrides: Partial<Detail> = {}): Detail {
    return {
        id: 7,
        template_key: 'course.completed',
        locale: 'en-GB',
        channel_key: 'email',
        body_version: 3,
        state: 'published',
        status: 'published',
        draft_revision: 4,
        has_draft: false,
        trigger_key: 'course.completed',
        label: 'Course completed',
        category_key: 'learning',
        class: 'transactional',
        available: true,
        unavailable_reason: null,
        current_blueprint_version: 1,
        published_blueprint_version: 1,
        update_available: false,
        delivery_ready: true,
        delivery_warnings: [],
        published_source: SOURCE,
        draft_source: null,
        default_source: SOURCE,
        draft_base_version: null,
        draft_origin: null,
        published_at: '2026-09-05 12:00:00',
        draft_updated_at: null,
        legacy_published: false,
        blueprint_version: 1,
        generic_blueprint: false,
        tokens: [
            { key: 'course.title', type: 'text', required: true, sample: 'Inclusive leadership' },
            { key: 'learner.name', type: 'text', required: true, sample: 'Asha Rao' },
            { key: 'certificate.url', type: 'url', required: false, sample: 'https://example.test/c/1' },
            { key: 'site.name', type: 'text', required: false, sample: 'VidyaLMS' },
            { key: 'site.logo_url', type: 'url', required: false, sample: 'https://example.test/logo.png' },
        ],
        channel_capabilities: ['html', 'text'],
        resolved_locale: 'en-GB',
        using_fallback: false,
        live_parts: {
            subject: 'You completed Inclusive leadership',
            body_text: 'Well done, Asha Rao',
            body_html: '<!doctype html><html><body><h1>Well done, Asha Rao</h1></body></html>',
            body_short: 'Course completed',
        },
        ...overrides,
    };
}

function route(request: RecordedRequest): Response | null {
    const url = new URL(request.path, 'http://localhost');
    if (request.method === 'GET' && url.pathname === '/v1/manage/notifications/catalogue') {
        return jsonResponse({
            triggers: [{ key: 'course.completed', label: 'Course completed' }],
            channels: [
                { key: 'email', label: 'Email' },
                { key: 'sms', label: 'SMS' },
            ],
            categories: [],
        });
    }
    if (request.method === 'GET' && url.pathname === '/v1/manage/notifications/templates') {
        const status = url.searchParams.get('status');
        const totals: Record<string, number> = { published: 8, draft_changes: 2, draft_only: 1 };
        if (url.searchParams.get('per_page') === '1') {
            return jsonResponse({
                items: [],
                total: status === null ? 11 : (totals[status] ?? 0),
                page: 1,
                per_page: 1,
            });
        }
        return jsonResponse({
            items: [
                {
                    id: 7,
                    template_key: 'course.completed',
                    locale: 'en-GB',
                    channel_key: 'email',
                    state: 'published',
                    status: 'published',
                    has_draft: false,
                    label: 'Course completed',
                    trigger_key: 'course.completed',
                    available: true,
                    unavailable_reason: null,
                    current_blueprint_version: 1,
                    published_blueprint_version: 1,
                    update_available: false,
                    delivery_ready: false,
                    delivery_warnings: [
                        {
                            code: 'public_url_https',
                            message: 'Configure an HTTPS public site URL before live email delivery.',
                            blocking: true,
                        },
                    ],
                },
            ],
            total: 30,
            page: Number(url.searchParams.get('page') ?? 1),
            per_page: 18,
        });
    }
    if (
        request.method === 'GET' &&
        url.pathname === '/v1/manage/notifications/templates/course.completed/en-GB/email'
    ) {
        return jsonResponse(detail());
    }
    if (request.method === 'POST' && url.pathname.endsWith('/preview')) {
        return jsonResponse({
            subject: 'You completed Inclusive leadership',
            body_text: 'Synthetic learner completed the sample course.',
            body_html: '<!doctype html><html><body><h1>Synthetic sample</h1></body></html>',
            body_short: 'Completed',
            locale: 'en-GB',
            sample_data: true,
            used_tokens: ['course.title'],
            unresolved_tokens: [],
            class: 'transactional',
            delivery_ready: false,
            delivery_warnings: [
                {
                    code: 'public_url_https',
                    message: 'This local HTTP site does not provide safe public links.',
                    blocking: true,
                },
            ],
        });
    }
    return null;
}

function renderScreen(hash: string, handler: (request: RecordedRequest) => Response | null = route) {
    const config = makeConfig({
        screen: 'email-templates',
        urls: {
            ...makeConfig().urls,
            screens: { 'email-templates': '/admin/email-templates' },
        },
    });
    const harness = makeHarness(handler, config);
    const navigate = vi.fn();
    const replace = vi.fn();
    const node = (currentHash: string) => (
        <EmailTemplatesScreen
            runtime={{
                config,
                client: harness.client,
                hash: currentHash,
                navigate,
                replace,
                mountEl: null,
            }}
        />
    );
    const view = render(node(hash), { wrapper: harness.wrapper });
    return {
        ...harness,
        navigate,
        replace,
        view,
        rerenderHash: async (next: string) => {
            view.rerender(node(next));
            await Promise.resolve();
        },
    };
}

function renderNavigableScreen(initialHash: string, handler: (request: RecordedRequest) => Response | null = route) {
    const config = makeConfig({
        screen: 'email-templates',
        urls: {
            ...makeConfig().urls,
            screens: { 'email-templates': '/admin/email-templates' },
        },
    });
    const harness = makeHarness(handler, config);
    const navigations: string[] = [];
    const replacements: string[] = [];
    let currentHash = initialHash;

    const updateBrowserHash = (next: string, replace: boolean) => {
        const url = new URL(window.location.href);
        url.hash = next;
        window.history[replace ? 'replaceState' : 'pushState'](window.history.state, '', url);
    };

    const initialUrl = new URL(window.location.href);
    initialUrl.hash = initialHash;
    window.history.replaceState(window.history.state, '', initialUrl);
    function Host() {
        const [hash, setHash] = useState(initialHash);
        const move = (next: string, replace: boolean) => {
            currentHash = next;
            updateBrowserHash(next, replace);
            setHash(next);
        };
        const runtime: AdminScreenRuntime = {
            config,
            client: harness.client,
            hash,
            navigate: (next) => {
                navigations.push(next);
                move(next, false);
            },
            replace: (next) => {
                replacements.push(next);
                move(next, true);
            },
            mountEl: null,
        };
        return <EmailTemplatesScreen runtime={runtime} />;
    }

    const view = render(<Host />, { wrapper: harness.wrapper });
    return {
        ...harness,
        view,
        navigations,
        replacements,
        hash: () => currentHash,
    };
}

beforeEach(() => {
    window.location.hash = '';
});

describe('Email template studio', () => {
    it('uses the actual template source and declared sample tokens for visual cards', async () => {
        let enterViewport = () => {};
        vi.stubGlobal(
            'IntersectionObserver',
            class {
                constructor(callback: (entries: { isIntersecting: boolean }[]) => void) {
                    enterViewport = () => callback([{ isIntersecting: true }]);
                }
                observe() {}
                disconnect() {}
            },
        );
        try {
            const harness = renderScreen('/');
            await screen.findByRole('heading', { name: 'Course completed' });
            expect(harness.requests.some((request) => request.path.endsWith('/course.completed/en-GB/email'))).toBe(
                false,
            );
            act(enterViewport);
            expect(await screen.findByText('Well done, Asha Rao')).toBeInTheDocument();
            expect(screen.getByText('You completed Inclusive leadership.')).toBeInTheDocument();
            expect(screen.getByText('Sample preview')).toBeInTheDocument();
        } finally {
            vi.unstubAllGlobals();
        }
    });
    it('preserves message order, branding, and delivery content when saving from grouped editor panels', async () => {
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            if (request.method === 'PUT' && request.path.endsWith('/draft'))
                return jsonResponse(
                    detail({
                        has_draft: true,
                        draft_revision: 5,
                        draft_source: (request.body as { source: Source }).source,
                    }),
                );
            return route(request);
        });
        await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.click(screen.getByRole('button', { name: 'Add paragraph' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Paragraph 2' }), 'An introduction.');
        await userEvent.click(screen.getByRole('button', { name: 'Move paragraph 2 earlier' }));
        await userEvent.click(screen.getByRole('button', { name: 'Branding' }));
        const footer = screen.getByRole('textbox', { name: 'Footer' });
        await userEvent.clear(footer);
        await userEvent.type(footer, 'Academy support');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PUT')?.body).toMatchObject({
                expected_revision: 4,
                source: {
                    ...SOURCE,
                    footer: 'Academy support',
                    paragraphs: ['An introduction.', SOURCE.paragraphs[0]],
                },
            }),
        );
    });
    it('refreshes a sample preview automatically without saving or delivering the message', async () => {
        const harness = renderScreen('/template/course.completed/en-GB/email');
        expect(await screen.findByTitle('Sample email preview', {}, { timeout: 2000 })).toBeInTheDocument();
        expect(
            harness.requests
                .filter((request) => request.method !== 'GET')
                .every((request) => request.path.endsWith('/preview')),
        ).toBe(true);
    });
    it('lists server-paged templates with exact status counts and filters', async () => {
        const harness = renderScreen('/');
        expect(await screen.findByRole('heading', { name: 'Course completed' })).toBeInTheDocument();
        expect(screen.getByText('8')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Published (8)' })).toBeInTheDocument();
        expect(screen.getByText('Configure an HTTPS public site URL before live email delivery.')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Draft changes (2)' }));
        await waitFor(() =>
            expect(
                harness.requests.some((request) => {
                    const url = new URL(request.path, 'http://localhost');
                    return (
                        url.searchParams.get('status') === 'draft_changes' && url.searchParams.get('per_page') === '18'
                    );
                }),
            ).toBe(true),
        );

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search templates' }), 'certificate');
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => new URL(request.path, 'http://localhost').searchParams.get('search') === 'certificate',
                ),
            ).toBe(true),
        );

        await userEvent.click(screen.getByRole('button', { name: 'Page 2' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => new URL(request.path, 'http://localhost').searchParams.get('page') === '2',
                ),
            ).toBe(true),
        );
    });

    it('restores the complete bookmarked list state and sends it to the paged query', async () => {
        const hash = '/?search=certificate&filter=draft_changes&locale=fr-CA&channel=all&page=2';
        window.location.hash = hash;
        const harness = renderScreen(hash);

        expect(await screen.findByRole('heading', { name: 'Course completed' })).toBeInTheDocument();
        expect(screen.getByRole('searchbox', { name: 'Search templates' })).toHaveValue('certificate');
        expect(screen.getByRole('textbox', { name: 'Locale' })).toHaveValue('fr-CA');
        expect(screen.getByRole('combobox', { name: 'Channel' })).toHaveValue('all');
        expect(screen.getByRole('button', { name: 'Draft changes (2)' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page');

        await waitFor(() =>
            expect(
                harness.requests.some((request) => {
                    const url = new URL(request.path, 'http://localhost');
                    return (
                        url.searchParams.get('search') === 'certificate' &&
                        url.searchParams.get('status') === 'draft_changes' &&
                        url.searchParams.get('locale') === 'fr-CA' &&
                        !url.searchParams.has('channel_key') &&
                        url.searchParams.get('page') === '2' &&
                        url.searchParams.get('per_page') === '18'
                    );
                }),
            ).toBe(true),
        );
    });

    it('opens a card through the exact nested hash route', async () => {
        const { navigate } = renderScreen('/');
        await userEvent.click(await screen.findByRole('button', { name: 'Customize Course completed' }));
        expect(navigate).toHaveBeenCalledWith('/template/course.completed/en-GB/email');
    });

    it('edits structured fields, inserts declared tokens, previews safely and saves with CAS', async () => {
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const standard = route(request);
            if (standard !== null) return standard;
            if (request.method === 'PUT' && request.path.endsWith('/draft')) {
                const body = request.body as { source: Source; expected_revision: number };
                return jsonResponse(
                    detail({
                        status: 'draft_changes',
                        has_draft: true,
                        draft_revision: 5,
                        draft_source: body.source,
                        draft_base_version: 3,
                    }),
                );
            }
            return null;
        });

        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.clear(subject);
        await userEvent.type(subject, 'Completed ');
        await userEvent.click(screen.getByRole('button', { name: 'Insert {course.title}' }));
        expect(subject).toHaveValue('Completed {course.title}');

        await userEvent.click(screen.getByRole('button', { name: 'Refresh preview' }));
        const frame = await screen.findByTitle('Sample email preview');
        expect(frame).toHaveAttribute('sandbox', '');
        expect(frame).toHaveAttribute('srcdoc', expect.stringContaining('Synthetic sample'));
        expect(screen.getByText('This local HTTP site does not provide safe public links.')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(await screen.findByText('Draft saved.')).toBeInTheDocument();
        const write = harness.requests.find((request) => request.method === 'PUT' && request.path.endsWith('/draft'));
        expect(write?.body).toMatchObject({ expected_revision: 4, source: { subject: 'Completed {course.title}' } });
        expect(write?.headers['idempotency-key']).toBeTruthy();
    });

    it('hides a preview as soon as the normalized source no longer matches it', async () => {
        renderScreen('/template/course.completed/en-GB/email');
        const subject = await screen.findByRole('textbox', { name: 'Subject' });

        await userEvent.click(screen.getByRole('button', { name: 'Refresh preview' }));
        expect(await screen.findByTitle('Sample email preview')).toHaveAttribute(
            'srcdoc',
            expect.stringContaining('Synthetic sample'),
        );

        await userEvent.type(subject, ' updated');
        expect(screen.queryByTitle('Sample email preview')).toBeNull();
        expect(screen.getByText('Rendering sample preview…')).toBeInTheDocument();
    });

    it('keeps the captured CAS revision and local source across a background cache refresh', async () => {
        const identity = { templateKey: 'course.completed', locale: 'en-GB', channelKey: 'email' };
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const standard = route(request);
            if (standard !== null) return standard;
            if (request.method === 'PUT' && request.path.endsWith('/draft')) {
                const body = request.body as { source: Source };
                return jsonResponse(
                    detail({
                        draft_revision: 5,
                        has_draft: true,
                        draft_source: body.source,
                    }),
                );
            }
            return null;
        });
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.clear(subject);
        await userEvent.type(subject, 'My unsaved subject');

        act(() => {
            harness.queryClient.setQueryData(
                notificationTemplateKeys.detail(identity),
                detail({
                    draft_revision: 91,
                    has_draft: true,
                    draft_source: { ...SOURCE, subject: 'Another manager changed this' },
                }),
            );
        });
        await waitFor(() => expect(subject).toHaveValue('My unsaved subject'));

        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(await screen.findByText('Draft saved.')).toBeInTheDocument();
        const write = harness.requests.find((request) => request.method === 'PUT' && request.path.endsWith('/draft'));
        expect(write?.body).toMatchObject({
            expected_revision: 4,
            source: { subject: 'My unsaved subject' },
        });
    });

    it('disables authoring fields while a save is in flight', async () => {
        let release: (() => void) | undefined;
        const encoder = new TextEncoder();
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const standard = route(request);
            if (standard !== null) return standard;
            if (request.method === 'PUT' && request.path.endsWith('/draft')) {
                const body = request.body as { source: Source };
                const responseBody = JSON.stringify(
                    detail({
                        draft_revision: 5,
                        has_draft: true,
                        draft_source: body.source,
                    }),
                );
                return new Response(
                    new ReadableStream({
                        start(controller) {
                            release = () => {
                                controller.enqueue(encoder.encode(responseBody));
                                controller.close();
                            };
                        },
                    }),
                    { headers: { 'Content-Type': 'application/json' } },
                );
            }
            return null;
        });
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.type(subject, ' changed');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(harness.requests.some((request) => request.method === 'PUT')).toBe(true));
        expect(subject).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();

        await act(async () => release?.());
        expect(await screen.findByText('Draft saved.')).toBeInTheDocument();
        expect(subject).not.toBeDisabled();
    });

    it('preserves unsaved work when the draft revision is stale', async () => {
        renderScreen('/template/course.completed/en-GB/email', (request) => {
            const standard = route(request);
            if (standard !== null) return standard;
            if (request.method === 'PUT' && request.path.endsWith('/draft')) {
                return problemResponse(409, 'Conflict', 'The expected draft revision is stale.');
            }
            return null;
        });
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.clear(subject);
        await userEvent.type(subject, 'Keep my unsaved work');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(await screen.findByText('A newer revision exists.')).toBeInTheDocument();
        expect(subject).toHaveValue('Keep my unsaved work');
        expect(screen.getByRole('button', { name: 'Reload server version' })).toBeInTheDocument();
    });

    it('guards translation links and Back, restores the filtered list, and lets the author stay or leave', async () => {
        const listHash = '/?search=certificate&filter=draft_changes&locale=en-GB&channel=all&page=2';
        const harness = renderNavigableScreen(listHash);
        await userEvent.click(await screen.findByRole('button', { name: 'Customize Course completed' }));
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.type(subject, ' changed');

        await userEvent.click(screen.getByRole('button', { name: 'Settings & delivery' }));
        const locale = screen.getByRole('textbox', { name: /^Translation locale/ });
        await userEvent.clear(locale);
        await userEvent.type(locale, 'fr-CA');
        await userEvent.click(screen.getByRole('button', { name: 'Open translation' }));
        let dialog = screen.getByRole('dialog', { name: 'Leave unsaved changes?' });
        expect(harness.navigations).toEqual(['/template/course.completed/en-GB/email']);
        await userEvent.click(within(dialog).getByRole('button', { name: 'Keep editing' }));
        expect(subject).toHaveValue(`${SOURCE.subject} changed`);

        await userEvent.click(screen.getByRole('button', { name: 'Back to email templates' }));
        dialog = screen.getByRole('dialog', { name: 'Leave unsaved changes?' });
        expect(harness.navigations).toEqual(['/template/course.completed/en-GB/email']);
        await userEvent.click(within(dialog).getByRole('button', { name: 'Keep editing' }));
        expect(screen.queryByRole('dialog', { name: 'Leave unsaved changes?' })).toBeNull();
        expect(subject).toHaveValue(`${SOURCE.subject} changed`);

        await userEvent.click(screen.getByRole('button', { name: 'Back to email templates' }));
        dialog = screen.getByRole('dialog', { name: 'Leave unsaved changes?' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Leave without saving' }));

        expect(await screen.findByRole('searchbox', { name: 'Search templates' })).toHaveValue('certificate');
        expect(harness.navigations.at(-1)).toBe(listHash);
        expect(harness.hash()).toBe(listHash);
    });

    it('intercepts browser hash travel while dirty and restores editor state when staying', async () => {
        const editorHash = '/template/course.completed/en-GB/email';
        const targetHash = '/?filter=draft_only&page=2';
        const harness = renderScreen(editorHash);
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.clear(subject);
        await userEvent.type(subject, 'Do not lose this');

        await harness.rerenderHash(targetHash);
        const dialog = await screen.findByRole('dialog', { name: 'Leave unsaved changes?' });
        expect(harness.replace).toHaveBeenLastCalledWith(editorHash);
        await userEvent.click(within(dialog).getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('textbox', { name: 'Subject' })).toHaveValue('Do not lose this');
    });

    it('discards and publishes drafts only after an accessible confirmation using the current revision', async () => {
        const draftDetail = detail({
            status: 'draft_changes',
            has_draft: true,
            draft_source: { ...SOURCE, heading: 'Draft heading' },
            draft_base_version: 3,
        });
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname.endsWith('/course.completed/en-GB/email')) {
                return jsonResponse(draftDetail);
            }
            if (request.method === 'DELETE' && url.pathname.endsWith('/draft')) {
                return jsonResponse(detail({ draft_revision: 5 }));
            }
            return route(request);
        });
        await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        const dialog = screen.getByRole('dialog', { name: 'Discard this draft?' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Discard draft' }));
        expect(await screen.findByText('Draft discarded. The published message is unchanged.')).toBeInTheDocument();
        const discardRequest = harness.requests.find((request) => request.method === 'DELETE');
        expect(new URL(discardRequest?.path ?? '', 'http://localhost').searchParams.get('expected_revision')).toBe('4');
        expect(discardRequest?.headers['idempotency-key']).toBeTruthy();

        harness.view.unmount();
        const publishHarness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname.endsWith('/course.completed/en-GB/email')) {
                return jsonResponse(draftDetail);
            }
            if (request.method === 'POST' && url.pathname.endsWith('/publish')) {
                return jsonResponse(detail({ body_version: 4, draft_revision: 5 }));
            }
            return route(request);
        });
        await screen.findByRole('textbox', { name: 'Subject' });
        await userEvent.click(screen.getByRole('button', { name: 'Publish draft' }));
        const publishDialog = screen.getByRole('dialog', { name: 'Publish this draft?' });
        await userEvent.click(within(publishDialog).getByRole('button', { name: 'Publish draft' }));
        expect(await screen.findByText('Draft published.')).toBeInTheDocument();
        const publishRequest = publishHarness.requests.find((request) => request.path.endsWith('/publish'));
        expect(publishRequest?.body).toEqual({ expected_revision: 4 });
        expect(publishRequest?.headers['idempotency-key']).toBeTruthy();
    });

    it('names a canonical locale fallback without claiming a saved translation', async () => {
        renderScreen('/template/course.completed/fr-CA/email', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname.endsWith('/course.completed/fr-CA/email')) {
                return jsonResponse(detail({ locale: 'fr-CA', using_fallback: true, resolved_locale: 'fr' }));
            }
            return route(request);
        });
        expect(await screen.findByText('Live fallback from fr')).toBeInTheDocument();
        expect(screen.getByText('Fallback language version')).toBeInTheDocument();
        expect(screen.queryByText('Published version')).not.toBeInTheDocument();
        expect(screen.getByText(/This is a fallback, not a saved translation/)).toBeInTheDocument();
    });

    it('shows blueprint provenance without rewriting live content and disables an unavailable combination', async () => {
        const newerDefault = { ...SOURCE, subject: 'New professional subject' };
        const first = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname.endsWith('/course.completed/en-GB/email')) {
                return jsonResponse(
                    detail({
                        current_blueprint_version: 2,
                        published_blueprint_version: 1,
                        update_available: true,
                        default_source: newerDefault,
                    }),
                );
            }
            return route(request);
        });
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        expect(subject).toHaveValue(SOURCE.subject);
        expect(screen.getByText('Published blueprint 1 · current blueprint 2')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Settings & delivery' }));
        await userEvent.click(screen.getByRole('button', { name: 'Start draft from current default' }));
        expect(subject).toHaveValue('New professional subject');

        first.view.unmount();
        renderScreen('/template/course.completed/en-GB/email', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname.endsWith('/course.completed/en-GB/email')) {
                return jsonResponse(
                    detail({
                        available: false,
                        unavailable_reason: 'Email is not registered for this message class.',
                    }),
                );
            }
            return route(request);
        });
        expect(await screen.findByText('Email is not registered for this message class.')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Subject' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Send test to me' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Settings & delivery' }));
        expect(screen.getByRole('textbox', { name: 'Translation locale' })).toBeEnabled();
        expect(screen.getByRole('textbox', { name: 'Short message' })).toBeDisabled();
    });

    it('isolates legacy HTML and requires an explicit professional draft start', async () => {
        renderScreen('/template/course.completed/en-GB/email', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname.endsWith('/course.completed/en-GB/email')) {
                return jsonResponse(detail({ legacy_published: true, published_source: null }));
            }
            return route(request);
        });
        const legacy = await screen.findByTitle('Legacy live email');
        expect(legacy).toHaveAttribute('sandbox', '');
        expect(screen.getByRole('textbox', { name: 'Translation locale' })).toBeEnabled();
        expect(screen.queryByRole('textbox', { name: 'Subject' })).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'Start a professional draft' }));
        expect(screen.getByRole('textbox', { name: 'Subject' })).toHaveValue(SOURCE.subject);
    });

    it('sends a synthetic self-test only to the acting account and shows its status', async () => {
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            const standard = route(request);
            if (standard !== null) return standard;
            if (request.method === 'POST' && request.path.endsWith('/test-send')) {
                return jsonResponse({
                    status: 'sent',
                    outcome: 'sent',
                    recipient_email: 'admin@example.test',
                    provider_message_id: 'provider-1',
                    error: null,
                    degraded: false,
                    delivery_ready: false,
                    delivery_warnings: [
                        {
                            code: 'public_url_https',
                            message: 'Local HTTP links are not ready for a live notification.',
                            blocking: true,
                        },
                    ],
                });
            }
            return null;
        });
        expect(
            await screen.findByText('Uses your account email only. Up to 5 test attempts per hour.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('textbox', { name: /recipient/i })).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'Send test to me' }));
        expect(
            await screen.findByText(
                'The configured provider accepted a test for admin@example.test. Inbox delivery is not guaranteed.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Local HTTP links are not ready for a live notification.')).toBeInTheDocument();
        const send = harness.requests.find((request) => request.path.endsWith('/test-send'));
        expect(send?.headers['idempotency-key']).toBeTruthy();
        expect(send?.body).toEqual({ source: SOURCE });
    });

    it('reuses a self-test retry key only while the source is unchanged', async () => {
        const harness = renderScreen('/template/course.completed/en-GB/email', (request) => {
            if (request.method === 'POST' && request.path.endsWith('/test-send'))
                throw new TypeError('Network disconnected');
            return route(request);
        });
        const subject = await screen.findByRole('textbox', { name: 'Subject' });
        const send = screen.getByRole('button', { name: 'Send test to me' });
        const sends = () => harness.requests.filter((request) => request.path.endsWith('/test-send'));
        await userEvent.click(send);
        await waitFor(() => expect(sends()).toHaveLength(1));
        await waitFor(() => expect(send).toBeEnabled());
        await userEvent.click(send);
        await waitFor(() => expect(sends()).toHaveLength(2));
        expect(sends()[1]?.headers['idempotency-key']).toBe(sends()[0]?.headers['idempotency-key']);
        await waitFor(() => expect(send).toBeEnabled());
        await userEvent.type(subject, ' Updated');
        await userEvent.click(send);
        await waitFor(() => expect(sends()).toHaveLength(3));
        expect(sends()[2]?.headers['idempotency-key']).not.toBe(sends()[1]?.headers['idempotency-key']);
        expect(sends()[2]?.body).toMatchObject({ source: { subject: `${SOURCE.subject} Updated` } });
    });

    it('renders explicit 401, 403 and exact 404 states', async () => {
        for (const status of [401, 403, 404]) {
            const view = renderScreen('/template/course.completed/en-GB/email', () => problemResponse(status));
            if (status === 401) expect(await screen.findByText('Sign in to continue.')).toBeInTheDocument();
            if (status === 403) expect(await screen.findByText('Managers see courses only.')).toBeInTheDocument();
            if (status === 404) expect(await screen.findByText('Template not found')).toBeInTheDocument();
            view.view.unmount();
        }
    });

    it('renders an invalid bookmark as a recoverable route state', async () => {
        const { navigate } = renderScreen('/template/incomplete');
        const alert = screen.getByRole('alert');
        expect(within(alert).getByText('Template link not recognized')).toBeInTheDocument();
        await userEvent.click(within(alert).getByRole('button', { name: 'Back to email templates' }));
        expect(navigate).toHaveBeenCalledWith('/');
    });
});
