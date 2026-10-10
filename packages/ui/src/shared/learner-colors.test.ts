import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'learner-colors.css'), 'utf8');
const pairs = new Map([...css.matchAll(/--_vy-([\w-]+):[^;]*light-dark\((#[\da-f]{6}), (#[\da-f]{6})\)/g)]
    .map((match) => [match[1], [match[2]!, match[3]!] as const]));
function luminance(hex: string) {
    const channels = [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255)
        .map((value) => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
    return channels[0]! * 0.2126 + channels[1]! * 0.7152 + channels[2]! * 0.0722;
}
function contrast(fg: string, bg: string, scheme: 0 | 1) {
    const a = luminance(pairs.get(fg)![scheme]);
    const b = luminance(pairs.get(bg)![scheme]);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

describe.each([0, 1] as const)('learner palette, scheme %s', (scheme) => {
    it.each(['fg', 'fg-2', 'accent', 'ok-strong', 'warn-strong', 'danger-strong'])('%s text stays readable on every base surface', (name) => {
        for (const bg of ['bg', 'surface', 'surface-2']) {
            expect(contrast(name, bg, scheme), `${name} on ${bg}`).toBeGreaterThanOrEqual(4.5);
        }
    });
    it('keeps active navigation, primary buttons, certificate text and activity icons readable', () => {
        expect(contrast('accent', 'accent-soft', scheme)).toBeGreaterThanOrEqual(4.5);
        expect(contrast('accent-fg', 'accent', scheme)).toBeGreaterThanOrEqual(4.5);
        expect(contrast('fg-2', 'certificate-paper', scheme)).toBeGreaterThanOrEqual(4.5);
        expect(contrast('activity-fg', 'activity-bg', scheme)).toBeGreaterThanOrEqual(3);
        expect(contrast('control-border', 'surface', scheme)).toBeGreaterThanOrEqual(3);
    });
    it('keeps the feature panel readable', () => {
        for (const name of ['feature-fg', 'feature-fg-2', 'feature-action']) {
            expect(contrast(name, 'feature', scheme), `${name} on feature`).toBeGreaterThanOrEqual(4.5);
        }
        expect(contrast('feature-action-fg', 'feature-action', scheme)).toBeGreaterThanOrEqual(4.5);
    });
});
