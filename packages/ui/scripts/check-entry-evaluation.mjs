/* Final-output gate: every built app entry evaluates and mounts in a browser-like page.
   check-app-entry-imports.mjs reads the emitted import graph; this runs it. An
   entry can pass every graph rule and still die at load, which is what the
   empty learner player of 2026-09-30 was: a module-scope t() call reached a
   binding of the shared chunk that was still in its temporal dead zone.

   Each scenario runs in its own process, with Node's ES module loader (which
   follows the same evaluation order a browser does) importing the built
   files into the happy-dom window the unit tests use. Three kinds run:

   - every entry alone, the first module on its page, holding the learner
     shell and the entry's mount with a config that satisfies that app's own
     mount schema, so the app really mounts and renders; then every lazy chunk
     the entry can reach through import() is evaluated on the same page;
   - every learner entry on its real native page: learner-navigation.js first,
     then the entry, in document order, as both hosts print them;
   - the same pair across a learner SPA swap: learner-navigation.js on a page
     with no mount, then the mount is swapped in and the entry loaded, the way
     src/learner/navigation.ts does it.

   Nothing leaves the process: the page URL is on the reserved .invalid
   domain, happy-dom's script, stylesheet and frame loading is off, and every
   request the page makes (fetch, XMLHttpRequest, sendBeacon) is answered
   locally with a 503.

   A scenario fails when importing a file throws; when an uncaught error, an
   unhandled rejection or a "[vidyalms]" console error (the app error
   boundary's report) surfaces while the page settles; when a known app shows
   the malformed-config notice, which means this gate's config no longer
   satisfies its schema and the gate would stop exercising the mount; or when
   a known app renders nothing at all. */
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { availableParallelism } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { runInThisContext } from 'node:vm';
import { MANIFEST_FILE, requiredEntries } from './check-app-entry-imports.mjs';
import { invokedDirectly } from './invoked-directly.mjs';

const here = fileURLToPath(import.meta.url);
const REPORT = '@@vidyalms-entry-evaluation@@';
export const PAGE_URL = 'https://vidyalms.invalid/learning/course/player?vy-lesson=1';
export const SETTLE_MS = 250;
export const MOUNT_WAIT_MS = 5000;
export const TIMEOUT_MS = 60000;
export const FIRST_PAGE_ENTRY = 'learner-navigation.js';
/** The entries a learner page prints after learner-navigation.js; each mounts with learnerNavigation: true. */
export const learnerPageEntries = ['credentials.js', 'notifications.js', 'player.js', 'quiz-runner.js'];

/** The part of the mount contract every app shares (src/shared/config.ts), with no host behind it. */
export const minimalConfig = {
    contract: 1,
    api: { base: 'https://vidyalms.invalid/api/v1', mode: 'prefix', headers: {} },
    user: { id: 0, name: '' },
    locale: 'en-GB',
    messages: {},
};

const site = 'https://vidyalms.invalid';
const signedIn = { ...minimalConfig, user: { id: 1, name: 'Evaluation learner' } };
const learner = {
    ...signedIn,
    idleTimeoutSeconds: 300,
    course: { id: 1, slug: 'evaluation' },
    lessonId: 1,
    urls: { course: `${site}/learning/course`, myLearning: `${site}/learning`, player: `${site}/learning/course/player`, upload: `${site}/upload` },
};

/**
 * A config per app that satisfies that app's own mount schema, so the gate
 * reaches the mount and render path instead of the malformed-config notice.
 * src/shared/app-entry-evaluation.test.ts parses each one with the real schema.
 */
