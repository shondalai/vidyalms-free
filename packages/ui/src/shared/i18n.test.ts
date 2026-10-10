import { afterEach, expect, it } from 'vitest';
import { translate } from './i18n';

afterEach(() => document.body.replaceChildren());

it('reads host translations and supports reordered parameters without interpreting HTML', () => {
    const mount = document.createElement('div');
    mount.setAttribute('data-vy-config', JSON.stringify({ messages: { example: 'Travail de {p1} : {p0}' } }));
    document.body.append(mount);
    expect(translate('example', '{p0} by {p1}', { p0: '<b>Photos</b>', p1: 'Camille' })).toBe('Travail de Camille : <b>Photos</b>');
    expect(translate('missing', 'Save')).toBe('Save');
});
