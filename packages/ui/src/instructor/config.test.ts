import { describe, expect, it } from 'vitest';
import { readMountConfig, MountConfigError, mountConfigSchema } from '@/shared/config';
import { fillUrlTemplate, instructorConfigSchema } from './config';

/*
 * The workbench declares its own mount contract. These prove the two apps
 * stay independent: the instructor schema accepts what the learner schema
 * would refuse and vice versa, and readMountConfig validates against
 * whichever schema the app hands it.
 */

const valid = {
    api: { base: '/v1', mode: 'prefix', headers: { 'X-CSRF-Token': 'abc' } },
    course: { id: 3, slug: 'web-accessibility' },
    user: { id: 7, name: 'R. Okafor' },
    locale: 'en-GB',
    urls: { courses: '/admin/courses', workbench: '/admin/course/{course_id}', preview: '/course/{slug}' },
};

function mountWith(json: unknown): HTMLElement {
    const el = document.createElement('div');
    el.id = 'vidyalms-instructor';
    el.setAttribute('data-vy-config', JSON.stringify(json));
    return el;
}

describe('instructorConfigSchema', () => {
    it('accepts a workbench mount for one course', () => {
        const config = instructorConfigSchema.parse(valid);
        expect(config.course?.id).toBe(3);
        expect(config.urls.preview).toBe('/course/{slug}');
    });

    it('accepts a null course, which is the course list mount', () => {
        const config = instructorConfigSchema.parse({ ...valid, course: null });
        expect(config.course).toBeNull();
    });

    it('refuses a config without the host URLs the app substitutes into', () => {
        expect(() => instructorConfigSchema.parse({ ...valid, urls: { courses: '/x' } })).toThrow();
    });

    /*
     * The upload endpoint is the host's, not the contract's, and a host
     * older than it has none. Absent it, the workbench keeps its typed
     * paths; present, it is what the pickers post to.
     */
    it('accepts a config with no upload endpoint, which is an older host', () => {
        expect(instructorConfigSchema.parse(valid).urls.upload).toBeUndefined();
    });

    it('carries the upload endpoint when the host gave one', () => {
        const config = instructorConfigSchema.parse({
            ...valid,
            urls: { ...valid.urls, upload: '/index.php?option=com_vidyalms&task=api.upload' },
        });
        expect(config.urls.upload).toBe('/index.php?option=com_vidyalms&task=api.upload');
    });

    it('refuses an empty upload endpoint, which is a host mistake, not an absent one', () => {
        expect(() =>
            instructorConfigSchema.parse({ ...valid, urls: { ...valid.urls, upload: '' } }),
        ).toThrow();
    });

    it('refuses a course id of zero, which no route would accept', () => {
        expect(() => instructorConfigSchema.parse({ ...valid, course: { id: 0, slug: 'x' } })).toThrow();
    });

    it('keeps the learner contract separate: the player config is not a workbench config', () => {
        const playerConfig = {
            api: valid.api,
            course: { id: 3, slug: 'x' },
            lessonId: null,
            user: valid.user,
            locale: 'en-GB',
            urls: { course: '/c', myLearning: '/my' },
        };
        expect(() => instructorConfigSchema.parse(playerConfig)).toThrow();
        expect(mountConfigSchema.parse(playerConfig).lessonId).toBeNull();
    });
});

describe('readMountConfig with a per-app schema', () => {
    it('validates the workbench config against the schema the app passes', () => {
        const el = mountWith(valid);
        const config = readMountConfig(el, instructorConfigSchema);
        expect(config.user.name).toBe('R. Okafor');
    });

    it('renders the malformed state in place and throws when the app schema refuses', () => {
        const el = mountWith({ ...valid, urls: undefined });
        expect(() => readMountConfig(el, instructorConfigSchema)).toThrow(MountConfigError);
        expect(el.textContent).toContain('VidyaLMS could not start.');
    });

    it('still defaults to the learner schema for the apps that had no schema argument', () => {
        const el = mountWith({
            api: valid.api,
            course: { id: 3, slug: 'x' },
            lessonId: null,
            user: valid.user,
            locale: 'en-GB',
            urls: { course: '/c', myLearning: '/my' },
        });
        expect(readMountConfig(el).course.slug).toBe('x');
    });
});

describe('fillUrlTemplate', () => {
    it('substitutes the tokens the contract names', () => {
        expect(fillUrlTemplate('/admin/course/{course_id}', { course_id: 12 })).toBe('/admin/course/12');
        expect(fillUrlTemplate('/course/{slug}', { slug: 'web-accessibility' })).toBe('/course/web-accessibility');
    });

    it('encodes a value so a slug cannot break out of the host URL', () => {
        expect(fillUrlTemplate('/course/{slug}', { slug: 'a b&c' })).toBe('/course/a%20b%26c');
    });

    it('leaves a template with no token alone', () => {
        expect(fillUrlTemplate('/admin/courses', { course_id: 4 })).toBe('/admin/courses');
    });
});
