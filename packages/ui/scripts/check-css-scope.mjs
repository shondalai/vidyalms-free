/*
 * Build gate: every selector in the built stylesheets must be scoped to a
 * mount element, so nothing the bundle ships can restyle the host page.
 * Also asserts the utilities the design depends on were generated
 * (font-mono went missing once when the font scale dropped the mono stack).
 *
 * Runs after `vite build`; fails the build on the first leak.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const dist = process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '..', 'dist');
const files = ['player.css', 'quiz-runner.css', 'instructor.css', 'admin.css', 'notifications.css', 'credentials.css', 'public-theme.css'];
const MOUNTS = [
    '#vidyalms-player',
    '#vidyalms-quiz-runner',
    '#vidyalms-instructor',
    '#vidyalms-admin',
    '#vidyalms-notifications',
    '#vidyalms-credentials',
    '.vidyalms',
];

/** Selector lists of every rule, with an at-rule trail for keyframe detection. */
function selectorsOf(css) {
    const found = [];
    const atRuleStack = [];
    let depth = 0;
    let buffer = '';
    for (let i = 0; i < css.length; i += 1) {
        const char = css[i];
        if (char === '{') {
            const text = buffer.trim();
            if (text.startsWith('@')) {
                atRuleStack.push({ depth, name: text });
            } else if (text !== '') {
                found.push({ selector: text, atRules: atRuleStack.map((entry) => entry.name) });
            }
            depth += 1;
            buffer = '';
        } else if (char === '}') {
            depth -= 1;
            const top = atRuleStack.at(-1);
            if (top !== undefined && top.depth === depth) {
                atRuleStack.pop();
            }
            buffer = '';
        } else {
            buffer += char;
        }
    }
    return found;
}

let failed = false;

for (const file of files) {
    const css = readFileSync(join(dist, file), 'utf8');
    const rules = selectorsOf(css);
    const leaks = rules.filter(
        ({ selector, atRules }) =>
            !atRules.some((name) => /@(-\w+-)?keyframes/.test(name)) &&
            !MOUNTS.some((mount) => selector.includes(mount)),
    );
    for (const leak of leaks) {
        failed = true;
        console.error(`[css-scope] ${file}: unscoped selector "${leak.selector.slice(0, 120)}"`);
    }
    if (file !== 'public-theme.css' && !css.includes('.font-mono')) {
        failed = true;
        console.error(`[css-scope] ${file}: the font-mono utility was not generated`);
    }
    if (leaks.length === 0) {
        console.log(`[css-scope] ${file}: ${rules.length} rules, all scoped to the mounts`);
    }
}

if (failed) {
    process.exit(1);
}
