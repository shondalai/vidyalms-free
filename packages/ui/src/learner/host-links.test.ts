import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';
import { isLearnerLink } from './navigation';

/*
 * Two learner links the hosts print, checked against the rule SPA navigation
 * applies to them. Both regressed on 2026-09-30: the WordPress My
 * organizations link forced https, so on an http site it was cross-origin
 * and reloaded the page; the Joomla course page Sign in link had no
 * data-no-swup, so swup fetched the sign-in page first, logged a failed
 * visit and only then let the browser load it.
 */
const packages = join(dirname(fileURLToPath(import.meta.url)), '../../..');
const hostFile = (path: string) => readFileSync(join(packages, path), 'utf8');

function inShell(markup: string): HTMLAnchorElement {
    document.body.innerHTML = `<div data-vy-learner-shell data-vy-site-key="host">${markup}</div>`;
    return document.querySelector<HTMLAnchorElement>('a')!;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('host learner links under SPA navigation', () => {
    it('keeps the WordPress My organizations link on the page scheme, so it stays an SPA hop', () => {
        const source = hostFile('adapter-wordpress/vidyalms/src/Content/OrganisationLanding.php');
        const body = /function url\(\): string\s*\{([\s\S]*?)\n {4}\}/.exec(source)?.[1] ?? '';
        expect(body, 'OrganisationLanding::url() is where the shell link comes from').toContain('vidyalms_organisations');
        const forced = /home_url\(\s*'\/'\s*,\s*'([a-z]+)'\s*\)/.exec(body)?.[1];
        expect(forced, 'home_url() follows the site scheme when no scheme is passed').toBeUndefined();
        expect(body).toMatch(/home_url\(\s*'\/'\s*\)/);
        // What the shell prints on this page's own scheme is a same-origin learner link.
        const link = inShell(`<nav><a href="${location.origin}/?vidyalms_organisations=1">My organizations</a></nav>`);
        expect(isLearnerLink(link)).toBe(true);
        // The forced scheme on an http page is the cross-origin link that reloaded.
        const other = location.protocol === 'https:' ? 'http:' : 'https:';
        expect(isLearnerLink(inShell(`<nav><a href="${other}//${location.host}/?vidyalms_organisations=1">My organizations</a></nav>`))).toBe(false);
    });

    it('keeps the Joomla course page Sign in link native even on a menu alias, so swup never fetches it', () => {
        const source = hostFile('adapter-joomla/com_vidyalms/site/tmpl/course/default.php');
        // A PHP block inside the tag is one unit, so its closing ?> does not end the tag.
        const anchors = (source.match(/<a\b(?:<\?php[\s\S]*?\?>|[^>])*>/g) ?? []).filter((tag) => tag.includes('$this->loginUrl'));
        expect(anchors.length, 'the guest Sign in link of the course page').toBeGreaterThan(0);
        for (const anchor of anchors) {
            expect(anchor).toMatch(/\sdata-no-swup(?:[\s>=])/);
            // The template's tag with a sign-in address no URL rule recognises: only the marker keeps it native.
            const markup = anchor.replace(/<\?php echo \$this->loginUrl; \?>/, '/index.php/sign-in?return=aHR0cDovL2E') + 'Sign in</a>';
            expect(isLearnerLink(inShell(`<p>${markup}</p>`)), anchor).toBe(false);
        }
    });
});
