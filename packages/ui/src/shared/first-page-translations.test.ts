import { mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { afterAll, describe, expect, it } from 'vitest';

/*
 * The build guard against module-scope translation in anything
 * learner-navigation.js evaluates (scripts/check-app-entry-imports.mjs).
 *
 * That code runs on the first learner page, which is often a catalogue,
 * course or My learning page with no data-vy-config, so a string translated
 * at module scope there stays untranslated on every page swup swaps in
 * afterwards. The guard reads the modules back from the source maps of the
 * files the entry reaches, which is exact: whatever Rollup moves into the
 * shared chunk is checked, including a table imported from another feature.
 */

const script = pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../../scripts/check-app-entry-imports.mjs')).href;
const { eagerTranslationViolations, firstPageTranslationViolations, staticClosure } = await import(script) as {
    eagerTranslationViolations: (sources: Record<string, string>) => string[];
    firstPageTranslationViolations: (directory: string, files: Record<string, string>) => string[];
    staticClosure: (files: Record<string, string>, starts: string[]) => string[];
};

const src = join(dirname(fileURLToPath(import.meta.url)), '..');
const directories: string[] = [];

afterAll(() => {
    for (const directory of directories) rmSync(directory, { recursive: true, force: true });
});

function sourcesUnder(folder: string): Record<string, string> {
    const found: Record<string, string> = {};
    const walk = (directory: string) => {
        for (const item of readdirSync(directory, { withFileTypes: true })) {
            const path = join(directory, item.name);
            if (item.isDirectory()) walk(path);
            else if (/\.tsx?$/.test(item.name) && !/\.test\.tsx?$/.test(item.name)) {
                found[relative(join(src, '..'), path).replace(/\\/g, '/')] = readFileSync(path, 'utf8');
            }
        }
    };
    walk(join(src, folder));
    return found;
}

describe('module-scope translation', () => {
    it('flags a translate() call or a table read made while the module is evaluated', () => {
        expect(eagerTranslationViolations({
            'src/shared/eager.ts': [
                "import { translate as uiText } from '@/shared/i18n';",
                "import { sharedStrings } from './strings';",
                "export const table = { title: uiText('k', 'Title'), again: uiText('j', 'Again') };",
                'export const frozen = sharedStrings.errors;',
            ].join('\n'),
        })).toEqual(['src/shared/eager.ts translates at module scope 3 times (first: line 3 calls uiText())']);
    });

    it('passes translation deferred into a function, a localizedStrings builder, a method or an instance field', () => {
        expect(eagerTranslationViolations({
            'src/shared/lazy.ts': [
                "import { localizedStrings, translate } from './i18n';",
                "import { sharedStrings } from './strings';",
                "import type { availabilityStrings } from './availability';",
                "export const table = localizedStrings(() => ({ title: translate('k', 'Title') }));",
                "export function label() { return translate('k', 'Title') + sharedStrings.retry; }",
                "export const arrow = () => sharedStrings.errors.notFound;",
                "export class Screen { title = translate('k', 'Title'); render() { return sharedStrings.close; } }",
                'export const alias = sharedStrings;',
                'export type Copy = typeof availabilityStrings;',
            ].join('\n'),
        })).toEqual([]);
    });

    it('treats a static class field as module scope, and ignores code that never imports translate or a table', () => {
        expect(eagerTranslationViolations({
            'src/shared/static.ts': "import { translate } from '@/shared/i18n';\nexport class Screen { static title = translate('k', 'Title'); }",
            'src/shared/plain.ts': "const translate = (key: string) => key;\nexport const title = translate('k');",
        })).toEqual(['src/shared/static.ts translates at module scope once (first: line 2 calls translate())']);
    });

    it('finds no module-scope translation in the modules the shared chunk and learner-navigation.js are built from', () => {
        expect(eagerTranslationViolations({
            ...sourcesUnder('shared'),
            ...sourcesUnder('availability-reasons'),
            ...sourcesUnder('learner'),
        })).toEqual([]);
    });

    it('checks exactly the files learner-navigation.js reaches, reading their sources back from the source maps', () => {
        const directory = mkdtempSync(join(tmpdir(), 'vidyalms-first-page-'));
        directories.push(directory);
        const files = {
            'learner-navigation.js': 'import "./shared-aaaaaaaa.js"; const later = () => import("./player-cccccccc.js");',
            'shared-aaaaaaaa.js': 'export const x = 1;',
            'player-cccccccc.js': 'import "./shared-aaaaaaaa.js";',
        };
        const map = (sources: Record<string, string>) => JSON.stringify({ version: 3, sources: Object.keys(sources), sourcesContent: Object.values(sources), mappings: '' });
        writeFileSync(join(directory, 'learner-navigation.js.map'), map({ '../src/learner/main.ts': 'export {};' }));
        writeFileSync(join(directory, 'shared-aaaaaaaa.js.map'), map({
            '../src/shared/copy.ts': "import { translate } from './i18n';\nexport const copy = { title: translate('k', 'Title') };",
            '../node_modules/dep/index.js': "import { translate } from './i18n';\nexport const t = translate('k', 'x');",
        }));
        writeFileSync(join(directory, 'player-cccccccc.js.map'), map({ '../src/player/copy.ts': "import { translate } from '@/shared/i18n';\nexport const c = translate('k', 'P');" }));

        expect(staticClosure(files, ['learner-navigation.js'])).toEqual(['learner-navigation.js', 'shared-aaaaaaaa.js']);
        const violations = firstPageTranslationViolations(directory, files);
        expect(violations).toHaveLength(1);
        expect(violations[0]).toMatch(/^src\/shared\/copy\.ts translates at module scope once \(first: line 2 calls translate\(\)\); learner-navigation\.js evaluates it/);

        rmSync(join(directory, 'shared-aaaaaaaa.js.map'));
        expect(firstPageTranslationViolations(directory, files)).toEqual([
            'shared-aaaaaaaa.js has no source map, so its module-scope translations cannot be checked',
        ]);
    });
});
