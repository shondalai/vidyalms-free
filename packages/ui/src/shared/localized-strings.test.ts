import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * Copy tables in the shared chunk follow the page's catalogue.
 *
 * learner-navigation.js evaluates the shared chunk on the first learner page,
 * and the catalogue, course and My learning pages carry no data-vy-config.
 * Tables written as plain objects translated once, there, and kept English
 * after swup swapped in a player whose mount carries the host's messages
 * (review of 2026-09-30: "VidyaLMS could not start." on a French site). Each
 * case imports fresh module instances, so the first import is the first page.
 */

const FRENCH = {
    'vidyalms.ui.5d53377ba6aa28b2': 'VidyaLMS n a pas pu demarrer.',
    'vidyalms.ui.a050175f094b8b8a': 'Chargement impossible.',
    'vidyalms.ui.bc9d266058a6a5f2': 'Enregistre {p0}',
    'vidyalms.ui.4bc7f76d36ade5ee': 'Plus disponible',
    'vidyalms.ui.bb46314ceee7e7a3': 'Votre travail pratique',
    'vidyalms.ui.9e5239fb446a51ce': 'Saisissez une adresse http ou https valide.',
};

/** What swup does to the shell: the next page's content, here a mount carrying the host catalogue. */
function swapInMount(messages: Record<string, string>, id = 'vidyalms-player'): HTMLElement {
    const mount = document.createElement('div');
    mount.id = id;
    mount.setAttribute('data-vy-config', JSON.stringify({ contract: 1, api: { base: '/x', mode: 'prefix', headers: {} }, user: { id: 0, name: '' }, locale: 'fr-FR', messages }));
    document.body.replaceChildren(mount);
    return mount;
}

beforeEach(() => {
    document.body.replaceChildren();
    vi.resetModules();
});

afterEach(() => document.body.replaceChildren());

describe('localizedStrings', () => {
    it('builds on the first read, again only when the catalogue changes, and reads like the plain table', async () => {
        const { localizedStrings, translate } = await import('./i18n');
        const build = vi.fn(() => ({ title: translate('t', 'Title'), nested: { count: (n: number) => translate('c', '{p0} items', { p0: n }) } }));
        const table = localizedStrings(build);

        expect(build).not.toHaveBeenCalled();
        expect(table.title).toBe('Title');
        expect(table.nested.count(3)).toBe('3 items');
        expect(build).toHaveBeenCalledTimes(1);

        swapInMount({ t: 'Titre', c: '{p0} elements' });
        expect(table.title).toBe('Titre');
        expect(table.nested.count(3)).toBe('3 elements');
        expect(build).toHaveBeenCalledTimes(2);

        expect(Object.keys(table)).toEqual(['title', 'nested']);
        expect('title' in table).toBe(true);
        expect({ ...table }.title).toBe('Titre');
        expect(JSON.stringify(table)).toBe('{"title":"Titre","nested":{}}');
        expect(build).toHaveBeenCalledTimes(2);
    });

    it('translates the shared tables for a mount swapped in after the shared chunk ran on a page with no catalogue', async () => {
        // The course page: learner-navigation.js evaluates shared, and nothing on the page carries a config.
        const { sharedStrings } = await import('./strings');
        const { availabilityStrings } = await import('./availability');
        const { practicalStrings } = await import('./practical-strings');
        expect(sharedStrings.config.malformedTitle).toBe('VidyaLMS could not start.');

        swapInMount(FRENCH);

        expect(sharedStrings.config.malformedTitle).toBe('VidyaLMS n a pas pu demarrer.');
        expect(sharedStrings.errors.couldNotLoad).toBe('Chargement impossible.');
        expect(sharedStrings.save.savedAgo('2m')).toBe('Enregistre 2m');
        expect(availabilityStrings.closed).toBe('Plus disponible');
        expect(practicalStrings.title).toBe('Votre travail pratique');
    });

    it('renders the malformed-config notice in the language of the page it was swapped into', async () => {
        const { readMountConfig } = await import('./config');
        const mount = swapInMount(FRENCH, 'vidyalms-quiz-runner');
        mount.setAttribute('data-vy-config', JSON.stringify({ ...JSON.parse(mount.getAttribute('data-vy-config')!), course: 'broken' }));

        expect(() => readMountConfig(mount)).toThrow();

        expect(mount.querySelector('[data-vy-config-error] strong')).toHaveTextContent('VidyaLMS n a pas pu demarrer.');
    });

    it('translates the lesson presentation URL message when a value is checked, not when the module loads', async () => {
        const { lessonPresentationSchema } = await import('./lesson-presentation');
        swapInMount(FRENCH);

        const result = lessonPresentationSchema.shape.poster_url.safeParse('not a url');

        expect(result.success).toBe(false);
        expect(result.error?.issues[0]?.message).toBe('Saisissez une adresse http ou https valide.');
    });
});
