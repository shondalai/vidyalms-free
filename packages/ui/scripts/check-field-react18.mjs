// Test the actual admin and instructor controls against WordPress's React major without
// replacing this checkout's dependencies or generating application bundles.
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ui = fileURLToPath(new URL('..', import.meta.url));
const temporaryRoot = resolve(tmpdir());
const fixture = mkdtempSync(join(temporaryRoot, 'vidyalms-react18-'));
if (dirname(resolve(fixture)) !== temporaryRoot) throw new Error('Unexpected compatibility fixture path.');
const dependencies = { react: '18.3.1', 'react-dom': '18.3.1' };
for (const name of [
    'vitest', 'happy-dom', '@testing-library/react', '@testing-library/user-event',
    '@testing-library/jest-dom', 'react-hook-form', 'clsx', 'tailwind-merge',
]) {
    dependencies[name] = JSON.parse(readFileSync(join(ui, 'node_modules', name, 'package.json'), 'utf8')).version;
}

try {
    for (const file of [
        'src/instructor/components/field.tsx', 'src/instructor/components/field.test.tsx', 'src/shared/cn.ts',
        'src/admin/components/field.tsx', 'src/admin/components/field.test.tsx',
        'src/admin/question-banks/fields.tsx', 'src/admin/question-banks/fields.test.tsx',
        'src/shared/components/visually-hidden.tsx',
    ]) {
        mkdirSync(dirname(join(fixture, file)), { recursive: true });
        copyFileSync(join(ui, file), join(fixture, file));
    }
    writeFileSync(join(fixture, 'package.json'), JSON.stringify({ private: true, type: 'module', dependencies }));
    writeFileSync(join(fixture, 'vitest.config.mjs'), `
import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';
export default defineConfig({
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    esbuild: { jsx: 'automatic' },
    test: { environment: 'happy-dom', setupFiles: ['./setup.ts'], include: ['src/**/*.test.tsx'] },
});
`);
    writeFileSync(join(fixture, 'setup.ts'), `
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
afterEach(cleanup);
`);
    // npm_execpath is supplied by npm run; use Node to avoid Windows shell quoting.
    const npm = process.env.npm_execpath;
    if (!npm) throw new Error('Run this check through npm run test:react18.');
    const install = spawnSync(process.execPath, [npm, 'install', '--ignore-scripts', '--no-audit', '--no-fund'], {
        cwd: fixture, stdio: 'inherit',
    });
    if (install.error) throw install.error;
    if (install.status !== 0) throw new Error('Could not install the isolated React 18 test dependencies.');
    const result = spawnSync(process.execPath, [join(fixture, 'node_modules/vitest/vitest.mjs'), 'run'], {
        cwd: fixture, stdio: 'inherit', env: { ...process.env, VIDYALMS_REACT18_CHECK: '1' },
    });
    if (result.error) throw result.error;
    process.exitCode = result.status ?? 1;
} finally {
    rmSync(fixture, { recursive: true, force: true });
}
