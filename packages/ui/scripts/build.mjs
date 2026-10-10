/* npm run build: vite build, then every post-build guard, then publish.

   The packagers (packages/adapter-joomla/build/build.php and the WordPress
   one, through tooling/releases/ui-manifest.php) and
   tooling/operations/install-dashboard-assets.ps1 take dist/ as finished
   when it holds .vite/manifest.json and is newer than src/. When the build
   was "vite build && guard && guard", a failed guard left exactly that
   behind: a complete manifest in a fresh dist/, which both consumers
   accepted (review, 2026-09-30, with the broken chunk pair of that day).

   So vite's manifest is moved to a pending name the moment vite finishes,
   the guards read it there, and it returns to .vite/manifest.json only when
   every guard passed. A rejected build keeps its files for inspection, with
   the manifest at .vite/manifest.rejected.json and the reasons beside it in
   .vite/rejected.txt; no consumer reads either, so each one refuses the
   output or rebuilds it.

   Diagnostics go to stdout and stderr carries one short line. Both
   packagers run this through runCommand (adapter-joomla build/lib/packager.php
   and its WordPress twin), which reads the child's stdout to its end before
   it reads stderr, so a rejection long enough to fill the stderr pipe left
   the build waiting on that pipe forever, and the packager with it, instead
   of failing (2026-10-01). */
import { spawn } from 'node:child_process';
import { existsSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { invokedDirectly } from './invoked-directly.mjs';

const here = fileURLToPath(import.meta.url);
const root = join(dirname(here), '..');
export const PUBLISHED = '.vite/manifest.json';
export const PENDING = '.vite/manifest.pending.json';
export const REJECTED = '.vite/manifest.rejected.json';
export const REASONS = '.vite/rejected.txt';

/** A child's stderr is forwarded to stdout, for the reason in the header. */
function run(command, args) {
    return new Promise((done) => {
        const child = spawn(command, args, { cwd: root, stdio: ['inherit', 'inherit', 'pipe'] });
        child.stderr.on('data', (chunk) => process.stdout.write(chunk));
        child.on('error', (error) => done({ ok: false, reason: error.message }));
        child.on('close', (code) => done(code === 0 ? { ok: true } : { ok: false, reason: `exited with ${String(code)}` }));
    });
}

/**
 * Moves the fresh manifest aside, runs every guard against it and publishes
 * it only when all of them pass. A guard is { name, check(directory,
 * manifestFile) } and fails by throwing or by resolving { ok: false, reason }.
 * Every guard runs even after one fails, so one build reports every problem.
 */
export async function publishWhenGuarded(dist, guards, log = console) {
    rmSync(join(dist, PENDING), { force: true });
    rmSync(join(dist, REJECTED), { force: true });
    rmSync(join(dist, REASONS), { force: true });
    if (!existsSync(join(dist, PUBLISHED))) {
        log.error(`[build] vite wrote no ${PUBLISHED}; nothing to publish`);
        return false;
    }
    renameSync(join(dist, PUBLISHED), join(dist, PENDING));
    const failed = [];
    for (const guard of guards) {
        try {
            const outcome = await guard.check(dist, PENDING);
            if (outcome && outcome.ok === false) failed.push(`${guard.name}: ${outcome.reason}`);
        } catch (error) {
            const reason = error instanceof Error ? error.message : String(error);
            log.log(`[${guard.name}] ${reason}`);
            failed.push(`${guard.name}: ${reason.split('\n')[0]}`);
        }
    }
    if (failed.length === 0) {
        renameSync(join(dist, PENDING), join(dist, PUBLISHED));
        log.log(`[build] ${guards.length} guards passed; published ${PUBLISHED}`);
        return true;
    }
    renameSync(join(dist, PENDING), join(dist, REJECTED));
    writeFileSync(join(dist, REASONS), `${failed.join('\n')}\n`);
    log.log(`[build] dist/ rejected; ${PUBLISHED} withheld so no packager or installer accepts it:\n  ${failed.join('\n  ')}`);
    log.error(`[build] rejected by ${failed.length} of ${guards.length} guards; reasons in dist/${REASONS}`);
    return false;
}

/** The guards of this package, in the order the build has always run them. */
export async function packageGuards() {
    const imports = await import('./check-app-entry-imports.mjs');
    const evaluation = await import('./check-entry-evaluation.mjs');
    return [
        { name: 'app-entry-imports', check: (dist, manifest) => imports.checkBuild(dist, manifest) },
        { name: 'entry-evaluation', check: (dist, manifest) => evaluation.checkBuild(dist, manifest) },
        // Reads the stylesheets only; it runs as its own script, as it always has.
        { name: 'css-scope', check: (dist) => run(process.execPath, [join(root, 'scripts', 'check-css-scope.mjs'), dist]) },
    ];
}

if (invokedDirectly(process.argv[1], import.meta.url)) {
    const wordpress = process.argv.includes('--wordpress');
    if (wordpress) process.env.VIDYALMS_BUILD_HOST = 'wordpress';
    const dist = join(root, wordpress ? 'dist-wordpress' : 'dist');
    // vite's exports map hides bin/, so resolve its package.json and take the bin from there.
    const vitePackage = createRequire(here).resolve('vite/package.json');
    const vite = join(dirname(vitePackage), 'bin', 'vite.js');
    const built = await run(process.execPath, [vite, 'build', ...(wordpress ? ['--mode', 'wordpress'] : [])]);
    if (!built.ok) {
        // Whatever vite left behind is not a build anyone may take.
        if (existsSync(join(dist, PUBLISHED))) renameSync(join(dist, PUBLISHED), join(dist, REJECTED));
        console.error(`[build] vite build ${built.reason}`);
        process.exitCode = 1;
    } else if (!(await publishWhenGuarded(dist, await packageGuards()))) {
        process.exitCode = 1;
    }
}
