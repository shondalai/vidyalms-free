import { describe, expect, it } from 'vitest';
import { MOUNT_CONTRACT, MountConfigError, readMountConfig } from './config';

const validConfig = {
    api: { base: '/v1', mode: 'prefix', headers: { 'X-CSRF-Token': 'tok' } },
    course: { id: 3, slug: 'intro-to-php' },
    lessonId: null,
    user: { id: 42, name: 'Priya Nair' },
    locale: 'en-GB',
    urls: { course: '/course/intro-to-php', myLearning: '/my-learning' },
};

function mountEl(config?: string): HTMLElement {
    const el = document.createElement('div');
    el.id = 'vidyalms-player';
    if (config !== undefined) {
        el.setAttribute('data-vy-config', config);
    }
    document.body.appendChild(el);
    return el;
}

describe('readMountConfig', () => {
    it('parses a valid contract payload', () => {
        const el = mountEl(JSON.stringify(validConfig));
        const config = readMountConfig(el);
        expect(config.api.mode).toBe('prefix');
        expect(config.course.slug).toBe('intro-to-php');
        expect(config.lessonId).toBeNull();
        expect(config.user.name).toBe('Priya Nair');
    });

    it('accepts a numeric lessonId and the query transport mode', () => {
        const el = mountEl(
            JSON.stringify({
                ...validConfig,
                lessonId: 7,
                api: { base: '/index.php?option=com_vidyalms&task=api.route&path=', mode: 'query', headers: {} },
            }),
        );
        const config = readMountConfig(el);
        expect(config.lessonId).toBe(7);
        expect(config.api.mode).toBe('query');
    });

    it('renders an in-place alert and throws when the attribute is missing', () => {
        const el = mountEl();
        expect(() => readMountConfig(el)).toThrow(MountConfigError);
        const alert = el.querySelector('[role="alert"]');
        expect(alert).not.toBeNull();
        expect(alert?.textContent).toContain('VidyaLMS could not start.');
    });

    it('renders an in-place alert and throws on broken JSON', () => {
        const el = mountEl('{not json');
        expect(() => readMountConfig(el)).toThrow(MountConfigError);
        expect(el.querySelector('[role="alert"]')).not.toBeNull();
    });

    it('renders an in-place alert and throws when the shape is off-contract', () => {
        const el = mountEl(JSON.stringify({ ...validConfig, api: { base: '/v1', mode: 'websocket' } }));
        expect(() => readMountConfig(el)).toThrow(MountConfigError);
        const alert = el.querySelector('[role="alert"]');
        expect(alert).not.toBeNull();
        /* the developer-facing detail names the offending field */
        expect(alert?.textContent).toContain('mode');
    });
});

/*
 * The config is a wire contract between a host and a bundle that ship and
 * upgrade separately, and it had no version at all: the failure mode when a
 * bundle was newer than its host was a silently missing key, which is what
 * happened with urls.upload.
 */
describe('the mount contract version', () => {
    function mount(config: unknown): HTMLElement {
        const el = document.createElement('div');
        el.id = 'vidyalms-player';
        el.setAttribute('data-vy-config', JSON.stringify(config));
        document.body.append(el);
        return el;
    }

    it('defaults to this bundle for a host from before versions existed', () => {
        expect(readMountConfig(mount(validConfig)).contract).toBe(MOUNT_CONTRACT);
    });

    it('accepts the contract this bundle speaks', () => {
        expect(readMountConfig(mount({ ...validConfig, contract: MOUNT_CONTRACT })).contract).toBe(MOUNT_CONTRACT);
    });

    it('refuses a host one contract ahead, and says which way round it is', () => {
        const el = mount({ ...validConfig, contract: MOUNT_CONTRACT + 1 });

        expect(() => readMountConfig(el)).toThrow(MountConfigError);
        expect(el.textContent).toContain('older than the extension');
    });
});
