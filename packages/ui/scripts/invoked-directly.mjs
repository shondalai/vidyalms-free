/* Whether node was asked to run this very file, however the path to it was spelt.

   Node gives a module its real path in import.meta.url, while process.argv[1]
   keeps the path as typed, so through a junction, a symlink or a different
   drive letter case the plain comparison is false. Each build script then
   skipped its main block and exited 0: npm run build "passed" without
   building or guarding anything, and a packager that runs it to replace a
   rejected dist/ found nothing new (seen 2026-10-01 with the package reached
   through a junction). */
import { realpathSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

function canonical(path) {
    let real = resolve(path);
    try {
        real = realpathSync.native(real);
    } catch {
        // A path that cannot be resolved is compared as typed.
    }
    return process.platform === 'win32' ? real.toLowerCase() : real;
}

export function invokedDirectly(argument, moduleUrl) {
    return typeof argument === 'string' && argument !== '' && canonical(argument) === canonical(fileURLToPath(moduleUrl));
}
