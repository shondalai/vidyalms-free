import { describe, expect, it } from 'vitest';
import { emailTemplateListHash, emailTemplateListValues, type EmailTemplateListState } from './list-state';

describe('email template list state', () => {
    it('preserves provider channels, notification filters, and the table view in a bookmark', () => {
        const state = {
            search: '',
            filter: 'all',
            locale: '',
            channel: 'sms',
            page: 2,
            trigger: 'course.completed',
            view: 'table' as const,
        };
        expect(emailTemplateListValues(emailTemplateListHash(state))).toEqual(state);
    });
    it('round trips the complete bookmarkable filter identity', () => {
        const state: EmailTemplateListState = {
            search: 'completion & certificate',
            filter: 'draft_changes',
            locale: 'pt-BR',
            channel: 'all',
            page: 37,
        };

        expect(emailTemplateListValues(emailTemplateListHash(state))).toEqual(state);
    });

    it('uses a compact root hash for the default gallery state', () => {
        expect(
            emailTemplateListHash({
                search: '',
                filter: 'all',
                locale: '',
                channel: 'email',
                page: 1,
            }),
        ).toBe('/');
    });

    it('rejects unchecked filter, channel, and unbounded page values from bookmarks', () => {
        expect(emailTemplateListValues('/?filter=deleted&channel=not%20valid&page=1000001')).toMatchObject({
            filter: 'all',
            channel: 'email',
            page: 1,
        });
        expect(emailTemplateListValues('/?page=2.5')).toMatchObject({ page: 1 });
    });
});
