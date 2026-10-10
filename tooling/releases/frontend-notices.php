<?php

declare(strict_types=1);

/** Retain upstream copyright and licence notices alongside compiled assets. */
function vidyalmsFrontendNotices(string $repository, string $destination, bool $pro = false): void
{
    $notices = "VidyaLMS frontend dependency notices\n";
    $seen = [];
    foreach ($pro ? ['ui', 'api-sdk', 'pro-ui'] : ['ui', 'api-sdk'] as $package) {
        $source = $repository . '/packages/' . $package;
        $lock = json_decode((string) file_get_contents($source . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($lock['packages'] ?? [] as $path => $info) {
            if (!str_starts_with($path, 'node_modules/') || ($info['link'] ?? false)) continue;
            // Pro declares its browser runtime dependencies as devDependencies;
            // build tools themselves are not distributed in the browser bundle.
            if (($info['dev'] ?? false) && ($package !== 'pro-ui'
                || preg_match('~^node_modules/(react|react-dom|scheduler|clsx|tailwind-merge|zod)(/|$)~', $path) !== 1)) continue;
            $name = substr($path, strrpos('/' . $path, '/node_modules/') + strlen('node_modules/'));
            $identity = $name . '@' . ($info['version'] ?? '');
            if (isset($seen[$identity]) || !is_dir($source . '/' . $path)) continue;
            $seen[$identity] = true;
            $notices .= "\n===== {$identity} (" . ($info['license'] ?? 'see licence below') . ") =====\n";
            $found = false;
            foreach (scandir($source . '/' . $path) ?: [] as $file) {
                if (preg_match('/^(licen[cs]e|copying|notice|ofl)([.\-_]|$)/i', $file) !== 1
                    || !is_file($source . '/' . $path . '/' . $file)) continue;
                $notices .= "\n{$file}\n" . file_get_contents($source . '/' . $path . '/' . $file) . "\n";
                $found = true;
            }
            if (!$found) {
                $retained = $repository . '/packages/adapter-wordpress/build/licenses/'
                    . str_replace(['/', '@'], ['-', '-'], $identity) . '.txt';
                if (!is_file($retained)) throw new RuntimeException('Dependency licence file is missing: ' . $identity);
                $notices .= "\n" . file_get_contents($retained) . "\n";
            }
        }
    }
    if (file_put_contents($destination . '/THIRD-PARTY-LICENSES.txt', $notices) === false) {
        throw new RuntimeException('Cannot write frontend licence notices.');
    }
}
