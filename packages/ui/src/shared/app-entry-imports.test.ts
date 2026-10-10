import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { describe, expect, it } from 'vitest';
import { build } from 'vite';
import { appManualChunk, appOutputNames } from '../../vite.config';
import { buildChunkLayout } from './testing/chunk-cycle-fixture';

const script = pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../../scripts/check-app-entry-imports.mjs')).href;
const {
    entryImportViolations, importsOf, cacheSafetyViolations, staticImportGraph, staticImportCycles, staticImportCycleViolations,
} = await import(script) as {
    entryImportViolations: (files: Record<string, string>, entries: string[], lazyFiles?: string[]) => string[];
    importsOf: (code: string) => { path: string; dynamic: boolean }[];
    cacheSafetyViolations: (files: Record<string, string>, entries: string[]) => string[];
    staticImportGraph: (files: Record<string, string>) => Map<string, string[]>;
    staticImportCycles: (files: Record<string, string>) => string[][];
    staticImportCycleViolations: (files: Record<string, string>) => string[];
};

describe('cache-versioned app entry isolation', () => {
    it.each([
        'import { value } from "./admin.js";',
        'import "./admin.js?version=9";',
        'export { value } from "./admin.js";',
        'export * from "./admin.js";',
        'const load = () => import("./admin.js");',
        'const load = () => import(`./admin.js`);',
        'import "./nested/../admin.js#fragment";',
    ])('rejects a mounting entry dependency: %s', (code) => {
        expect(entryImportViolations({ 'screen.js': code, 'admin.js': 'mount();' }, ['admin.js'])).toHaveLength(1);
    });

    it('checks every app entry, including entry-to-entry dependencies', () => {
        expect(entryImportViolations({ 'credentials.js': 'import "./instructor.js";', 'instructor.js': 'mount();' }, ['credentials.js', 'instructor.js'])).toHaveLength(1);
    });

    it('does not mistake comments, ordinary strings or property calls for imports', () => {
        const code = `/* import "./admin.js" */ const hint = 'import("./admin.js")'; const x = { import() {} }; x.import("./admin.js");`;
        expect(importsOf(code)).toEqual([]);
        expect(entryImportViolations({ 'screen.js': code }, ['admin.js'])).toEqual([]);
    });

    it('allows shared foundations while retaining three separate lazy feature screens', () => {
        const files = {
            'admin.js': 'import "./admin-foundation.js"; import("./templates.js"); import("./organisations.js"); import("./operations.js");',
            'admin-foundation.js': 'export const context = {};',
            'templates.js': 'import "./admin-foundation.js";',
            'organisations.js': 'import "./admin-foundation.js";',
            'operations.js': 'import "./admin-foundation.js";',
        };
        expect(entryImportViolations(files, ['admin.js'], ['templates.js', 'organisations.js', 'operations.js'])).toEqual([]);
    });

    it('rejects accidentally eager screens, including transitive static reachability', () => {
        expect(entryImportViolations({ 'admin.js': 'import "./foundation.js";', 'foundation.js': 'export * from "./templates.js";', 'templates.js': 'screen();' }, ['admin.js'], ['templates.js'])).toEqual([
            'templates.js is statically reachable from an app entry; the screen is no longer lazy',
        ]);
    });

    it.each(['config.ts', 'strings.ts', 'credentials/studio-strings.ts', 'credentials/element-settings.tsx', 'components/list-frame.tsx', 'organisations/strings.ts', 'operations/strings.ts'])('places shared admin module %s outside the mount entry', (path) => {
        expect(appManualChunk(`D:/project/src/admin/${path}`)).toBe('admin-foundation');
        expect(appManualChunk(`D:\\project\\src\\admin\\${path.replaceAll('/', '\\')}`)).toBe('admin-foundation');
    });

    it.each(['main.tsx', 'app.tsx', 'credentials/templates-tab.tsx', 'organisations/screen.tsx', 'operations/screens.tsx'])('does not force entry or lazy feature %s into the foundation', (path) => {
        expect(appManualChunk(`/project/src/admin/${path}`)).toBeUndefined();
    });

    it('keeps all CSS with its app and retains existing shared/vendor/question-type boundaries', () => {
        expect(appManualChunk('/project/src/admin/styles.css')).toBeUndefined();
        expect(appManualChunk('/project/node_modules/font/index.css?used')).toBeUndefined();
        expect(appManualChunk('/project/node_modules/react/index.js')).toBe('vendor');
        expect(appManualChunk('/project/src/shared/context.tsx')).toBe('shared');
        expect(appManualChunk('/project/src/question-types/registry.ts')).toBe('question-types');
    });

    it('rejects unversioned secondary files and missing transitive chunks', () => {
        expect(cacheSafetyViolations({ 'admin.js': 'import "./shared.js";', 'shared.js': 'export const x = 1;' }, ['admin.js'])).toEqual([
            'shared.js is a secondary JavaScript file without a content hash',
        ]);
        expect(cacheSafetyViolations({ 'admin.js': 'import("./screen-abcdefgh.js");' }, ['admin.js'])).toEqual([
            'admin.js imports missing JavaScript screen-abcdefgh.js',
        ]);
    });

    it('keeps native entry/CSS names stable and hashes secondary JavaScript', () => {
        expect(appOutputNames).toEqual({ entryFileNames: '[name].js', chunkFileNames: '[name]-[hash].js', assetFileNames: '[name][extname]' });
    });

    it('a changed dependency gives both the shared and lazy chunks new URLs on a warm-cache upgrade', async () => {
        async function fixture(version: string) {
            const modules: Record<string, string> = {
                '\0virtual:admin': 'import { value } from "virtual:foundation"; globalThis.fixture = value; globalThis.openFixture = () => import("virtual:screen");',
                '\0virtual:foundation': `export const value = ${JSON.stringify(version)};`,
                '\0virtual:screen': 'import { value } from "virtual:foundation"; export const screenValue = value;',
            };
            // Real Vite/Rollup output, entirely in memory: no fixture files,
            // package installation, browser, CMS or production dist writes.
            const result = await build({
                configFile: false, logLevel: 'silent', publicDir: false,
                plugins: [{ name: 'entry-cache-fixture', resolveId: (id) => id.startsWith('virtual:') ? `\0${id}` : null,
                    load: (id) => modules[id] ?? null }],
                build: { write: false, minify: false, modulePreload: false,
                    rollupOptions: { input: { admin: 'virtual:admin' }, output: {
                        ...appOutputNames, manualChunks: (id) => id === '\0virtual:foundation' ? 'foundation' : undefined,
                    } },
                },
            });
            if (Array.isArray(result) || !('output' in result)) throw new Error('Expected one in-memory build output.');
            const chunks = result.output.filter((item) => item.type === 'chunk');
            const files = Object.fromEntries(chunks.map((chunk) => [chunk.fileName, chunk.code]));
            const foundation = chunks.find((chunk) => chunk.name === 'foundation')!;
            const screen = chunks.find((chunk) => chunk.isDynamicEntry)!;
            expect(entryImportViolations(files, ['admin.js'], [screen.fileName])).toEqual([]);
            expect(cacheSafetyViolations(files, ['admin.js'])).toEqual([]);
            return { files, foundation: foundation.fileName, screen: screen.fileName };
        }
        const before = await fixture('before'); const after = await fixture('after');
        expect(after.foundation).not.toBe(before.foundation);
        expect(after.screen).not.toBe(before.screen);
        expect(after.files['admin.js']).toContain(after.foundation);
        expect(after.files['admin.js']).toContain(after.screen);
        expect(after.files['admin.js']).not.toContain(before.foundation);
        expect(after.files['admin.js']).not.toContain(before.screen);
        expect(after.files[after.screen]).toContain(after.foundation);
    });
});

