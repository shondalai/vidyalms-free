import { describe, expect, it } from 'vitest';
import { operationOrigin } from './origin';

const base = 'https://joomla.test/administrator/index.php?option=com_vidyalms&view=operations';
const screens = {
    certificates: 'index.php?option=com_vidyalms&view=certificates',
    import: 'index.php?option=com_vidyalms&view=import',
    organisations: 'index.php?option=com_vidyalms&view=organisations',
    notifications: 'index.php?option=com_vidyalms&view=notifications',
    reports: 'index.php?option=com_vidyalms&view=reports',
    enrolments: 'index.php?option=com_vidyalms&view=enrolments',
};
const task = { type: 'vidyalms.certificate.reissue', group_key: 'reissue:template:4:3' };

describe('safe registered operation workflow destinations', () => {
    it.each(['vidyalms.certificate.reissue', 'vidyalms.certificate.reissue_one'])('resolves %s to its real template, retaining native Joomla routing', (type) => {
        expect(operationOrigin({ ...task, type }, screens, base)).toEqual({
            href: 'https://joomla.test/administrator/index.php?option=com_vidyalms&view=certificates#/templates/4/edit',
            label: 'Open certificate template',
        });
    });
    it('preserves WordPress routing and replaces an old screen hash', () => {
        expect(operationOrigin(task, { certificates: '/wp-admin/admin.php?page=vidyalms-certificates#/badges' }, 'https://wordpress.test/wp-admin/admin.php?page=vidyalms-operations')?.href)
            .toBe('https://wordpress.test/wp-admin/admin.php?page=vidyalms-certificates#/templates/4/edit');
    });
    it.each([null, '', 'template4:revision3', 'reissue:template:0:3', 'reissue:template:-4:3', 'reissue:template:4:0',
        'reissue:template:9007199254740993:3', 'reissue:template:4:9007199254740993', 'reissue:template:4:3:cursor:8',
        'reissue:template:4:3?redirect=https://evil.test', 'https://evil.test/4'])('falls back to the templates list for non-contract group %s', (group_key) => {
        expect(operationOrigin({ ...task, group_key }, screens, base)).toEqual({
            href: 'https://joomla.test/administrator/index.php?option=com_vidyalms&view=certificates#/templates',
            label: 'Open certificate templates',
        });
    });
    it.each([
        ['vidyalms.import.run', 'import', 'Open import history'],
        ['vidyalms.organisation.terminate_access', 'organisations', 'Open organizations'],
        ['vidyalms.notification.fanout', 'notifications', 'Open notification settings'],
        ['vidyalms.notification.send', 'notifications', 'Open notification settings'],
        ['vidyalms.notification.sweep', 'notifications', 'Open notification settings'],
        ['vidyalms.certificate.render', 'certificates', 'Open issued certificates'],
        ['vidyalms.certificate.backfill', 'certificates', 'Open issued certificates'],
        ['vidyalms.report.generate', 'reports', 'Open reports'],
        ['vidyalms.entitlements.expire', 'enrolments', 'Open enrolments'],
        ['vidyalms.progress.recompute', 'enrolments', 'Open enrolments'],
    ])('uses an honest workflow list for %s without guessing record IDs', (type, screen, label) => {
        expect(operationOrigin({ type, group_key: 'https://evil.test/jobs/99' }, screens, base)).toEqual({
            href: `https://joomla.test/administrator/index.php?option=com_vidyalms&view=${screen}#/`, label,
        });
    });
    it.each(['thirdparty.custom', 'vidyalms.import.run.evil', 'vidyalms.certificate.reissue_custom', ''])('does not invent a destination for %s', (type) => {
        expect(operationOrigin({ ...task, type }, screens, base)).toBeNull();
    });
    it.each([undefined, '', ' ', 'javascript:alert(1)', 'data:text/html,hello', '//evil.test/admin',
        'https://evil.test/admin', 'https://user:password@joomla.test/admin', 'http://['])('omits missing or unsafe advertised screens: %s', (certificates) => {
        expect(operationOrigin(task, certificates === undefined ? {} : { certificates }, base)).toBeNull();
    });
});
