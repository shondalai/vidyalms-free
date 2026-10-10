import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'styles.css'), 'utf8');

describe('public credentials page canvas', () => {
    it('paints the active theme inside the mount, without changing host chrome', () => {
        expect(css).toMatch(/#vidyalms-credentials\s*\{[^}]*background-color:\s*transparent;/);
        expect(css).not.toMatch(/(?:^|\})\s*(?:body|html|main)\s*\{/);
    });

    it('keeps responsive, host-overridable gutters without imposing a fixed width', () => {
        const mount = css.match(/#vidyalms-credentials\s*\{([^}]*)\}/)?.[1] ?? '';
        expect(mount).toContain('padding: var(--vy-page-padding, 0);');
        expect(mount).not.toMatch(/(?:^|[;{])\s*(?:max-width|width):/);
    });
});
