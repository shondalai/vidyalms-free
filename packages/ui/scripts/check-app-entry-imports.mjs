/* Final-output gate: a cache-versioned mounting entry is never a dependency.
   Parse emitted JavaScript rather than matching strings/comments or trusting
   source-level imports. Vite already provides Rollup's maintained AST parser. */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';
import { parseAst } from 'vite';
import { invokedDirectly } from './invoked-directly.mjs';

/** Where vite writes the build manifest; scripts/build.mjs has the guards read it under a pending name until they pass. */
export const MANIFEST_FILE = '.vite/manifest.json';

export const requiredEntries = ['learner-navigation.js', 'admin.js', 'credentials.js', 'instructor.js', 'notifications.js', 'player.js', 'quiz-runner.js'];
export const requiredLazySources = [
    'src/admin/credentials/templates-tab.tsx',
    'src/admin/organisations/screen.tsx',
    'src/admin/operations/screens.tsx',
];

export function importsOf(code) {
    const found = []; const pending = [parseAst(code)];
    while (pending.length) {
        const node = pending.pop();
        if (!node || typeof node !== 'object') continue;
        if (['ImportDeclaration', 'ExportNamedDeclaration', 'ExportAllDeclaration', 'ImportExpression'].includes(node.type)) {
            const source = node.source;
            const path = source?.type === 'Literal' ? source.value
                : source?.type === 'TemplateLiteral' && source.expressions.length === 0 ? source.quasis[0]?.value.cooked : null;
            if (typeof path === 'string') found.push({ path, dynamic: node.type === 'ImportExpression' });
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) pending.push(...value);
            else if (value !== null && typeof value === 'object') pending.push(value);
        }
    }
    return found;
}

function resolvedImport(file, path) {
    if (!path.startsWith('.') && !path.startsWith('/')) return null;
    const url = new URL(path, `https://build.invalid/${file}`);
    return url.origin === 'https://build.invalid' ? decodeURIComponent(url.pathname.slice(1)) : null;
}

/** Pure graph check, also used by the regression tests and old-build audit. */
export function entryImportViolations(files, entries, lazyFiles = []) {
    const entrySet = new Set(entries); const imports = new Map(); const violations = [];
    for (const [file, code] of Object.entries(files)) {
        const links = importsOf(code).map((link) => ({ ...link, target: resolvedImport(file, link.path) }));
        imports.set(file, links);
        for (const link of links) {
            if (entrySet.has(link.target)) violations.push(`${file} ${link.dynamic ? 'dynamically imports' : 'imports/re-exports'} mounting entry ${link.target}`);
        }
    }
    const eager = new Set(); const pending = [...entries];
    while (pending.length) {
        const file = pending.pop();
        if (eager.has(file)) continue;
        eager.add(file);
        for (const link of imports.get(file) ?? []) {
            if (!link.dynamic && link.target !== null) pending.push(link.target);
        }
    }
    for (const file of lazyFiles) {
        if (eager.has(file)) violations.push(`${file} is statically reachable from an app entry; the screen is no longer lazy`);
    }
    return violations;
}

/**
 * The static import graph between emitted files, each target once and in
 * name order. A dynamic import() is not an edge: it evaluates later, on
 * demand, after its importer has finished. Targets outside the output are
 * left to cacheSafetyViolations.
 */
export function staticImportGraph(files) {
    const graph = new Map();
    for (const [file, code] of Object.entries(files)) {
        const targets = new Set();
        for (const link of importsOf(code)) {
            const target = link.dynamic ? null : resolvedImport(file, link.path);
            if (target !== null && target in files) targets.add(target);
        }
        graph.set(file, [...targets].sort());
    }
    return graph;
}

/** The shortest way round one strongly connected component, from its first file back to it. */
function shortestCycle(graph, members, start) {
    const previous = new Map([[start, null]]);
    const queue = [start];
    while (queue.length) {
        const node = queue.shift();
        for (const next of graph.get(node) ?? []) {
            if (next === start) {
                const path = [start];
                for (let at = node; at !== start; at = previous.get(at)) path.splice(1, 0, at);
                return [...path, start];
            }
            if (members.has(next) && !previous.has(next)) {
                previous.set(next, node);
                queue.push(next);
            }
        }
    }
    return [start, start];
}

/**
 * Every static import cycle between emitted files, one per strongly connected
 * component, each named from its alphabetically first file.
 *
 * Rollup keeps a cycle between modules in the order the modules asked for,
 * but a cycle between chunks has no safe order: whichever chunk a page loads
 * first pauses on its import of the other, and the other then runs against
 * bindings that do not exist yet. On 2026-09-30 that was shared importing
 * question-types for the window.vidyalms helper while question-types imported
 * shared for t(), and every entry that loaded shared first died on a temporal
 * dead zone error before it could mount.
 */
