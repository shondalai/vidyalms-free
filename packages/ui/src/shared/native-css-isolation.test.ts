import postcss, { type Declaration, type Plugin, type Root, type Rule } from 'postcss';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import tailwindcss from 'tailwindcss';
import { beforeAll, describe, expect, it } from 'vitest';
import config from '../../tailwind.config';

const moduleUrl = pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../../postcss.config.js')).href;
const { default: postcssConfig, nativeBorderIsolation } = await import(moduleUrl) as {
    default: { plugins: unknown[] };
    nativeBorderIsolation: Plugin;
};
let output: Root;

beforeAll(async () => {
    const result = await postcss([
        tailwindcss({
            ...config,
            safelist: [],
            content: [{
                raw: '<div class="border border-0 border-2 border-border border-accent border-warn border-danger border-l-[3px] border-l-accent border-dashed hover:border-accent aria-[invalid=true]:border-danger rounded-lg w-full h-full left-0 top-0 text-fg bg-surface"></div>',
                extension: 'html',
            }],
        }),
        nativeBorderIsolation,
    ]).process('@tailwind utilities;', { from: undefined });
    output = result.root;
});

function declarations(selectorFragment: string, property: string): Declaration[] {
    const found: Declaration[] = [];
    output.walkRules((rule) => {
        if (rule.selector.endsWith(selectorFragment)) {
            rule.walkDecls(property, (declaration) => { found.push(declaration); });
        }
    });
    return found;
}

describe('native admin border isolation in actual Tailwind output', () => {
    it('is enabled in the shipped PostCSS pipeline', () => {
        expect(postcssConfig.plugins).toContain(nativeBorderIsolation);
    });

    it.each([
        ['.border', 'border-width', '1px'],
        ['.border-0', 'border-width', '0px'],
        ['.border-2', 'border-width', '2px'],
        ['.border-border', 'border-color', 'var(--_vy-border)'],
        ['.border-accent', 'border-color', 'var(--_vy-accent)'],
        ['.border-warn', 'border-color', 'var(--_vy-warn)'],
        ['.border-danger', 'border-color', 'var(--_vy-danger)'],
        ['.border-l-accent', 'border-left-color', 'var(--_vy-accent)'],
        ['.border-l-\\[3px\\]', 'border-left-width', '3px'],
        ['.border-dashed', 'border-style', 'dashed'],
        ['.hover\\:border-accent:hover', 'border-color', 'var(--_vy-accent)'],
        ['border-danger[aria-invalid="true"]', 'border-color', 'var(--_vy-danger)'],
    ])('%s beats the native important shorthand with its semantic value', (selector, property, value) => {
        const emitted = declarations(selector, property);
        expect(emitted, selector).toHaveLength(1);
        expect(emitted[0]?.value).toBe(value);
        expect(emitted[0]?.important).toBe(true);
        const rule = emitted[0]?.parent;
        expect(rule?.type).toBe('rule');
        if (rule?.type === 'rule') {
            // Same author/important cascade, but this mount-ID specificity
            // outranks Atum's .border { border: 1px solid #dee2e6!important }.
            expect(rule.selector).toContain('#vidyalms-admin');
            expect(rule.selector).toContain('#vidyalms-player');
        }
    });

    it('does not make geometry, typography, backgrounds or radius important', () => {
        for (const property of ['width', 'height', 'left', 'top', 'color', 'background-color', 'border-radius']) {
            const found: Declaration[] = [];
            output.walkDecls(property, (declaration) => { found.push(declaration); });
            expect(found.length, property).toBeGreaterThan(0);
            expect(found.every((declaration) => !declaration.important), property).toBe(true);
        }
    });

    it('leaves non-utility, unscoped and mixed-scope selectors unchanged', async () => {
        const input = '.border { border-color: red; }'
            + '#vidyalms-admin .canvas { border-color: blue; width: 10mm; }'
            + '#vidyalms-admin .border, .host-border { border-color: green; }'
            + '#vidyalms-admin .border { border-radius: 2mm; width: 20mm; border-left-width: 3px; }';
        const result = await postcss([nativeBorderIsolation]).process(input, { from: undefined });
        const important: Declaration[] = [];
        result.root.walkDecls((declaration) => { if (declaration.important) important.push(declaration); });
        expect(important).toHaveLength(1);
        expect(important[0]?.prop).toBe('border-left-width');
    });
});

