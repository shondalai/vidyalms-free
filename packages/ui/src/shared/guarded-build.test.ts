import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { afterAll, describe, expect, it, vi } from 'vitest';

/*
 * npm run build (scripts/build.mjs) publishes .vite/manifest.json only after
 * every guard passed. Both adapter packagers (tooling/releases/ui-manifest.php)
 * and tooling/operations/install-dashboard-assets.ps1 take a dist/ holding
 * that manifest as finished, and the review of 2026-09-30 fed them a dist/
 * that had failed both guards: they accepted it, broken chunk pair included.
 */

interface Guard {
    name: string;
    check: (dist: string, manifestFile: string) => unknown;
}

const script = pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../../scripts/build.mjs')).href;
const { publishWhenGuarded, packageGuards, PUBLISHED, PENDING, REJECTED, REASONS } = await import(script) as {
    publishWhenGuarded: (dist: string, guards: Guard[], log?: Pick<Console, 'log' | 'error'>) => Promise<boolean>;
    packageGuards: () => Promise<Guard[]>;
    PUBLISHED: string;
    PENDING: string;
    REJECTED: string;
    REASONS: string;
};

const directories: string[] = [];
const quiet = { log: vi.fn(), error: vi.fn() };
const MANIFEST = '{"src/player/main.tsx":{"file":"player.js","isEntry":true}}';

/** A fresh dist/ as vite leaves it: files plus a complete manifest. */
function freshDist(): string {
    const dist = mkdtempSync(join(tmpdir(), 'vidyalms-guarded-build-'));
    directories.push(dist);
    mkdirSync(join(dist, '.vite'));
    writeFileSync(join(dist, PUBLISHED), MANIFEST);
    writeFileSync(join(dist, 'player.js'), 'export {};');
    return dist;
}

afterAll(() => {
    for (const directory of directories) rmSync(directory, { recursive: true, force: true });
});

describe('guarded publishing of the build manifest', () => {
    it('hands every guard the pending manifest and publishes it once all of them pass', async () => {
        const dist = freshDist();
        const seen: string[] = [];
        const guards: Guard[] = ['imports', 'evaluation', 'css'].map((name) => ({
            name,
            check: (directory, manifestFile) => {
                expect(existsSync(join(directory, PUBLISHED)), 'no consumer can take the build while a guard runs').toBe(false);
                seen.push(`${name}:${readFileSync(join(directory, manifestFile), 'utf8') === MANIFEST ? 'manifest' : 'other'}`);
            },
        }));

        expect(await publishWhenGuarded(dist, guards, quiet)).toBe(true);

        expect(seen).toEqual(['imports:manifest', 'evaluation:manifest', 'css:manifest']);
        expect(readFileSync(join(dist, PUBLISHED), 'utf8')).toBe(MANIFEST);
        expect(existsSync(join(dist, PUBLISHED))).toBe(true);
        expect(existsSync(join(dist, PENDING))).toBe(false);
        expect(existsSync(join(dist, REJECTED))).toBe(false);
    });

    it('withholds the manifest when any guard fails, still runs the rest, and records why', async () => {
        const dist = freshDist();
        const ran: string[] = [];
        const guards: Guard[] = [
            {
                name: 'app-entry-imports',
                check: () => {
                    ran.push('imports');
                    throw new Error('static import cycle between built chunks: a -> b -> a\nsecond line');
                },
            },
            { name: 'entry-evaluation', check: async () => { ran.push('evaluation'); } },
            {
                name: 'css-scope',
                check: async () => {
                    ran.push('css');
                    return { ok: false, reason: 'exited with 1' };
                },
            },
        ];

        const log = { log: vi.fn(), error: vi.fn() };
        expect(await publishWhenGuarded(dist, guards, log)).toBe(false);

        expect(ran).toEqual(['imports', 'evaluation', 'css']);
        // The packagers read stdout to its end before stderr: details on stdout, one short stderr line.
        expect(log.error).toHaveBeenCalledOnce();
        expect(String(log.error.mock.calls[0]![0])).toBe(`[build] rejected by 2 of 3 guards; reasons in dist/${REASONS}`);
        expect(log.log.mock.calls.flat().join('\n')).toContain('second line');
        // What ui-manifest.php and install-dashboard-assets.ps1 look for is gone, so both refuse or rebuild.
        expect(existsSync(join(dist, PUBLISHED))).toBe(false);
        expect(existsSync(join(dist, PENDING))).toBe(false);
        expect(readFileSync(join(dist, REJECTED), 'utf8')).toContain('player.js');
        expect(readFileSync(join(dist, REASONS), 'utf8')).toBe(
            'app-entry-imports: static import cycle between built chunks: a -> b -> a\ncss-scope: exited with 1\n',
        );
        expect(existsSync(join(dist, 'player.js')), 'the rejected files stay for inspection').toBe(true);
    });

    it('publishes nothing when vite wrote no manifest, and clears a rejection left by an earlier run', async () => {
        const dist = freshDist();
        writeFileSync(join(dist, REJECTED), 'old');
        writeFileSync(join(dist, REASONS), 'old');

        expect(await publishWhenGuarded(dist, [], quiet)).toBe(true);
        expect(existsSync(join(dist, REJECTED))).toBe(false);
        expect(existsSync(join(dist, REASONS))).toBe(false);

        rmSync(join(dist, PUBLISHED));
        expect(await publishWhenGuarded(dist, [{ name: 'never', check: () => undefined }], quiet)).toBe(false);
    });

    it('runs the package guards the build has always run, in their order', async () => {
        expect((await packageGuards()).map((guard) => guard.name)).toEqual(['app-entry-imports', 'entry-evaluation', 'css-scope']);
    });
});

describe('the build scripts run however their path is spelt', () => {
    const scripts = join(dirname(fileURLToPath(import.meta.url)), '../../scripts');
    const helper = pathToFileURL(join(scripts, 'invoked-directly.mjs')).href;
    const buildUrl = pathToFileURL(join(scripts, 'build.mjs')).href;

    it('recognises the script through a junction, where argv[1] is not the real path', async () => {
        const { invokedDirectly } = await import(helper) as { invokedDirectly: (argument: unknown, moduleUrl: string) => boolean };
        const parent = mkdtempSync(join(tmpdir(), 'vidyalms-invoked-'));
        directories.push(parent);
        const linked = join(parent, 'scripts');
        // A directory junction needs no privilege on Windows; elsewhere the type is ignored and a symlink is made.
        symlinkSync(scripts, linked, 'junction');
        try {
            // Through the link, the 2026-10-01 comparison was false: npm run build exited 0 having done nothing.
            expect(resolve(join(linked, 'build.mjs'))).not.toBe(fileURLToPath(buildUrl));
            expect(invokedDirectly(join(linked, 'build.mjs'), buildUrl)).toBe(true);
            expect(invokedDirectly(join(scripts, 'build.mjs'), buildUrl)).toBe(true);
            expect(invokedDirectly(join(linked, 'check-css-scope.mjs'), buildUrl)).toBe(false);
            expect(invokedDirectly(undefined, buildUrl)).toBe(false);
            expect(invokedDirectly('', buildUrl)).toBe(false);
        } finally {
            // Removes the link itself, never what it points at.
            unlinkSync(linked);
        }
    });
});