export function staticImportCycles(files) {
    const graph = staticImportGraph(files);
    const order = new Map();
    const low = new Map();
    const stack = [];
    const onStack = new Set();
    const components = [];
    const connect = (node) => {
        order.set(node, order.size);
        low.set(node, order.get(node));
        stack.push(node);
        onStack.add(node);
        for (const next of graph.get(node) ?? []) {
            if (!order.has(next)) {
                connect(next);
                low.set(node, Math.min(low.get(node), low.get(next)));
            } else if (onStack.has(next)) {
                low.set(node, Math.min(low.get(node), order.get(next)));
            }
        }
        if (low.get(node) !== order.get(node)) return;
        const component = [];
        let member;
        do {
            member = stack.pop();
            onStack.delete(member);
            component.push(member);
        } while (member !== node);
        components.push(component);
    };
    for (const node of [...graph.keys()].sort()) {
        if (!order.has(node)) connect(node);
    }
    const cycles = [];
    for (const component of components) {
        const start = [...component].sort()[0];
        if (component.length === 1 && !(graph.get(start) ?? []).includes(start)) continue;
        cycles.push(shortestCycle(graph, new Set(component), start));
    }
    return cycles.sort((a, b) => a[0].localeCompare(b[0]));
}

export function staticImportCycleViolations(files) {
    return staticImportCycles(files).map((cycle) => `static import cycle between built chunks: ${cycle.join(' -> ')}`
        + '; whichever of them a page loads first runs the other against bindings that do not exist yet');
}

/** Every secondary URL is content-addressed, including transitive imports. */
export function cacheSafetyViolations(files, entries) {
    const entrySet = new Set(entries); const violations = [];
    for (const [file, code] of Object.entries(files)) {
        if (!entrySet.has(file) && !/-[A-Za-z0-9_-]{8,}\.js$/.test(file)) {
            violations.push(`${file} is a secondary JavaScript file without a content hash`);
        }
        for (const link of importsOf(code)) {
            const target = resolvedImport(file, link.path);
            if (target !== null && target.endsWith('.js') && !(target in files)) {
                violations.push(`${file} imports missing JavaScript ${target}`);
            }
        }
    }
    return violations;
}

/** Every file statically reachable from the given ones, themselves included: what a page evaluates when it loads them. */
export function staticClosure(files, starts) {
    const graph = staticImportGraph(files);
    const reached = new Set();
    const pending = [...starts];
    while (pending.length) {
        const file = pending.pop();
        if (reached.has(file) || !(file in files)) continue;
        reached.add(file);
        pending.push(...(graph.get(file) ?? []));
    }
    return [...reached].sort();
}

/** The entry that evaluates first on every learner page, often on one that carries no data-vy-config. */
export const FIRST_PAGE_ENTRY = 'learner-navigation.js';

function lazyScope(node) {
    if (ts.isFunctionLike(node)) return true;
    // An instance field initialiser runs at construction; a static one runs with the class.
    return ts.isPropertyDeclaration(node) && !(ts.getCombinedModifierFlags(node) & ts.ModifierFlags.Static);
}

/**
 * Module-scope translations in the given source files: a call to the i18n
 * translate() binding, or a read from an imported copy table (a binding
 * named ...Strings), made while the module is evaluated rather than when
 * something renders.
 *
 * translate() resolves against the data-vy-config of the page at the moment
 * of the call. A module that runs before the first learner page swap freezes
 * whatever that page carried, and the catalogue, course and My learning
 * pages carry none, so its strings stayed in English on every page swup
 * swapped in afterwards (2026-09-30). Such a module builds its table with
 * localizedStrings (src/shared/i18n.ts) and reads it at the moment of use.
 */
export function eagerTranslationViolations(sources) {
    const violations = [];
    for (const [path, code] of Object.entries(sources)) {
        if (!/\.[cm]?[jt]sx?$/.test(path)) continue;
        const kind = /\.[jt]sx$/.test(path) ? ts.ScriptKind.TSX : /\.[cm]?js$/.test(path) ? ts.ScriptKind.JS : ts.ScriptKind.TS;
        const source = ts.createSourceFile(path, code, ts.ScriptTarget.Latest, true, kind);
        const translators = new Set();
        const tables = new Set();
        for (const statement of source.statements) {
            if (!ts.isImportDeclaration(statement) || statement.importClause?.isTypeOnly) continue;
            const bindings = statement.importClause?.namedBindings;
            if (!bindings || !ts.isNamedImports(bindings) || !ts.isStringLiteral(statement.moduleSpecifier)) continue;
            for (const element of bindings.elements) {
                if (element.isTypeOnly) continue;
                const imported = (element.propertyName ?? element.name).text;
                if (imported === 'translate' && /(?:^|\/)i18n$/.test(statement.moduleSpecifier.text)) translators.add(element.name.text);
                if (/Strings$/.test(imported)) tables.add(element.name.text);
            }
        }
        if (translators.size === 0 && tables.size === 0) continue;
        const found = [];
        const line = (node) => source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;
        const visit = (node, deferred) => {
            if (!deferred && ts.isCallExpression(node) && ts.isIdentifier(node.expression) && translators.has(node.expression.text)) {
                found.push(`line ${line(node)} calls ${node.expression.text}()`);
            }
            if (!deferred && ts.isPropertyAccessExpression(node) && ts.isIdentifier(node.expression) && tables.has(node.expression.text)) {
                found.push(`line ${line(node)} reads ${node.expression.text}.${node.name.text}`);
            }
            ts.forEachChild(node, (child) => visit(child, deferred || lazyScope(node)));
        };
        visit(source, false);
        // One line per module: a table is dozens of calls, and the fix is one wrapper.
        if (found.length) violations.push(`${path} translates at module scope ${found.length === 1 ? 'once' : `${found.length} times`} (first: ${found[0]})`);
    }
    return violations;
}

