import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TemplateEditor } from './template-editor';
import { makeConfig, makeHarness } from '../../testing/harness';

describe('notification rule template destination', () => {
    it('deep-links the selected identity through the host-owned studio URL without API writes', async () => {
        const config = makeConfig({
            urls: {
                ...makeConfig().urls,
                emailTemplates: '/wp-admin/admin.php?page=vidyalms-email',
            },
        });
        const harness = makeHarness(() => null, config);
        render(
            <TemplateEditor templateKey="course.completed" channelKeys={['email']} locale="en-GB" />,
            { wrapper: harness.wrapper },
        );
        expect(screen.getByRole('link', { name: 'Open email template studio' })).toHaveAttribute(
            'href',
            '/wp-admin/admin.php?page=vidyalms-email#/template/course.completed/en-GB/email',
        );
        expect(harness.requests).toHaveLength(0);
        expect(screen.queryByRole('textbox', { name: /HTML body/i })).toBeNull();
    });

    it('states when an older host did not provide the studio destination', () => {
        const harness = makeHarness(() => null);
        render(
            <TemplateEditor templateKey="course.completed" channelKeys={['email']} locale="en-GB" />,
            { wrapper: harness.wrapper },
        );
        expect(screen.getByText('The host did not provide the Email template studio URL for this account.')).toBeInTheDocument();
        expect(screen.queryByRole('link')).toBeNull();
    });
});