describe('static import cycles between built chunks', () => {
    it('names a two chunk cycle once, from its alphabetically first file', () => {
        const files = {
            'shared-aaaaaaaa.js': 'import { v } from "./question-types-bbbbbbbb.js"; export const t = () => v;',
            'question-types-bbbbbbbb.js': 'import { t } from "./shared-aaaaaaaa.js"; export const v = t();',
        };
        expect(staticImportCycles(files)).toEqual([['question-types-bbbbbbbb.js', 'shared-aaaaaaaa.js', 'question-types-bbbbbbbb.js']]);
        expect(staticImportCycleViolations(files)).toEqual([
            'static import cycle between built chunks: question-types-bbbbbbbb.js -> shared-aaaaaaaa.js -> question-types-bbbbbbbb.js'
                + '; whichever of them a page loads first runs the other against bindings that do not exist yet',
        ]);
    });

    it('follows bare imports and both re-export forms round a longer cycle', () => {
        const files = {
            'a-aaaaaaaa.js': 'import "./b-bbbbbbbb.js";',
            'b-bbbbbbbb.js': 'export * from "./c-cccccccc.js";',
            'c-cccccccc.js': 'export { value } from "./a-aaaaaaaa.js";',
        };
        expect(staticImportCycles(files)).toEqual([['a-aaaaaaaa.js', 'b-bbbbbbbb.js', 'c-cccccccc.js', 'a-aaaaaaaa.js']]);
    });

    it('reports one shortest cycle per tangle and one per separate tangle', () => {
        const files = {
            'a-aaaaaaaa.js': 'import "./b-bbbbbbbb.js"; import "./c-cccccccc.js";',
            'b-bbbbbbbb.js': 'import "./a-aaaaaaaa.js";',
            'c-cccccccc.js': 'import "./b-bbbbbbbb.js";',
            'x-xxxxxxxx.js': 'import "./x-xxxxxxxx.js?again";',
        };
        expect(staticImportCycles(files)).toEqual([
            ['a-aaaaaaaa.js', 'b-bbbbbbbb.js', 'a-aaaaaaaa.js'],
            ['x-xxxxxxxx.js', 'x-xxxxxxxx.js'],
        ]);
    });

    it('does not count a dynamic import back to the importer, which runs after it has finished', () => {
        const files = {
            'admin.js': 'import "./foundation-aaaaaaaa.js"; const open = () => import("./screen-bbbbbbbb.js");',
            'foundation-aaaaaaaa.js': 'const back = () => import("./admin.js#reload"); export const x = back;',
            'screen-bbbbbbbb.js': 'import "./foundation-aaaaaaaa.js";',
        };
        expect(staticImportCycles(files)).toEqual([]);
        expect(staticImportGraph(files).get('foundation-aaaaaaaa.js')).toEqual([]);
    });

    it('accepts one foundation shared by many chunks, which is a diamond and not a cycle', () => {
        const files = {
            'player.js': 'import "./lib-aaaaaaaa.js"; import "./shared-bbbbbbbb.js";',
            'quiz-runner.js': 'import "./question-types-cccccccc.js"; import "./shared-bbbbbbbb.js";',
            'lib-aaaaaaaa.js': 'import "./shared-bbbbbbbb.js";',
            'question-types-cccccccc.js': 'import "./shared-bbbbbbbb.js"; import "./vendor-dddddddd.js";',
            'shared-bbbbbbbb.js': 'import "./vendor-dddddddd.js";',
            'vendor-dddddddd.js': 'export const react = {};',
        };
        expect(staticImportCycles(files)).toEqual([]);
    });

    it('catches the 2026-09-30 layout in real Rollup output and clears the fixed one', async () => {
        const broken = await buildChunkLayout('broken');
        const fixed = await buildChunkLayout('fixed');
        const cycles = staticImportCycles(broken.files);
        expect(cycles).toHaveLength(1);
        expect(cycles[0]?.map((file) => file.replace(/-[A-Za-z0-9_-]{8}\.js$/, ''))).toEqual(['question-types', 'shared', 'question-types']);
        expect(staticImportCycles(fixed.files)).toEqual([]);
        expect(entryImportViolations(fixed.files, fixed.entries)).toEqual([]);
        expect(cacheSafetyViolations(fixed.files, fixed.entries)).toEqual([]);
        const shared = Object.keys(fixed.files).find((file) => file.startsWith('shared-'))!;
        expect(staticImportGraph(fixed.files).get(shared)).toEqual([]);
        expect(staticImportGraph(fixed.files).get('player.js')).toEqual([shared]);
    }, 30000);
});
