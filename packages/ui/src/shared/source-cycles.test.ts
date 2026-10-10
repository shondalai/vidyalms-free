import { describe, expect, it } from 'vitest';
import { build } from 'vite';
import { appSourceCycleGuard, moduleCycles } from '../../vite.config';

/*
 * The build gate against static import cycles between the app's own source
 * modules (vite.config.ts). The chunk guard cannot see a cycle inside one
 * chunk, and Rollup prints no warning for it; shared/availability.ts and
 * availability-reasons/free.ts were one, in the shared chunk, and one eager
 * read across it made every entry die with "Cannot access 'P' before
 * initialization" (review probe of 2026-09-30).
 */

const ROOT = '/virtual-app/src';

/** Builds the given modules in memory with the guard; each id is ROOT plus its name. */
async function buildWith(modules: Record<string, string>, entry: string) {
    const ids = Object.fromEntries(Object.entries(modules).map(([name, code]) => [`${ROOT}/${name}`, code]));
    return build({
        configFile: false,
        logLevel: 'silent',
        publicDir: false,
        plugins: [
            {
                name: 'source-cycle-fixture',
                enforce: 'pre',
                resolveId: (id, importer) => {
                    if (id in ids) return id;
                    if (importer && id.startsWith('./')) return `${ROOT}/${id.slice(2)}`;
                    return null;
                },
                load: (id) => ids[id] ?? null,
            },
            appSourceCycleGuard(ROOT),
        ],
        build: { write: false, minify: false, rollupOptions: { input: `${ROOT}/${entry}` } },
    });
}

describe('source module cycles', () => {
    it('finds each cycle once, from the first module it reaches, and none in a diamond', () => {
        const graph = new Map<string, string[]>([
            ['availability.ts', ['free.ts', 'i18n.ts']],
            ['free.ts', ['availability.ts']],
            ['player.ts', ['availability.ts', 'strings.ts']],
            ['strings.ts', ['i18n.ts']],
            ['i18n.ts', []],
            ['self.ts', ['self.ts']],
        ]);

        expect(moduleCycles(graph)).toEqual([['availability.ts', 'free.ts', 'availability.ts'], ['self.ts', 'self.ts']]);
        expect(moduleCycles(new Map([['a', ['b', 'c']], ['b', ['d']], ['c', ['d']], ['d', []]]))).toEqual([]);
    });

    it('fails a build whose sources import each other and names the cycle', async () => {
        await expect(buildWith({
            'main.ts': "import { reasonText } from './availability.ts'; console.log(reasonText());",
            'availability.ts': "import { register } from './free.ts'; export const strings = { closed: 'Closed' }; export function reasonText() { return register(); }",
            'free.ts': "import { strings } from './availability.ts'; export function register() { return strings.closed; }",
        }, 'main.ts')).rejects.toThrow(/static import cycle between source modules: availability\.ts -> free\.ts -> availability\.ts/);
    });

    it('passes the same modules once the shared part lives in a leaf both import', async () => {
        await expect(buildWith({
            'main.ts': "import { reasonText } from './availability.ts'; console.log(reasonText());",
            'availability.ts': "import { register } from './free.ts'; export { strings } from './availability-text.ts'; export function reasonText() { return register(); }",
            'availability-text.ts': "export const strings = { closed: 'Closed' };",
            'free.ts': "import { strings } from './availability-text.ts'; export function register() { return strings.closed; }",
            'later.ts': "import './main.ts'; export const late = 1;",
        }, 'main.ts')).resolves.toBeDefined();
    });

    it('does not count a dynamic import back to the importer', async () => {
        await expect(buildWith({
            'main.ts': "export const value = 1; export const open = () => import('./screen.ts');",
            'screen.ts': "import { value } from './main.ts'; export const screen = value;",
        }, 'main.ts')).resolves.toBeDefined();
    });
});
