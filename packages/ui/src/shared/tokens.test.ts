import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * The shipped token defaults must pass WCAG AA for every text/background
 * pair (4.5:1) and interactive control boundary (3:1) the components use, in
 * both schemes. The values are parsed out of tokens.css, so retuning a
 * default re-runs the proof; the soft-tint percentages mirror
 * tailwind.config.ts.
 */

type Rgb = [number, number, number];

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'tokens.css'), 'utf8');

function parseBlock(text: string): Map<string, string> {
    const map = new Map<string, string>();
    const pattern = /--_vy-([a-z0-9-]+):\s*var\(--vy-\1,\s*([\s\S]*?)\);/g;
    for (const match of text.matchAll(pattern)) {
        const name = match[1];
        const value = match[2];
        if (name !== undefined && value !== undefined) {
            map.set(name, value.trim());
        }
    }
    return map;
}

const darkStart = css.indexOf("[data-color-scheme='dark']");
const lightTokens = parseBlock(css.slice(0, darkStart));
const darkBlock = css.slice(darkStart, css.indexOf('}', darkStart));
const darkTokens = new Map(lightTokens);
for (const [name, value] of parseBlock(darkBlock)) {
    darkTokens.set(name, value);
}

function resolve(tokens: Map<string, string>, name: string): string {
    const raw = tokens.get(name);
    if (raw === undefined) {
        throw new Error(`token ${name} missing from tokens.css`);
    }
    const reference = raw.match(/^var\(--_vy-([a-z0-9-]+)\)$/);
    return reference?.[1] !== undefined ? resolve(tokens, reference[1]) : raw;
}

function hexToRgb(hex: string): Rgb {
    const value = parseInt(hex.slice(1), 16);
    return [(value >> 16) & 255, (value >> 8) & 255, value & 255].map((v) => v / 255) as Rgb;
}

/** oklch -> sRGB (Ottosson's OKLab matrices), clamped to gamut. */
function oklchToRgb(l: number, c: number, hDeg: number): Rgb {
    const h = (hDeg * Math.PI) / 180;
    const a = c * Math.cos(h);
    const b = c * Math.sin(h);
    const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3;
    const linear: Rgb = [
        4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_,
        -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_,
        -0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_,
    ];
    return linear.map((v) => {
        const gamma = v <= 0.0031308 ? 12.92 * v : 1.055 * v ** (1 / 2.4) - 0.055;
        return Math.min(1, Math.max(0, gamma));
    }) as Rgb;
}

function parseColor(value: string): Rgb {
    if (value.startsWith('#')) {
        return hexToRgb(value);
    }
    const oklch = value.match(/^oklch\(([\d.]+)%\s+([\d.]+)\s+([\d.]+)\)$/);
    if (oklch?.[1] !== undefined && oklch[2] !== undefined && oklch[3] !== undefined) {
        return oklchToRgb(Number(oklch[1]) / 100, Number(oklch[2]), Number(oklch[3]));
    }
    throw new Error(`unparseable colour: ${value}`);
}

function color(tokens: Map<string, string>, name: string): Rgb {
    return parseColor(resolve(tokens, name));
}

/** The colour of `tint` at `alpha` composited over `backdrop`. */
function over(tint: Rgb, alpha: number, backdrop: Rgb): Rgb {
    return tint.map((v, i) => v * alpha + (backdrop[i] ?? 0) * (1 - alpha)) as Rgb;
}

function luminance(rgb: Rgb): number {
    const [r, g, b] = rgb.map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return 0.2126 * (r ?? 0) + 0.7152 * (g ?? 0) + 0.0722 * (b ?? 0);
}

function contrast(a: Rgb, b: Rgb): number {
    const la = luminance(a);
    const lb = luminance(b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];
    return (hi + 0.05) / (lo + 0.05);
}

function accentSoftAlpha(tokens: Map<string, string>): number {
    const raw = resolve(tokens, 'accent-soft').match(/([\d.]+)%/);
    if (raw?.[1] === undefined) {
        throw new Error('accent-soft is not a color-mix percentage');
    }
    return Number(raw[1]) / 100;
}

/* Soft tint strengths from tailwind.config.ts. */
const OK_SOFT = 0.1;
const WARN_SOFT = 0.12;
const DANGER_SOFT = 0.1;