export const entryConfigs = {
    'player.js': learner,
    'quiz-runner.js': learner,
    'instructor.js': {
        ...signedIn,
        course: null,
        routes: [],
        urls: { courses: `${site}/admin/courses`, workbench: `${site}/admin/course/{course_id}`, preview: `${site}/learning/{slug}` },
    },
    'admin.js': {
        ...signedIn,
        screen: 'dashboard',
        routes: [],
        urls: { workbench: `${site}/admin/course/{course_id}`, preview: `${site}/learning/{slug}`, settings: `${site}/admin/settings`, screens: {} },
    },
    'notifications.js': { ...signedIn, screen: 'preferences', token: '', urls: { home: `${site}/`, preferences: `${site}/notifications` } },
    'credentials.js': {
        ...signedIn,
        screen: 'credentials',
        subject: 'certificate',
        code: '',
        urls: { home: `${site}/`, verify: `${site}/verify`, myLearning: `${site}/learning` },
    },
};

/** Every fixed entry mounts on #vidyalms-<entry name>; learner-navigation looks for the shell instead. */
export function mountIdOf(entry) {
    return `vidyalms-${entry.replace(/\.js$/, '').replace(/[^A-Za-z0-9_-]/g, '-')}`;
}

/*
 * A scenario is { name, entries, mount, spa, lazy, expectMount }: the files
 * imported in order, the entry whose mount the page holds (null for none),
 * whether that mount is swapped in after the first file ran, the lazy chunks
 * evaluated afterwards, and whether the page must end with a known app
 * mounted (rendered, and not showing the malformed-config notice).
 */

/** One entry alone on its page, then the lazy chunks it can reach. The build expects every known app to mount. */
export function aloneScenario(entry, lazy = [], expectMount = false) {
    const mount = entry === FIRST_PAGE_ENTRY ? null : entry;
    return { name: entry, entries: [entry], mount, spa: false, lazy, expectMount: expectMount && mount !== null && entry in entryConfigs };
}

/** A learner entry on its native page, after learner-navigation.js in document order. */
export function nativePageScenario(entry) {
    const name = `${FIRST_PAGE_ENTRY} + ${entry} (native page)`;
    return { name, entries: [FIRST_PAGE_ENTRY, entry], mount: entry, spa: false, lazy: [], expectMount: entry in entryConfigs };
}

/** A learner entry swapped in by SPA navigation, after learner-navigation.js ran on a page without a mount. */
export function spaSwapScenario(entry) {
    const name = `${FIRST_PAGE_ENTRY} then ${entry} (SPA swap)`;
    return { name, entries: [FIRST_PAGE_ENTRY, entry], mount: entry, spa: true, lazy: [], expectMount: entry in entryConfigs };
}

function summary(error) {
    if (error instanceof Error) {
        const frames = String(error.stack ?? '').split('\n').filter((line) => line.trimStart().startsWith('at ')).slice(0, 5);
        return { name: error.name, message: error.message, stack: frames.join('\n') };
    }
    return { name: typeof error, message: String(error), stack: '' };
}

/** Requests the page made, every one answered locally; reported so a run shows the stub was exercised. */
let offlineRequests = 0;

async function installPage() {
    const require = createRequire(here);
    const { builtinEnvironments } = await import(pathToFileURL(require.resolve('vitest/environments')).href);
    const offlineHeaders = (window) => new window.Headers({ 'Content-Type': 'text/plain' });
    const interceptor = {
        beforeAsyncRequest: async ({ window }) => {
            offlineRequests++;
            return new window.Response('offline', { status: 503, statusText: 'Offline', headers: offlineHeaders(window) });
        },
        beforeSyncRequest: ({ request, window }) => {
            offlineRequests++;
            return {
                status: 503,
                statusText: 'Offline',
                ok: false,
                url: request.url,
                redirected: false,
                headers: offlineHeaders(window),
                body: Buffer.from('offline'),
            };
        },
    };
    await builtinEnvironments['happy-dom'].setup(globalThis, {
        happyDOM: {
            url: PAGE_URL,
            settings: {
                disableJavaScriptFileLoading: true,
                disableCSSFileLoading: true,
                disableIframePageLoading: true,
                handleDisabledFileLoadingAsSuccess: true,
                fetch: { interceptor },
                navigation: { disableMainFrameNavigation: true, disableChildFrameNavigation: true, disableChildPageNavigation: true },
            },
        },
    });
    class OfflineSocket {
        constructor(url) {
            throw new Error(`Entry evaluation keeps the network off; refused a socket to ${String(url)}.`);
        }
    }
    globalThis.WebSocket = OfflineSocket;
    globalThis.EventSource = OfflineSocket;
    const page = globalThis.document;
    const shell = page.createElement('div');
    shell.setAttribute('data-vy-learner-shell', '');
    shell.setAttribute('data-vy-site-key', 'entry-evaluation');
    page.body.append(shell);
    return shell;
}