/** The app's own sources behind the given emitted files, from their source maps. */
export function appSourcesOf(directory, files) {
    const sources = {};
    const missing = [];
    for (const file of files) {
        const mapPath = join(directory, `${file}.map`);
        if (!existsSync(mapPath)) {
            missing.push(file);
            continue;
        }
        const map = JSON.parse(readFileSync(mapPath, 'utf8'));
        (map.sources ?? []).forEach((name, index) => {
            const content = map.sourcesContent?.[index];
            const resolved = decodeURIComponent(new URL(name, `https://build.invalid/${file}`).pathname);
            // The package's own sources, wherever the build ran from; dependencies are not app copy.
            const at = resolved.indexOf('/src/');
            if (at >= 0 && !resolved.includes('/node_modules/') && typeof content === 'string') sources[resolved.slice(at + 1)] = content;
        });
    }
    return { sources, missing };
}

export function firstPageTranslationViolations(directory, files) {
    const early = staticClosure(files, [FIRST_PAGE_ENTRY]);
    const { sources, missing } = appSourcesOf(directory, early);
    return [
        ...missing.map((file) => `${file} has no source map, so its module-scope translations cannot be checked`),
        ...eagerTranslationViolations(sources).map((found) => `${found}; ${FIRST_PAGE_ENTRY} evaluates it on pages that may carry`
            + ' no catalogue, so later SPA pages keep those strings untranslated; build the table with localizedStrings (src/shared/i18n.ts)'),
    ];
}

function javascriptFiles(directory, prefix = '') {
    const files = {};
    for (const item of readdirSync(directory, { withFileTypes: true })) {
        const relative = `${prefix}${item.name}`;
        if (item.isDirectory()) Object.assign(files, javascriptFiles(join(directory, item.name), `${relative}/`));
        else if (item.name.endsWith('.js')) files[relative] = readFileSync(join(directory, item.name), 'utf8');
    }
    return files;
}

export function checkBuild(directory, manifestFile = MANIFEST_FILE) {
    const manifest = JSON.parse(readFileSync(join(directory, manifestFile), 'utf8'));
    const entries = Object.values(manifest).filter((chunk) => chunk.isEntry && chunk.file.endsWith('.js')).map((chunk) => chunk.file);
    for (const entry of requiredEntries) {
        if (!entries.includes(entry)) throw new Error(`Expected app entry ${entry} is missing from the build manifest.`);
    }
    const lazyFiles = requiredLazySources.map((source) => {
        const chunk = manifest[source];
        if (!chunk?.isDynamicEntry || !chunk.file.endsWith('.js')) throw new Error(`Expected lazy screen ${source} is missing or no longer dynamically imported.`);
        return chunk.file;
    });
    if (new Set(lazyFiles).size !== requiredLazySources.length) throw new Error('Independent certificate, organization and operations screens were merged into one chunk.');
    const files = javascriptFiles(directory);
    for (const file of [...entries, ...lazyFiles]) {
        if (!(file in files)) throw new Error(`Manifest chunk ${file} is missing from the output.`);
    }
    const violations = [
        ...entryImportViolations(files, entries, lazyFiles),
        ...cacheSafetyViolations(files, entries),
        ...staticImportCycleViolations(files),
        ...firstPageTranslationViolations(directory, files),
    ];
    if (violations.length) throw new Error(violations.join('\n'));
    console.log(`[app-entry-imports] ${entries.length} app entries, ${Object.keys(files).length} JavaScript files: no entry back-imports; all secondary files hashed and present; ${lazyFiles.length} independent lazy screens retained; no static import cycles between chunks;`
        + ` no module-scope translation in the ${staticClosure(files, [FIRST_PAGE_ENTRY]).length} files ${FIRST_PAGE_ENTRY} evaluates`);
}

if (invokedDirectly(process.argv[1], import.meta.url)) {
    // An optional directory argument audits another build, such as a deployed copy.
    try { checkBuild(process.argv[2] ? resolve(process.argv[2]) : join(dirname(fileURLToPath(import.meta.url)), '..', 'dist')); }
    catch (error) { console.error(`[app-entry-imports] ${error instanceof Error ? error.message : String(error)}`); process.exitCode = 1; }
}