function pairsOf(tokens: Map<string, string>): [string, Rgb, Rgb][] {
    const surface = color(tokens, 'surface');
    const accent = color(tokens, 'accent');
    const ok = color(tokens, 'ok');
    const warn = color(tokens, 'warn');
    const danger = color(tokens, 'danger');
    return [
        ['fg on surface', color(tokens, 'fg'), surface],
        ['fg on bg', color(tokens, 'fg'), color(tokens, 'bg')],
        ['fg-2 on surface', color(tokens, 'fg-2'), surface],
        ['fg-2 on bg', color(tokens, 'fg-2'), color(tokens, 'bg')],
        ['fg-2 on surface-2', color(tokens, 'fg-2'), color(tokens, 'surface-2')],
        ['accent-fg on accent', color(tokens, 'accent-fg'), accent],
        ['accent on surface', accent, surface],
        ['accent on accent-soft', accent, over(accent, accentSoftAlpha(tokens), surface)],
        ['ok-strong on ok-soft', color(tokens, 'ok-strong'), over(ok, OK_SOFT, surface)],
        ['ok-strong on surface', color(tokens, 'ok-strong'), surface],
        ['warn-strong on warn-soft', color(tokens, 'warn-strong'), over(warn, WARN_SOFT, surface)],
        ['warn-strong on surface', color(tokens, 'warn-strong'), surface],
        ['danger-strong on danger-soft', color(tokens, 'danger-strong'), over(danger, DANGER_SOFT, surface)],
        ['danger-strong on surface', color(tokens, 'danger-strong'), surface],
        ['fg-2 on warn-soft', color(tokens, 'fg-2'), over(warn, WARN_SOFT, surface)],
    ];
}

function controlBoundaryPairsOf(tokens: Map<string, string>): [string, Rgb, Rgb][] {
    const boundary = color(tokens, 'control-border');
    return [
        ['control border on surface', boundary, color(tokens, 'surface')],
        ['control border on page canvas', boundary, color(tokens, 'bg')],
        ['control border on muted surface', boundary, color(tokens, 'surface-2')],
    ];
}

describe.each([
    ['light', lightTokens],
    ['dark', darkTokens],
] as const)('%s scheme token pairs', (_scheme, tokens) => {
    it.each(pairsOf(tokens).map(([name, fg, bg]) => [name, contrast(fg, bg)] as const))(
        '%s meets AA (%f)',
        (_name, ratio) => {
            expect(ratio).toBeGreaterThanOrEqual(4.5);
        },
    );

    it.each(
        controlBoundaryPairsOf(tokens).map(([name, boundary, background]) => [
            name,
            contrast(boundary, background),
        ] as const),
    )('%s meets non-text AA (%f)', (_name, ratio) => {
        expect(ratio).toBeGreaterThanOrEqual(3);
    });
});

it('scopes the stronger boundary token to interactive controls', () => {
    expect(css).toMatch(
        /:is\(#vidyalms-player,[\s\S]*?#vidyalms-credentials\)[\s\S]*?:is\(button, input, select, textarea,[\s\S]*?\)\s*\{\s*--_vy-border:\s*var\(--_vy-control-border\);\s*\}/,
    );
});

it('makes labels inherit the mounted theme without changing host labels', () => {
    expect(css).toMatch(
        /:is\(#vidyalms-player,[\s\S]*?#vidyalms-credentials\)\s*:is\(button, input, select, textarea, label\)\s*\{\s*font: inherit;\s*color: inherit;\s*letter-spacing: inherit;\s*\}/,
    );
    expect(css).not.toMatch(/(?:^|\})\s*label\s*\{/);
});

it('isolates only the colliding bare border default, preserving host token overrides', () => {
    expect(css).toMatch(
        /:is\(#vidyalms-player,[\s\S]*?#vidyalms-credentials\)\s*\.border\s*\{\s*border-color: var\(--_vy-border\) !important;\s*border-style: solid !important;\s*\}/,
    );
    expect(css.match(/!important;/g)).toHaveLength(2);
});

it('lets an explicit light mount override a dark host ancestor', () => {
    const explicitLightStart = css.indexOf("#vidyalms-player[data-color-scheme='light']");
    expect(explicitLightStart).toBeGreaterThan(darkStart);

    const explicitLightBlock = parseBlock(css.slice(explicitLightStart, css.indexOf('}', explicitLightStart)));
    for (const name of [
        'bg',
        'surface',
        'surface-2',
        'fg',
        'fg-2',
        'border',
        'control-border',
        'accent',
        'accent-fg',
        'accent-soft',
        'ok',
        'warn',
        'danger',
        'ok-strong',
        'warn-strong',
        'danger-strong',
    ]) {
        expect(explicitLightBlock.get(name), `${name} explicit-light default`).toBe(lightTokens.get(name));
    }
});