function mountFor(entry) {
    const mount = globalThis.document.createElement('div');
    mount.id = mountIdOf(entry);
    mount.setAttribute('data-vy-config', JSON.stringify(entryConfigs[entry] ?? minimalConfig));
    return mount;
}

/** What a known app left in its mount that means it did not really start. */
function mountProblem(mount) {
    // The marker, or the notice's shape in a build from before the marker existed.
    const notice = mount.querySelector('[data-vy-config-error]')
        ?? [...mount.children].find((child) => child.getAttribute('role') === 'alert' && child.querySelector('strong') && child.querySelector('code'));
    if (notice) {
        const detail = notice.querySelector('code')?.textContent ?? '';
        return `#${mount.id} shows the malformed-config notice, so this gate's config no longer satisfies the app's mount schema: ${detail}`;
    }
    if (mount.childNodes.length === 0) return `#${mount.id} is still empty; the app never rendered`;
    return null;
}

async function rendered(mount) {
    const until = Date.now() + MOUNT_WAIT_MS;
    while (mount.childNodes.length === 0 && Date.now() < until) await sleep(25);
}

/** Child side: one page, one scenario, one report line on stdout. */
async function evaluateHere(directory, scenario) {
    const late = [];
    const record = (error) => {
        const found = summary(error);
        // One error can surface both as a window error event and as an uncaught exception.
        if (!late.some((seen) => seen.name === found.name && seen.message === found.message)) late.push(found);
    };
    process.on('uncaughtException', record);
    process.on('unhandledRejection', record);
    const consoleError = console.error.bind(console);
    console.error = (...parts) => {
        // The app error boundary and the registries report caught failures with this prefix.
        if (typeof parts[0] === 'string' && parts[0].startsWith('[vidyalms]')) {
            const cause = parts.find((part) => part instanceof Error);
            record(cause ?? new Error(parts.map(String).join(' ')));
        }
        consoleError(...parts);
    };
    const settled = (phase) => (late.length === 0 ? null : { ok: false, phase, error: late[0], more: late.length - 1 });
    let result = { ok: true };
    try {
        const shell = await installPage();
        if (process.env.VIDYALMS_BUILD_HOST === 'wordpress') {
            const vendor = process.env.VIDYALMS_WORDPRESS_VENDOR;
            if (vendor) {
                // Verify against a real WordPress installation when provided.
                for (const name of ['react', 'react-dom', 'react-jsx-runtime']) {
                    const filename = join(vendor, `${name}.js`);
                    runInThisContext(readFileSync(filename, 'utf8'), { filename });
                }
            } else {
                // Portable build check; release validation also runs with WP's actual UMD files.
                globalThis.React = (await import('react')).default;
                globalThis.ReactDOM = { ...(await import('react-dom')).default, ...(await import('react-dom/client')) };
                globalThis.ReactJSXRuntime = await import('react/jsx-runtime');
            }
        }
        globalThis.addEventListener('error', (event) => record(event.error ?? event.message));
        const mount = scenario.mount === null ? null : mountFor(scenario.mount);
        if (mount !== null && !scenario.spa) shell.append(mount);
        for (const [index, entry] of scenario.entries.entries()) {
            const swapping = scenario.spa && mount !== null && index === scenario.entries.length - 1;
            if (swapping) {
                // What navigation.ts does: the new content arrives, its scripts load, then the mount event fires.
                await sleep(SETTLE_MS);
                shell.dataset.vyMountPending = 'true';
                shell.append(mount);
            }
            try {
                await import(pathToFileURL(join(directory, entry)).href);
            } catch (error) {
                const phase = scenario.entries.length === 1 ? 'during module evaluation' : `during module evaluation of ${entry}`;
                result = { ok: false, phase, error: summary(error) };
                break;
            }
            if (swapping) {
                delete shell.dataset.vyMountPending;
                globalThis.dispatchEvent(new globalThis.Event('vidyalms:learner:mount'));
            }
        }
        const expectMount = scenario.expectMount === true && mount !== null;
        if (result.ok && expectMount) await rendered(mount);
        if (result.ok) await sleep(SETTLE_MS);
        if (result.ok) result = settled('while its page settled after evaluation') ?? result;
        const problem = result.ok && expectMount ? mountProblem(mount) : null;
        if (problem !== null) result = { ok: false, phase: 'after mounting', error: { name: 'MountError', message: problem, stack: '' } };
        for (const chunk of result.ok ? scenario.lazy : []) {
            try {
                await import(pathToFileURL(join(directory, chunk)).href);
            } catch (error) {
                result = { ok: false, phase: `during evaluation of its lazy chunk ${chunk}`, error: summary(error) };
                break;
            }
        }
        if (result.ok && scenario.lazy.length > 0) {
            await sleep(SETTLE_MS);
            result = settled('while its page settled after its lazy chunks') ?? result;
        }
    } catch (error) {
        result = { ok: false, phase: 'during page setup', error: summary(error) };
    }
    const entry = scenario.entries.at(-1);
    const report = { scenario: scenario.name, entry, offlineRequests, lazyChunks: scenario.lazy.length, ...result };
    // The app keeps timers and listeners alive; leave once the report is flushed.
    process.stdout.write(`\n${REPORT}${JSON.stringify(report)}\n`, () => process.exit(0));
}

