/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import externalGlobals from 'rollup-plugin-external-globals';

const src = (p: string) => fileURLToPath(new URL(p, import.meta.url));

/**
 * Every static import cycle in a module graph, each once, as the path from
 * its first module back to it. Depth first; a back edge closes one cycle.
 */
export function moduleCycles(graph: ReadonlyMap<string, readonly string[]>): string[][] {
    const state = new Map<string, 'open' | 'done'>();
    const path: string[] = [];
    const cycles: string[][] = [];
    const seen = new Set<string>();
    const visit = (node: string): void => {
        state.set(node, 'open');
        path.push(node);
        for (const next of graph.get(node) ?? []) {
            if (state.get(next) === 'open') {
                const cycle = [...path.slice(path.indexOf(next)), next];
                const key = [...cycle.slice(0, -1)].sort().join('\n');
                if (!seen.has(key)) {
                    seen.add(key);
                    cycles.push(cycle);
                }
            } else if (!state.has(next)) {
                visit(next);
            }
        }
        path.pop();
        state.set(node, 'done');
    };
    for (const node of [...graph.keys()].sort()) {
        if (!state.has(node)) visit(node);
    }
    return cycles;
}

/**
 * Build gate: no static import cycle between the app's own source modules.
 *
 * check-app-entry-imports.mjs sees cycles between emitted chunks, and a
 * cycle inside one chunk is invisible to it. Rollup keeps such a cycle in
 * the order the modules asked for, which only holds while nothing reads
 * across it at module scope; one eager read turns it into a temporal dead
 * zone error in every entry, with no Rollup warning. That was
 * shared/availability.ts and availability-reasons/free.ts, both in the
 * shared chunk, until 2026-09-30. Dynamic import() is not an edge; type-only
 * imports are erased before Rollup sees them.
 */
export function appSourceCycleGuard(root: string = src('./src')): Plugin {
    const base = root.replace(/\\/g, '/').replace(/\/?$/, '/');
    const own = (id: string) => {
        const path = id.replace(/\\/g, '/').split('?')[0] ?? '';
        return path.startsWith(base) && /\.[cm]?[jt]sx?$/.test(path);
    };
    const shown = (id: string) => id.replace(/\\/g, '/').slice(base.length);
    return {
        name: 'vidyalms-source-cycle-guard',
        apply: 'build',
        buildEnd(error) {
            if (error) return;
            const graph = new Map<string, string[]>();
            for (const id of this.getModuleIds()) {
                if (!own(id)) continue;
                graph.set(id, (this.getModuleInfo(id)?.importedIds ?? []).filter(own));
            }
            const cycles = moduleCycles(graph);
            if (cycles.length > 0) {
                this.error(`static import cycle between source modules: ${cycles.map((cycle) => cycle.map(shown).join(' -> ')).join('; ')}.`
                    + ' Rollup evaluates a cycle in whatever order the first importer reaches it, so a module-scope read across it'
                    + ' is a temporal dead zone error at load; move what both sides need into a module that imports neither.');
            }
        },
    };
}

export const appOutputNames = {
    entryFileNames: '[name].js',
    chunkFileNames: '[name]-[hash].js',
    assetFileNames: '[name][extname]',
};

/* Shared admin primitives must never be exported from a mounting entry.
   CMS adapters load admin.js?version=...; a lazy chunk importing ./admin.js
   would evaluate a second entry URL, recreate its contexts and mount again.
   Keep this list structural and explicit: feature screens stay lazy. */
const adminFoundationFiles = new Set([
    'config.ts', 'hooks.ts', 'lib.ts', 'api.ts', 'strings.ts',
    'credential-strings.ts', 'people-strings.ts', 'notification-strings.ts',
    'screens/registry.ts', 'credentials/fields.tsx', 'credentials/element-settings.tsx',
    'credentials/studio-routes.ts', 'credentials/studio-strings.ts',
    'credentials/workspace.tsx', 'credentials/workspace-strings.ts', 'credentials/studio-drafts.tsx', 'learners/course-picker.tsx',
    'organisations/strings.ts', 'operations/strings.ts',
]);

export function appManualChunk(id: string): string | undefined {
    const normalized = id.replace(/\\/g, '/');
    // CSS stays with its importing entry; no shared JavaScript chunk owns it.
    if (normalized.split('?')[0]?.endsWith('.css')) return undefined;
    if (/\/node_modules\/(?:@tiptap\/|prosemirror-)/.test(normalized)) return 'article-editor-vendor';
    if (/\/node_modules\/(?:swup\/|@swup\/|delegate-it\/|path-to-regexp\/|compute-scroll-into-view\/|delegate-events\/|scrl\/)/.test(normalized)) return 'learner-navigation-vendor';
    if (normalized.includes('/node_modules/')) return 'vendor';
    if (normalized.includes('/src/shared/')) return 'shared';
    if (normalized.includes('/src/question-types/')) return 'question-types';
    const adminPath = normalized.split('/src/admin/')[1];
    if (adminPath !== undefined && (adminFoundationFiles.has(adminPath) || adminPath.startsWith('components/'))) {
        return 'admin-foundation';
    }
    return undefined;
}

/**
 * Several apps, one package. The build is a "library of apps": fixed entry
 * and CSS names (the adapters cache-bust those by extension version),
 * relative base (the adapters serve dist/ from their own media folder), and
 * a deterministic chunk layout: player.js / quiz-runner.js / instructor.js /
 * admin.js entries, shared-[hash].js for the shared foundation,
 * admin-foundation-[hash].js for cross-screen admin primitives, and hashed
 * vendor/lazy chunks. A changed dependency changes its importers' hashes,
 * so an upgrade cannot reuse old unversioned secondary JavaScript. Mounting
 * entries are leaves of the import graph, never dependencies of chunks.
 * Entries are ES modules; the adapters load them with <script type="module">
 * plus the matching CSS file.
 */
export default defineConfig(({ mode }) => ({
    base: './',
    plugins: [react(), appSourceCycleGuard(), ...(mode === 'wordpress' ? [{
        ...externalGlobals({ react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM', 'react/jsx-runtime': 'ReactJSXRuntime' }),
        enforce: 'post' as const,
    }] : [])],
    resolve: {
        alias: {
            '@': src('./src'),
        },
    },
    build: {
        outDir: mode === 'wordpress' ? 'dist-wordpress' : 'dist',
        emptyOutDir: true,
        target: 'es2022',
        cssCodeSplit: true,
        modulePreload: false,
        sourcemap: true,
        manifest: true,
        rollupOptions: {
            input: {
                'learner-navigation': src('./src/learner/main.ts'),
                player: src('./src/player/main.tsx'),
                'quiz-runner': src('./src/quiz-runner/main.tsx'),
                instructor: src('./src/instructor/main.tsx'),
                admin: src('./src/admin/main.tsx'),
                notifications: src('./src/notifications/main.tsx'),
                credentials: src('./src/credentials/main.tsx'),
                'public-theme': src('./src/shared/public-theme.css'),
            },
            output: {
                ...appOutputNames,
                manualChunks: appManualChunk,
            },
        },
    },
    test: {
        environment: 'happy-dom',
        environmentOptions: { happyDOM: { settings: { disableIframePageLoading: true } } },
        setupFiles: ['./vitest.setup.ts'],
        css: false,
        include: ['src/**/*.test.{ts,tsx}'],
    },
}));