describe('learner sign-in gate styles', () => {
    const sheet = postcss.parse(readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'learner-theme.css'), 'utf8'));
    const gate: Rule[] = [];
    sheet.walkRules((rule) => {
        if (rule.selector.includes('vy-signin')) gate.push(rule);
    });
    function declared(selector: string): Map<string, string> {
        const values = new Map<string, string>();
        for (const rule of gate.filter((candidate) => candidate.selector === selector && candidate.parent?.type === 'root')) {
            rule.walkDecls((declaration) => {
                values.set(declaration.prop, declaration.value);
            });
        }
        return values;
    }

    it('ships the whole block', () => {
        expect(gate.length).toBeGreaterThan(25);
    });

    it('scopes every selector under .vidyalms, so the host page is never restyled', () => {
        for (const rule of gate) {
            for (const selector of rule.selectors) {
                expect(selector.startsWith('.vidyalms'), selector).toBe(true);
                expect(selector, selector).toContain('vy-signin');
            }
        }
    });

    it('colours through tokens only, with no literal colour and no !important', () => {
        for (const rule of gate) {
            rule.walkDecls((declaration) => {
                const where = `${rule.selector} { ${declaration.prop} }`;
                expect(declaration.value, where).not.toMatch(/#[\da-f]{3,8}\b|\b(?:rgba?|hsla?)\(/i);
                expect(Boolean(declaration.important), where).toBe(false);
            });
        }
    });

    it('answers its own container, reduced motion, forced colours and print', () => {
        const parents: string[] = [];
        sheet.walkAtRules((atRule) => {
            if (atRule.some((child) => child.type === 'rule' && child.selector.includes('vy-signin'))) {
                parents.push(`@${atRule.name} ${atRule.params}`);
            }
        });
        expect(parents).toContain('@container vy-signin (max-width:720px)');
        expect(parents).toContain('@container vy-signin (max-width:540px)');
        expect(parents.some((parent) => parent.startsWith('@media') && parent.includes('prefers-reduced-motion:reduce'))).toBe(true);
        expect(parents.some((parent) => parent.startsWith('@media') && parent.includes('forced-colors:active'))).toBe(true);
        expect(parents).toContain('@media print');
    });

    it('keeps targets large, clear of sticky host headers and sized by its own container', () => {
        expect(declared('.vidyalms .vy-signin-actions .vidyalms-button').get('min-height')).toBe('48px');
        expect(declared('.vidyalms .vy-signin-help a').get('min-height')).toBe('44px');
        expect(declared('.vidyalms .vy-signin-link').get('min-height')).toBe('44px');
        expect(declared('.vidyalms .vy-signin a').get('scroll-margin-block')).toBe('96px');
        expect(declared('.vidyalms .vy-signin').get('container')).toBe('vy-signin / inline-size');
    });
});