/** Parent side: a fresh process per scenario, so each page starts empty. */
export function evaluateScenario(directory, scenario, timeoutMs = TIMEOUT_MS) {
    return new Promise((done) => {
        const child = spawn(process.execPath, [here, '--evaluate', directory, JSON.stringify(scenario)], { stdio: ['ignore', 'pipe', 'pipe'] });
        let output = '';
        let errors = '';
        const timer = setTimeout(() => child.kill(), timeoutMs);
        child.stdout.on('data', (chunk) => {
            output += chunk;
        });
        child.stderr.on('data', (chunk) => {
            errors += chunk;
        });
        child.on('close', (code) => {
            clearTimeout(timer);
            const line = output.split('\n').find((text) => text.startsWith(REPORT));
            if (line) {
                done(JSON.parse(line.slice(REPORT.length)));
                return;
            }
            const tail = errors.trim().split('\n').slice(-6).join('\n');
            const message = `the evaluating process exited with ${String(code)} and no report${tail ? `:\n${tail}` : ''}`;
            const entry = scenario.entries.at(-1);
            done({ scenario: scenario.name, entry, ok: false, phase: 'in its evaluating process', error: { name: 'Error', message, stack: '' } });
        });
    });
}

export function evaluateEntry(directory, entry, timeoutMs = TIMEOUT_MS) {
    return evaluateScenario(directory, aloneScenario(entry), timeoutMs);
}

/** Pure over the results, so the tests can check the wording without a build. */
export function evaluationViolations(results) {
    return results.filter((result) => !result.ok).map((result) => {
        const more = result.more ? ` (and ${String(result.more)} more)` : '';
        const where = result.error.stack ? `\n${result.error.stack}` : '';
        return `${result.scenario ?? result.entry} fails ${result.phase}: ${result.error.name}: ${result.error.message}${more}${where}`;
    });
}

