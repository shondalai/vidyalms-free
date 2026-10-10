import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { jsonResponse, makeConfig, makeHarness } from '../testing/harness';
import { AutomationTemplatePicker } from './template-picker';

describe('notification selection in an automation', () => {
    it('loads a bounded trigger-filtered page and fills the message identity without selecting an unpublished draft', async () => {
        const harness = makeHarness(
            (request) => {
                if (request.path.includes('/manage/notifications/templates'))
                    return jsonResponse({
                        items: [
                            {
                                template_key: 'welcome',
                                label: 'Course welcome',
                                locale: 'en-GB',
                                channel_key: 'email',
                                category_key: 'course_activity',
                                class: 'operational',
                                state: 'published',
                            },
                            {
                                template_key: 'draft',
                                label: 'Unpublished message',
                                locale: 'en-GB',
                                channel_key: 'email',
                                state: 'draft',
                            },
                        ],
                        total: 27,
                        page: 1,
                        per_page: 12,
                    });
                return null;
            },
            makeConfig({ screen: 'automations' }),
        );
        const onChange = vi.fn();
        render(<AutomationTemplatePicker triggerKey="vidyalms.mail.enrolled" value={{}} onChange={onChange} />, {
            wrapper: harness.wrapper,
        });
        expect(harness.requests).toHaveLength(0);
        await userEvent.click(screen.getByRole('button', { name: 'Choose a message template' }));
        expect(await screen.findByRole('button', { name: /Unpublished message/ })).toBeDisabled();
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.path.includes('trigger_key=vidyalms.mail.enrolled') &&
                        request.path.includes('per_page=12'),
                ),
            ).toBe(true),
        );
        await userEvent.click(screen.getByRole('button', { name: /Course welcome/ }));
        expect(onChange).toHaveBeenCalledWith({
            template_key: 'welcome',
            category_key: 'course_activity',
            channel_key: 'email',
            class: 'operational',
        });
        expect(harness.requests.every((request) => request.method === 'GET')).toBe(true);
    });
});
