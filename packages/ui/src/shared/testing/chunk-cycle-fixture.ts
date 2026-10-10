import { build } from 'vite';
import { appOutputNames } from '../../../vite.config';

/*
 * Test-only: the 2026-09-30 chunk layout in miniature, built by real
 * Vite/Rollup entirely in memory, for the two post-build guards
 * (scripts/check-app-entry-imports.mjs and scripts/check-entry-evaluation.mjs).
 *
 * A player entry imports an availability module that the shared chunk owns.
 * In the broken layout that module finds window.vidyalms through the question
 * type bridge, whose chunk imports shared for t() and calls it at module
 * scope, so the two chunks import each other and the player dies on the
 * i18n cache binding before it can mount. In the fixed layout the
 * availability module takes the namespace from a shared module that imports
 * nothing, and the bridge chunk is no longer on the player's page at all.
 */

export type ChunkLayout = 'broken' | 'fixed';

function modulesOf(layout: ChunkLayout): Record<string, string> {
    const namespaceSource = layout === 'broken' ? 'virtual:qt-bridge' : 'virtual:namespace';
    return {
        '\0virtual:player': 'import { reason } from "virtual:availability"; globalThis.playerReason = reason();',
        '\0virtual:quiz': 'import { bridge } from "virtual:qt-bridge"; import { t } from "virtual:i18n"; globalThis.quiz = [bridge(), t("q", "Quiz")];',
        '\0virtual:i18n': [
            'let source = null;',
            'let messages = {};',
            'export function t(key, fallback) {',
            '  const mount = globalThis.document?.querySelector("[data-vy-config]") ?? null;',
            '  if (source !== mount) { source = mount; messages = {}; }',
            '  return messages[key] ?? fallback;',
            '}',
        ].join('\n'),
        '\0virtual:namespace': 'export function namespace() { globalThis.vidyalms ??= {}; return globalThis.vidyalms; }',
        '\0virtual:availability': [
            `import { namespace } from "${namespaceSource}";`,
            'import { t } from "virtual:i18n";',
            'export function reason() { namespace().availabilityReasons = true; return t("reason", "Not available yet"); }',
        ].join('\n'),
        '\0virtual:qt-bridge': [
            'import { t } from "virtual:i18n";',
            'import { namespace as base } from "virtual:namespace";',
            'const labels = { chooseOne: t("choose", "Choose one") };',
            'export function namespace() { return Object.assign(base(), { labels }); }',
            'export function bridge() { return namespace(); }',
        ].join('\n'),
    };
}

const sharedModules = new Set(['\0virtual:i18n', '\0virtual:namespace', '\0virtual:availability']);

/** The emitted JavaScript by file name, plus the two fixed entry names. */
export async function buildChunkLayout(layout: ChunkLayout): Promise<{ files: Record<string, string>; entries: string[] }> {
    const modules = modulesOf(layout);
    const result = await build({
        configFile: false,
        logLevel: 'silent',
        publicDir: false,
        plugins: [{
            name: 'chunk-cycle-fixture',
            resolveId: (id) => id.startsWith('virtual:') ? `\0${id}` : null,
            load: (id) => modules[id] ?? null,
        }],
        build: {
            write: false,
            minify: false,
            modulePreload: false,
            rollupOptions: {
                input: { player: 'virtual:player', 'quiz-runner': 'virtual:quiz' },
                output: {
                    ...appOutputNames,
                    manualChunks: (id) => sharedModules.has(id) ? 'shared' : id === '\0virtual:qt-bridge' ? 'question-types' : undefined,
                },
            },
        },
    });
    if (Array.isArray(result) || !('output' in result)) throw new Error('Expected one in-memory build output.');
    const chunks = result.output.filter((item) => item.type === 'chunk');
    return { files: Object.fromEntries(chunks.map((chunk) => [chunk.fileName, chunk.code])), entries: ['player.js', 'quiz-runner.js'] };
}