/** Runs scenarios a few at a time; each one is a whole Node process with a DOM. */
export async function evaluateScenarios(directory, scenarios, concurrency = Math.max(2, Math.min(8, availableParallelism() - 1))) {
    const results = new Array(scenarios.length);
    let next = 0;
    const worker = async () => {
        while (next < scenarios.length) {
            const index = next++;
            results[index] = await evaluateScenario(directory, scenarios[index]);
        }
    };
    await Promise.all(Array.from({ length: Math.min(concurrency, scenarios.length) }, worker));
    return results;
}

export function evaluateEntries(directory, entries) {
    return evaluateScenarios(directory, entries.map((entry) => aloneScenario(entry)));
}

export async function entryEvaluationViolations(directory, entries) {
    return evaluationViolations(await evaluateEntries(directory, entries));
}

/**
 * The JavaScript an entry can reach only through import(), in manifest key
 * order: every file reachable over static and dynamic edges, less what the
 * entry itself loads statically.
 */
export function lazyChunksOf(manifest, entryKey) {
    const walk = (dynamic) => {
        const reached = new Set();
        const pending = [entryKey];
        while (pending.length) {
            const key = pending.pop();
            if (reached.has(key) || !manifest[key]) continue;
            reached.add(key);
            pending.push(...(manifest[key].imports ?? []), ...(dynamic ? manifest[key].dynamicImports ?? [] : []));
        }
        return reached;
    };
    const eager = walk(false);
    return [...walk(true)].filter((key) => !eager.has(key) && manifest[key].file.endsWith('.js')).sort().map((key) => manifest[key].file);
}

/** Every scenario the build must pass, from its manifest. */
export function buildScenarios(manifest) {
    const records = Object.entries(manifest).filter(([, chunk]) => chunk.isEntry && chunk.file.endsWith('.js'));
    const entries = records.map(([, chunk]) => chunk.file).sort();
    for (const entry of requiredEntries) {
        if (!entries.includes(entry)) throw new Error(`Expected app entry ${entry} is missing from the build manifest.`);
    }
    const sorted = records.sort(([, a], [, b]) => a.file.localeCompare(b.file));
    const alone = sorted.map(([key, chunk]) => aloneScenario(chunk.file, lazyChunksOf(manifest, key), true));
    const learner = learnerPageEntries.filter((entry) => entries.includes(entry));
    return [...alone, ...learner.map(nativePageScenario), ...learner.map(spaSwapScenario)];
}

export async function checkBuild(directory, manifestFile = MANIFEST_FILE) {
    const manifest = JSON.parse(readFileSync(join(directory, manifestFile), 'utf8'));
    const scenarios = buildScenarios(manifest);
    const results = await evaluateScenarios(directory, scenarios);
    const violations = evaluationViolations(results);
    if (violations.length) throw new Error(violations.join('\n'));
    const requests = results.reduce((total, result) => total + (result.offlineRequests ?? 0), 0);
    const lazy = results.reduce((total, result) => total + (result.lazyChunks ?? 0), 0);
    const alone = scenarios.filter((scenario) => scenario.entries.length === 1).length;
    console.log(`[entry-evaluation] ${alone} app entries evaluated and mounted alone with their own mount config, ${lazy} lazy chunk evaluations,`
        + ` ${scenarios.length - alone} learner page pairs (native and SPA swap): none threw; ${requests} requests answered offline`);
}

if (invokedDirectly(process.argv[1], import.meta.url)) {
    const [flag, directory, scenario] = process.argv.slice(2);
    if (flag === '--evaluate' && directory && scenario) {
        await evaluateHere(directory, JSON.parse(scenario));
    } else {
        try {
            await checkBuild(resolve(flag ?? join(dirname(here), '..', 'dist')));
        } catch (error) {
            console.error(`[entry-evaluation] ${error instanceof Error ? error.message : String(error)}`);
            process.exitCode = 1;
        }
    }
}
