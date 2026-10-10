import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'styles.css'), 'utf8');
const joomlaAdminCss = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../../adapter-joomla/com_vidyalms/media/css/admin.css'),
    'utf8',
);

describe('admin mount styles', () => {
    it('caps the embedded application at the width supplied by its host', () => {
        const mountRule = css.match(/#vidyalms-admin\s*\{([\s\S]*?)\}/)?.[1] ?? '';

        expect(mountRule).toContain('width: 100%');
        expect(mountRule).toContain('min-width: 0');
        expect(mountRule).toContain('max-width: 100%');
    });

    it("lets Joomla's full-width main flex item shrink beside Atum's sidebar", () => {
        const hostRule = joomlaAdminCss.match(
            /body\.com_vidyalms\s+#wrapper\s*>\s*\.container-main\s*\{([\s\S]*?)\}/,
        )?.[1] ?? '';

        expect(hostRule).toMatch(/min-width\s*:\s*0\s*;/);
    });

    it('locks only the Joomla SPA document and bounds content at compact widths too', () => {
        expect(joomlaAdminCss).toMatch(/html:has\(body\.com_vidyalms #vidyalms-admin\)/);
        expect(joomlaAdminCss).toMatch(/body\.com_vidyalms:has\(#vidyalms-admin\)\s*\{\s*overflow: hidden/);
        expect(joomlaAdminCss).toContain('height: calc(100vh - var(--_vy-workspace-offset, 0px))');
        expect(joomlaAdminCss).toContain('height: calc(100dvh - var(--_vy-workspace-offset, 0px))');
        expect(joomlaAdminCss).not.toContain('height: max(320px');
        expect(joomlaAdminCss).toMatch(/\.vy-workspace-main\s*\{\s*overflow: auto/);
        expect(joomlaAdminCss).toContain('padding: 0 !important');
        expect(joomlaAdminCss).toContain('margin: 0 !important');
    });

    it('hides Joomla sidebar tracks while retaining scrolling and collapsed flyouts', () => {
        expect(joomlaAdminCss).toMatch(/#wrapper:not\(\.closed\) > \.sidebar-wrapper\s*\{[^}]*overflow-y: auto/);
        expect(joomlaAdminCss).toContain('#wrapper:not(.closed) :is(.sidebar-wrapper, .sidebar-sticky, #sidebarmenu)');
        expect(joomlaAdminCss).toContain('scrollbar-width: none !important');
        expect(joomlaAdminCss).toContain('- var(--_vy-admin-bottom-height, 0px)');
        expect(joomlaAdminCss).toMatch(/:is\(\.sidebar-wrapper, \.sidebar-sticky, #sidebarmenu\)::-webkit-scrollbar\s*\{\s*display: none/);
    });
});
