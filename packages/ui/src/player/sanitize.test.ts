// @vitest-environment jsdom
/*
 * jsdom on purpose: DOMPurify does not sanitize correctly on happy-dom's
 * DOM implementation (scripts survive), so the security assertions run on
 * the DOM DOMPurify supports. No SDK or network is involved here.
 */
import { describe, expect, it } from 'vitest';
import { sanitizeHtml } from './sanitize';

describe('sanitizeHtml', () => {
    it('keeps ordinary article markup', () => {
        const html = '<h2>Title</h2><p>Text with <strong>bold</strong> and <a href="https://example.com">a link</a>.</p><ul><li>Item</li></ul>';
        expect(sanitizeHtml(html)).toBe(html);
    });

    it('strips script elements and inline event handlers', () => {
        const out = sanitizeHtml(
            '<p>Safe</p><script>window.hacked = true;</script><img src="x" onerror="window.hacked = true">',
        );
        expect(out).toContain('<p>Safe</p>');
        expect(out).not.toContain('script');
        expect(out).not.toContain('onerror');
    });

    it('strips javascript: URLs and foreign content', () => {
        const out = sanitizeHtml('<a href="javascript:alert(1)">x</a><iframe src="https://evil.example"></iframe><svg onload="alert(1)"></svg>');
        expect(out).not.toContain('javascript:');
        expect(out).not.toContain('iframe');
        expect(out).not.toContain('onload');
    });

    it('strips style, form and input elements per the embed profile', () => {
        const out = sanitizeHtml('<style>#x{}</style><form action="/steal"><input name="a"><button>go</button></form><p>Kept</p>');
        expect(out).not.toContain('<style');
        expect(out).not.toContain('<form');
        expect(out).not.toContain('<input');
        expect(out).toContain('<p>Kept</p>');
    });

    it('forces rel isolation onto links that open a new tab', () => {
        const out = sanitizeHtml('<a href="https://example.com" target="_blank">out</a>');
        expect(out).toContain('target="_blank"');
        expect(out).toContain('rel="noopener noreferrer"');
    });
});
