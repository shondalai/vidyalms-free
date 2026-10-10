import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { afterAll, describe, expect, it } from 'vitest';
import { buildChunkLayout } from './testing/chunk-cycle-fixture';

/*
 * The runtime post-build gate, scripts/check-entry-evaluation.mjs, which the
 * build runs over dist/ after the import graph checks.
 *
 * A static graph can be perfectly legal and an entry still die at load; the
 * learner player of 2026-09-30 did, on a t() call that reached a shared
 * binding in its temporal dead zone. These cases prove the gate fails that
 * layout, passes the fixed one, isolates every entry, and keeps the network
 * off. Every URL below is on the reserved .invalid domain, so even a broken
 * stub could not reach a real host.
 */

interface EvaluationResult {
    entry: string;
    ok: boolean;
    phase?: string;
    offlineRequests?: number;
    error?: { name: string; message: string; stack: string };
    more?: number;
}

const script = pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../../scripts/check-entry-evaluation.mjs')).href;
const { entryEvaluationViolations, evaluateEntry, evaluationViolations, minimalConfig, mountIdOf } = await import(script) as {
    entryEvaluationViolations: (directory: string, entries: string[]) => Promise<string[]>;
    evaluateEntry: (directory: string, entry: string) => Promise<EvaluationResult>;
    evaluationViolations: (results: EvaluationResult[]) => string[];
    minimalConfig: { api: { base: string } };
    mountIdOf: (entry: string) => string;
};

const directories: string[] = [];

/** A throwaway output folder; package.json makes Node read the .js files as ES modules, as dist/ is read. */
function output(files: Record<string, string>): string {
    const directory = mkdtempSync(join(tmpdir(), 'vidyalms-entry-evaluation-'));
    directories.push(directory);
    writeFileSync(join(directory, 'package.json'), '{"type":"module"}\n');
    for (const [file, code] of Object.entries(files)) writeFileSync(join(directory, file), code);
    return directory;
}

afterAll(() => {
    for (const directory of directories) rmSync(directory, { recursive: true, force: true });
});

describe('built entry evaluation', () => {
    it('fails the 2026-09-30 layout at load, naming the entry and the binding error, and passes the fixed one', async () => {
        const broken = await buildChunkLayout('broken');
        const fixed = await buildChunkLayout('fixed');

        const failures = await entryEvaluationViolations(output(broken.files), ['player.js']);

        expect(failures).toHaveLength(1);
        expect(failures[0]).toMatch(/^player\.js fails during module evaluation: ReferenceError: Cannot access '\w+' before initialization/);
        expect(await entryEvaluationViolations(output(fixed.files), fixed.entries)).toEqual([]);
    }, 60000);

    it('gives each entry its own page with the learner shell, its mount and the minimal config', async () => {
        const directory = output({
            'first.js': [
                'const mount = document.getElementById("vidyalms-first");',
                'const config = JSON.parse(mount.getAttribute("data-vy-config"));',
                'if (!mount.closest("[data-vy-learner-shell]")) throw new Error("no learner shell");',
                `if (config.api.base !== ${JSON.stringify(minimalConfig.api.base)}) throw new Error("no minimal config");`,
                'globalThis.firstRan = true;',
            ].join('\n'),
            'second.js': 'if (globalThis.firstRan) throw new Error("shared a page with another entry");',
        });

        expect(mountIdOf('quiz-runner.js')).toBe('vidyalms-quiz-runner');
        expect(await entryEvaluationViolations(directory, ['first.js', 'second.js'])).toEqual([]);
    }, 60000);

    it('answers every fetch and XMLHttpRequest locally instead of reaching the network', async () => {
        const directory = output({
            'network.js': [
                'const answer = await fetch("https://vidyalms.invalid/api/v1/probe");',
                'if (answer.status !== 503) throw new Error(`fetch was answered with ${answer.status}`);',
                'const request = new XMLHttpRequest();',
                'request.open("GET", "https://vidyalms.invalid/api/v1/probe", false);',
                'request.send();',
                'if (request.status !== 503) throw new Error(`XMLHttpRequest was answered with ${request.status}`);',
                'let refused = false;',
                'try { new WebSocket("wss://vidyalms.invalid/socket"); } catch { refused = true; }',
                'if (!refused) throw new Error("a socket was opened");',
            ].join('\n'),
        });

        const result = await evaluateEntry(directory, 'network.js');

        expect(result).toMatchObject({ entry: 'network.js', ok: true, offlineRequests: 2 });
    }, 60000);

    it('fails an entry whose page throws while it settles, after the module itself finished', async () => {
        const directory = output({
            'late.js': 'setTimeout(() => { throw new TypeError("late failure"); }, 0);',
            'rejected.js': 'Promise.reject(new RangeError("nobody handled this"));',
        });

        const failures = await entryEvaluationViolations(directory, ['late.js', 'rejected.js']);

        expect(failures).toHaveLength(2);
        expect(failures[0]).toMatch(/^late\.js fails while its page settled after evaluation: TypeError: late failure/);
        expect(failures[1]).toMatch(/^rejected\.js fails while its page settled after evaluation: RangeError: nobody handled this/);
        // One error is one report, however many ways the page surfaced it.
        expect(failures.join('\n')).not.toContain('more)');
    }, 60000);

    it('fails an entry whose process ends without a report rather than passing it', async () => {
        const directory = output({ 'gone.js': 'process.exit(3);' });

        expect(await entryEvaluationViolations(directory, ['gone.js'])).toEqual([
            'gone.js fails in its evaluating process: Error: the evaluating process exited with 3 and no report',
        ]);
    }, 60000);

    it('words a failure with the entry, the phase, the error and its first frames', () => {
        expect(evaluationViolations([
            { entry: 'player.js', ok: true },
            {
                entry: 'admin.js',
                ok: false,
                phase: 'during module evaluation',
                error: { name: 'ReferenceError', message: "Cannot access 'Ce' before initialization", stack: '    at Tt (shared-x.js:1:530)' },
                more: 1,
            },
        ])).toEqual([
            "admin.js fails during module evaluation: ReferenceError: Cannot access 'Ce' before initialization (and 1 more)\n    at Tt (shared-x.js:1:530)",
        ]);
    });
});