describe('learner sign-in gate review fixes', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const theme = postcss.parse(readFileSync(join(here, 'learner-theme.css'), 'utf8'));
    const colors = postcss.parse(readFileSync(join(here, 'learner-colors.css'), 'utf8'));
    function rules(sheet: Root, match: (rule: Rule) => boolean): Rule[] {
        const found: Rule[] = [];
        sheet.walkRules((rule) => {
            if (match(rule)) found.push(rule);
        });
        return found;
    }
    function declared(sheet: Root, selector: string, parent = 'root'): Map<string, string> {
        const values = new Map<string, string>();
        for (const rule of rules(sheet, (candidate) => candidate.selector === selector
            && (parent === 'root' ? candidate.parent?.type === 'root' : candidate.parent?.toString().startsWith(parent) === true))) {
            rule.walkDecls((declaration) => {
                values.set(declaration.prop, declaration.value);
            });
        }
        return values;
    }
    function clampPx(value: string | undefined): [number, number] {
        const match = /clamp\((\d+)px,[^,]+,(\d+)px\)/.exec(value ?? '');
        expect(match, value).not.toBeNull();
        return [Number(match![1]), Number(match![2])];
    }

    it('paints the host scheme before the observer runs, so a light host is never dark on the first frame', () => {
        const base = rules(colors, (rule) => rule.parent?.type === 'root' && rule.selector.startsWith(':is(.vidyalms,')
            && rule.some((node) => node.type === 'decl' && node.prop === '--_vy-bg'));
        expect(base).toHaveLength(1);
        let scheme = '';
        base[0]!.walkDecls('color-scheme', (declaration) => {
            scheme = declaration.value;
        });
        expect(scheme).toBe('var(--_vy-learner-scheme, inherit)');

        const markers = rules(colors, (rule) => rule.selector.includes('[data-bs-theme='));
        const auto = markers.findIndex((rule) => rule.selector.includes('[data-bs-theme=auto]') && rule.selector.includes('[data-theme=system]'));
        const dark = markers.findIndex((rule) => rule.selector.includes('[data-bs-theme=dark]'));
        const light = markers.findIndex((rule) => rule.selector.includes('[data-bs-theme=light]'));
        expect(auto).toBeGreaterThanOrEqual(0);
        let autoScheme = '';
        markers[auto]!.walkDecls('--_vy-learner-scheme', (declaration) => {
            autoScheme = declaration.value;
        });
        expect(autoScheme).toBe('light dark');
        expect(auto, 'an explicit dark or light marker wins a conflict').toBeLessThan(Math.min(dark, light));
    });

    it('marks the current navigation item with more than colour', () => {
        expect(declared(theme, '.vidyalms .vy-learning-navigation a[aria-current=page]').get('box-shadow'))
            .toBe('inset 0 -2px 0 var(--_vy-accent)');
    });

    it('eases the action colours only under the pointer or focus, and never under reduced motion', () => {
        const hover = '.vidyalms .vy-signin-actions .vidyalms-button:is(:hover,:focus-visible)';
        expect(declared(theme, '.vidyalms .vy-signin-actions .vidyalms-button').get('transition')).toBe('none');
        expect(declared(theme, hover).get('transition')).toContain('background-color var(--_vy-dur-1)');
        const reduced = rules(theme, (rule) => rule.parent?.type === 'atrule'
            && String((rule.parent as { params?: string }).params).includes('prefers-reduced-motion:reduce')
            && rule.selectors.includes(hover));
        expect(reduced).toHaveLength(1);
        expect(reduced[0]!.selectors).toContain('.vidyalms .vy-signin-actions .vidyalms-button');
    });

    it('keeps the step numerals from pushing the step names down', () => {
        expect(declared(theme, '.vidyalms .vy-signin-step').get('line-height')).toBe('0');
        expect(declared(theme, '.vidyalms .vy-signin-step-name').get('line-height')).toBe('1.4');
        expect(declared(theme, '.vidyalms .vy-signin-step-detail').get('line-height')).toBe('1.6');
    });

    it('draws no underline under the explore arrow and keeps the link 44px tall', () => {
        const link = declared(theme, '.vidyalms .vy-signin-link');
        expect(link.get('display')).toBe('inline-block');
        expect(link.get('line-height')).toBe('44px');
        expect(link.get('min-height')).toBe('44px');
        expect(declared(theme, '.vidyalms .vy-signin-arrow').get('display')).toBe('inline-block');
    });

    it('sets its own text weight and spacing and adds no top margin, so both hosts render the same', () => {
        const section = declared(theme, '.vidyalms .vy-signin');
        expect(section.get('font-weight')).toBe('400');
        expect(section.get('letter-spacing')).toBe('normal');
        expect(section.get('margin')).toBe('0 0 36px');
    });

    it('keeps the gate title a clear step below the page heading', () => {
        const [gateMin, gateMax] = clampPx(declared(theme, '.vidyalms .vy-signin-title').get('font-size'));
        const [pageMin] = clampPx(declared(theme, '.vidyalms .vy-learning-content .vidyalms-page-title').get('font-size'));
        expect(gateMin).toBeLessThan(pageMin);
        expect(gateMax).toBeLessThanOrEqual(30);
    });
});
