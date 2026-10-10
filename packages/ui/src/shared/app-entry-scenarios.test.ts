import { mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { afterAll, describe, expect, it } from 'vitest';
import { adminConfigSchema } from '@/admin/config';
import { credentialConfigSchema } from '@/credentials/config';
import { instructorConfigSchema } from '@/instructor/config';
import { notificationConfigSchema } from '@/notifications/config';
import { mountConfigSchema } from './config';

/*
 * What scripts/check-entry-evaluation.mjs runs beyond "each entry alone":
 * every app with a config its own schema accepts, so the mount and render
 * path really runs (review of 2026-09-30: with the base config alone, four
 * apps stopped at the malformed-config notice and a throw queued by the
 * player's render passed); every lazy chunk an entry can reach; and every
 * learner entry after learner-navigation.js, on its native page and across
 * an SPA swap (before the fix the quiz page died that way while quiz-runner.js
 * alone survived). Every URL is on the reserved .invalid domain.
 */

interface Scenario {
    name: string;
    entries: string[];
    mount: string | null;
    spa: boolean;
    lazy: string[];
    expectMount: boolean;
}

interface EvaluationResult {
    scenario: string;
    entry: string;
    ok: boolean;
    phase?: string;
    error?: { name: string; message: string; stack: string };
}

type Manifest = Record<string, { file: string; isEntry?: boolean; imports?: string[]; dynamicImports?: string[] }>;

const script = pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../../scripts/check-entry-evaluation.mjs')).href;
const guard = await import(script) as {
    entryConfigs: Record<string, unknown>;
    learnerPageEntries: string[];
    aloneScenario: (entry: string, lazy?: string[], expectMount?: boolean) => Scenario;
    nativePageScenario: (entry: string) => Scenario;
    spaSwapScenario: (entry: string) => Scenario;
    evaluateScenario: (directory: string, scenario: Scenario) => Promise<EvaluationResult>;
    evaluationViolations: (results: EvaluationResult[]) => string[];
    lazyChunksOf: (manifest: Manifest, entryKey: string) => string[];
    buildScenarios: (manifest: Manifest) => Scenario[];
};

const src = join(dirname(fileURLToPath(import.meta.url)), '..');
const directories: string[] = [];

function output(files: Record<string, string>): string {
    const directory = mkdtempSync(join(tmpdir(), 'vidyalms-entry-scenarios-'));
    directories.push(directory);
    writeFileSync(join(directory, 'package.json'), '{"type":"module"}\n');
    for (const [file, code] of Object.entries(files)) writeFileSync(join(directory, file), code);
    return directory;
}

afterAll(() => {
    for (const directory of directories) rmSync(directory, { recursive: true, force: true });
});

/** A stand-in app entry: renders into its mount, as the real ones do through mountApp. */
const renders = (id: string) => `document.getElementById("${id}").textContent = "mounted";`;

describe('the evaluation configs', () => {
    it('satisfy each app\'s own mount schema', () => {
        const schemas: Record<string, { parse: (raw: unknown) => unknown }> = {
            'player.js': mountConfigSchema,
            'quiz-runner.js': mountConfigSchema,
            'instructor.js': instructorConfigSchema,
            'admin.js': adminConfigSchema,
            'notifications.js': notificationConfigSchema,
            'credentials.js': credentialConfigSchema,
        };
        expect(Object.keys(guard.entryConfigs).sort()).toEqual(Object.keys(schemas).sort());
        for (const [entry, schema] of Object.entries(schemas)) {
            expect(() => schema.parse(guard.entryConfigs[entry]), entry).not.toThrow();
        }
    });

    it('pair learner-navigation.js with exactly the entries that mount inside the learner shell', () => {
        const learner = readdirSync(src, { withFileTypes: true })
            .filter((item) => item.isDirectory())
            .filter((item) => {
                try {
                    return /learnerNavigation:\s*true/.test(readFileSync(join(src, item.name, 'main.tsx'), 'utf8'));
                } catch {
                    return false;
                }
            })
            .map((item) => `${item.name}.js`)
            .sort();
        expect(guard.learnerPageEntries).toEqual(learner);
    });
});

describe('build scenarios', () => {
    const manifest: Manifest = {
        'src/admin/main.tsx': { file: 'admin.js', isEntry: true, imports: ['_shared.js'], dynamicImports: ['src/admin/screen.tsx'] },
        'src/admin/screen.tsx': { file: 'screen-aaaaaaaa.js', imports: ['_shared.js', '_editor.js'], dynamicImports: ['src/admin/deeper.tsx'] },
        'src/admin/deeper.tsx': { file: 'deeper-bbbbbbbb.js', imports: ['_shared.js'] },
        '_editor.js': { file: 'editor-cccccccc.js' },
        '_shared.js': { file: 'shared-dddddddd.js', dynamicImports: ['src/admin/screen.tsx'] },
        'src/player/main.tsx': { file: 'player.js', isEntry: true, imports: ['_shared.js'] },
        'src/admin/styles.css': { file: 'admin.css', isEntry: true },
    };

    it('reaches every lazy chunk, including those behind another lazy chunk or a shared dependency, and none loaded eagerly', () => {
        expect(guard.lazyChunksOf(manifest, 'src/admin/main.tsx')).toEqual(['editor-cccccccc.js', 'deeper-bbbbbbbb.js', 'screen-aaaaaaaa.js']);
        expect(guard.lazyChunksOf(manifest, 'src/player/main.tsx')).toEqual(['editor-cccccccc.js', 'deeper-bbbbbbbb.js', 'screen-aaaaaaaa.js']);
    });

    it('runs every entry alone expecting a mount, then every learner entry after learner-navigation.js natively and across a swap', () => {
        const full: Manifest = { ...manifest };
        for (const entry of ['learner-navigation', 'credentials', 'instructor', 'notifications', 'quiz-runner']) {
            full[`src/${entry}/main.tsx`] = { file: `${entry}.js`, isEntry: true };
        }
        const names = guard.buildScenarios(full).map((scenario) => `${scenario.name}${scenario.expectMount ? ' [mount]' : ''}`);
        expect(names).toEqual([
            'admin.js [mount]', 'credentials.js [mount]', 'instructor.js [mount]', 'learner-navigation.js', 'notifications.js [mount]',
            'player.js [mount]', 'quiz-runner.js [mount]',
            ...guard.learnerPageEntries.map((entry) => `learner-navigation.js + ${entry} (native page) [mount]`),
            ...guard.learnerPageEntries.map((entry) => `learner-navigation.js then ${entry} (SPA swap) [mount]`),
        ]);
        expect(() => guard.buildScenarios(manifest)).toThrow(/Expected app entry learner-navigation\.js is missing/);
    });
});

describe('scenario evaluation', () => {
    it('fails a known app that shows the malformed-config notice, naming the detail, and one that renders nothing', async () => {
        const directory = output({
            'player.js': [
                'const mount = document.getElementById("vidyalms-player");',
                'mount.innerHTML = \'<div role="alert" data-vy-config-error><strong>VidyaLMS could not start.</strong><span></span><code>course: required</code></div>\';',
            ].join('\n'),
            'credentials.js': 'globalThis.nothingRendered = true;',
        });

        const notice = await guard.evaluateScenario(directory, guard.aloneScenario('player.js', [], true));
        const empty = await guard.evaluateScenario(directory, guard.aloneScenario('credentials.js', [], true));

        expect(guard.evaluationViolations([notice, empty])).toEqual([
            "player.js fails after mounting: MountError: #vidyalms-player shows the malformed-config notice, so this gate's config"
                + " no longer satisfies the app's mount schema: course: required",
            'credentials.js fails after mounting: MountError: #vidyalms-credentials is still empty; the app never rendered',
        ]);
    }, 60000);

    it('fails an error the app caught and reported with the [vidyalms] prefix, as the error boundary does', async () => {
        const directory = output({
            'notifications.js': `${renders('vidyalms-notifications')}\nconsole.error("[vidyalms]", new TypeError("render failed"), "component stack");`,
        });

        const result = await guard.evaluateScenario(directory, guard.aloneScenario('notifications.js', [], true));

        expect(result).toMatchObject({ ok: false, phase: 'while its page settled after evaluation', error: { name: 'TypeError', message: 'render failed' } });
    }, 60000);

    it('evaluates the lazy chunks after the entry and fails the one that throws at module scope', async () => {
        const directory = output({
            'admin.js': renders('vidyalms-admin'),
            'screen-aaaaaaaa.js': 'globalThis.screenRan = true;',
            'operations-bbbbbbbb.js': 'if (!globalThis.screenRan) throw new Error("order"); throw new RangeError("lazy screen failed at load");',
        });

        const result = await guard.evaluateScenario(directory, guard.aloneScenario('admin.js', ['screen-aaaaaaaa.js', 'operations-bbbbbbbb.js'], true));

        expect(guard.evaluationViolations([result])).toEqual([
            expect.stringMatching(/^admin\.js fails during evaluation of its lazy chunk operations-bbbbbbbb\.js: RangeError: lazy screen failed at load/),
        ]);
    }, 60000);

    it('catches an entry that only fails after learner-navigation.js, on the native page and across a swap', async () => {
        const directory = output({
            'learner-navigation.js': 'globalThis.navigationLoaded = true;',
            'quiz-runner.js': `if (globalThis.navigationLoaded) throw new ReferenceError("shared was already poisoned");\n${renders('vidyalms-quiz-runner')}`,
        });

        const alone = await guard.evaluateScenario(directory, guard.aloneScenario('quiz-runner.js', [], true));
        const native = await guard.evaluateScenario(directory, guard.nativePageScenario('quiz-runner.js'));
        const swapped = await guard.evaluateScenario(directory, guard.spaSwapScenario('quiz-runner.js'));

        expect(alone.ok).toBe(true);
        expect(guard.evaluationViolations([native, swapped])).toEqual([
            expect.stringMatching(/^learner-navigation\.js \+ quiz-runner\.js \(native page\) fails during module evaluation of quiz-runner\.js: ReferenceError/),
            expect.stringMatching(/^learner-navigation\.js then quiz-runner\.js \(SPA swap\) fails during module evaluation of quiz-runner\.js: ReferenceError/),
        ]);
    }, 60000);

    it('swaps the mount in the way navigation.ts does: absent while learner-navigation.js runs, pending while the entry loads, then the mount event', async () => {
        const directory = output({
            'learner-navigation.js': [
                'if (document.getElementById("vidyalms-player")) throw new Error("the mount was there before the swap");',
                'window.addEventListener("vidyalms:learner:mount", () => { globalThis.mountEvent = true; });',
            ].join('\n'),
            'player.js': [
                'if (!document.querySelector("[data-vy-mount-pending]")) throw new Error("the entry loaded outside the pending swap");',
                'window.addEventListener("vidyalms:learner:mount", () => {',
                '  if (document.querySelector("[data-vy-mount-pending]")) throw new Error("mount event while still pending");',
                `  ${renders('vidyalms-player')}`,
                '});',
            ].join('\n'),
        });

        const result = await guard.evaluateScenario(directory, guard.spaSwapScenario('player.js'));

        expect(result).toMatchObject({ ok: true, scenario: 'learner-navigation.js then player.js (SPA swap)' });
    }, 60000);
});
