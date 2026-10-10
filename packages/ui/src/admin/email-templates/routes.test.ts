import { describe, expect, it } from 'vitest';
import { emailTemplateHash, emailTemplateStudioUrl, parseEmailTemplateRoute } from './routes';

describe('email template routes', () => {
    it('round trips an exact encoded identity', () => {
        const identity = { templateKey: 'course/completed notice', locale: 'pt-BR', channelKey: 'email' };
        const hash = emailTemplateHash(identity);
        expect(hash).toBe('/template/course%2Fcompleted%20notice/pt-BR/email');
        expect(parseEmailTemplateRoute(hash)).toEqual({ kind: 'detail', identity });
    });

    it('does not guess from incomplete, malformed or query-bearing identities', () => {
        expect(parseEmailTemplateRoute('/template/course.completed/en-GB')).toEqual({ kind: 'not-found' });
        expect(parseEmailTemplateRoute('/template/%E0%A4%A/en-GB/email')).toEqual({ kind: 'not-found' });
        expect(parseEmailTemplateRoute('/template/course.completed/en-GB/email?fallback=1')).toEqual({ kind: 'not-found' });
    });

    it('keeps the host URL and replaces only its fragment', () => {
        expect(emailTemplateStudioUrl(
            '/wp-admin/admin.php?page=vidyalms-email#old',
            { templateKey: 'course.completed', locale: 'en-GB', channelKey: 'email' },
        )).toBe('/wp-admin/admin.php?page=vidyalms-email#/template/course.completed/en-GB/email');
    });
});
