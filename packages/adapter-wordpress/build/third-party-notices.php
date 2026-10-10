<?php

declare(strict_types=1);

/**
 * Third-party notices for the compiled interface in assets/app.
 *
 * The bundle keeps code from the npm libraries the interface is built with, and
 * Vite strips their licence headers, so the plugin carries the notices in one
 * file at its root. The editable source and the lockfiles are not shipped in
 * the ZIP: they are published in the public source repository the readme links
 * to, and the dependency list here is read from the same lockfiles.
 *
 * A packager include, not a WordPress file: build.php requires it and supplies
 * fail().
 *
 * @license GPL-3.0-or-later
 */
function vidyalmsFrontendNotices(string $repository, string $sourceUrl): string
{
    $notices = "VidyaLMS compiled interface: third-party notices\n"
        . "================================================\n\n"
        . "The JavaScript, CSS and font files in assets/app/ are compiled from\n"
        . "packages/ui and packages/api-sdk in " . $sourceUrl . "\n"
        . "using the package-lock.json files recorded there. VidyaLMS itself is\n"
        . "GPL-3.0-or-later. The libraries below are the production dependencies in\n"
        . "those lockfiles, and their original licence terms apply to them. React,\n"
        . "ReactDOM and the JSX runtime are listed because the interface depends on\n"
        . "them, but the WordPress build does not bundle them: it uses the copies\n"
        . "WordPress supplies.\n";
    $seen = [];

    foreach (['ui', 'api-sdk'] as $package) {
        $source = $repository . '/packages/' . $package;
        $lock = json_decode((string) file_get_contents($source . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($lock['packages'] ?? [] as $path => $info) {
            if (!str_starts_with($path, 'node_modules/') || ($info['dev'] ?? false) || ($info['link'] ?? false)) {
                continue;
            }

            $name = substr($path, strrpos('/' . $path, '/node_modules/') + strlen('node_modules/'));
            $identity = $name . '@' . ($info['version'] ?? '');

            if (isset($seen[$identity]) || !is_dir($source . '/' . $path)) {
                continue;
            }

            $seen[$identity] = true;
            $notices .= "\n===== {$identity} (" . ($info['license'] ?? 'see licence below') . ") =====\n";
            $found = false;

            foreach (scandir($source . '/' . $path) ?: [] as $file) {
                if (preg_match('/^(licen[cs]e|copying|notice|ofl)([.\-_]|$)/i', $file) !== 1 || !is_file($source . '/' . $path . '/' . $file)) {
                    continue;
                }

                $notices .= "\n{$file}\n" . file_get_contents($source . '/' . $path . '/' . $file) . "\n";
                $found = true;
            }

            if (!$found) {
                $retained = __DIR__ . '/licenses/' . str_replace(['/', '@'], ['-', '-'], $identity) . '.txt';

                if (!is_file($retained)) {
                    fail('Dependency licence file is missing: ' . $identity);
                }

                // Some npm tarballs omit the upstream licence. Retain the exact
                // version's licence in this build tree rather than fetching at
                // build time.
                $notices .= "\n" . file_get_contents($retained) . "\n";
            }
        }
    }

    if ($seen === []) {
        fail('No production dependencies found for the interface notices; run npm ci in packages/ui and packages/api-sdk.');
    }

    return $notices;
}
