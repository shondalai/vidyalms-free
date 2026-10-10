<?php

declare(strict_types=1);

/**
 * Validate the complete native-app bundle, including hashed lazy dependencies.
 *
 * @return list<string> Required dist-relative files (including the build manifest).
 */
function vidyalmsUiManifestFiles(string $dist): array
{
    $manifestPath = $dist . '/.vite/manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('UI build manifest is missing.');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || $manifest === []) {
        throw new RuntimeException('UI build manifest is empty or invalid.');
    }

    // Validate record shapes before inspecting required keys or graph edges.
    // Malformed prebuilt output must produce a caught packaging error, not a
    // PHP offset/type error that bypasses the rebuild-or-fail path.
    $mountingFiles = [];
    foreach ($manifest as $key => $record) {
        if (!is_array($record) || !is_string($record['file'] ?? null) || $record['file'] === '') {
            throw new RuntimeException('Invalid UI manifest record: ' . $key);
        }
        foreach (['isEntry', 'isDynamicEntry'] as $field) {
            if (array_key_exists($field, $record) && !is_bool($record[$field])) {
                throw new RuntimeException('Invalid UI entry flag: ' . $key);
            }
        }
        foreach (['imports', 'dynamicImports', 'css', 'assets'] as $field) {
            if (array_key_exists($field, $record) && (!is_array($record[$field]) || !array_is_list($record[$field]))) {
                throw new RuntimeException('Invalid UI manifest list: ' . $key);
            }
        }
        if (($record['isEntry'] ?? false) === true && str_ends_with($record['file'], '.js')) {
            $mountingFiles[$record['file']] = true;
        }
    }

    $entries = ['admin', 'instructor', 'player', 'quiz-runner', 'notifications', 'credentials'];
    $files = ['.vite/manifest.json', 'public-theme.css'];
    foreach ($entries as $entry) {
        $record = $manifest['src/' . $entry . '/main.tsx'] ?? [];
        if (($record['isEntry'] ?? false) !== true || ($record['file'] ?? '') !== $entry . '.js') {
            throw new RuntimeException('Missing native UI entry: ' . $entry);
        }
        $files[] = $entry . '.js';
        $files[] = $entry . '.css';
    }
    $deferred = [
        'src/admin/credentials/templates-tab.tsx',
        'src/admin/organisations/screen.tsx',
        'src/admin/operations/screens.tsx',
    ];
    $lazyFiles = [];
    foreach ($deferred as $key) {
        $record = $manifest[$key] ?? [];
        if (($record['isDynamicEntry'] ?? false) !== true || ($record['isEntry'] ?? false) === true
            || !is_string($record['file'] ?? null) || !str_ends_with($record['file'], '.js')) {
            throw new RuntimeException('Missing lazy UI feature: ' . $key);
        }
        $lazyFiles[] = $record['file'];
    }
    if (count(array_unique($lazyFiles)) !== count($deferred)) {
        throw new RuntimeException('Lazy UI features must have separate chunks.');
    }
    foreach ($manifest as $key => $record) {
        $files[] = $record['file'];
        if (str_ends_with($record['file'], '.js') && ($record['isEntry'] ?? false) !== true
            && preg_match('/-[A-Za-z0-9_-]{8,}\.js$/D', $record['file']) !== 1) {
            throw new RuntimeException('Secondary UI chunks must be content-hashed: ' . $key);
        }
        foreach (['imports', 'dynamicImports'] as $field) {
            foreach ($record[$field] ?? [] as $dependency) {
                if (!is_string($dependency) || !isset($manifest[$dependency])) {
                    throw new RuntimeException('Missing UI dependency for: ' . $key);
                }
                if (isset($mountingFiles[$manifest[$dependency]['file']])) {
                    throw new RuntimeException('UI chunk imports a mounting entry: ' . $key);
                }
            }
        }
        foreach (['css', 'assets'] as $field) {
            foreach ($record[$field] ?? [] as $asset) {
                if (!is_string($asset)) {
                    throw new RuntimeException('Invalid UI asset: ' . $key);
                }
                $files[] = $asset;
            }
        }
    }

    // isDynamicEntry alone is insufficient: Rollup may also pull a dynamic
    // feature into an eager dependency. Walk only static edges, cycle-safely,
    // from every JavaScript entry and preserve the feature's lazy boundary.
    $pending = [];
    foreach ($manifest as $key => $record) {
        if (($record['isEntry'] ?? false) === true && str_ends_with($record['file'], '.js')) {
            $pending[] = $key;
        }
    }
    $visited = [];
    while ($pending !== []) {
        $key = array_pop($pending);
        if (isset($visited[$key])) {
            continue;
        }
        $visited[$key] = true;
        if (in_array($manifest[$key]['file'], $lazyFiles, true)) {
            throw new RuntimeException('Lazy UI feature is statically reachable from an app entry: ' . $key);
        }
        foreach ($manifest[$key]['imports'] ?? [] as $dependency) {
            $pending[] = $dependency;
        }
    }
    foreach (['vietnamese', 'latin-ext', 'latin'] as $subset) {
        $files[] = 'bricolage-grotesque-' . $subset . '-wght-normal.woff2';
    }
    $files = array_values(array_unique($files));
    foreach ($files as $file) {
        // Adapters deliberately ship a flat directory; reject traversal and unsupported assets.
        if ($file !== '.vite/manifest.json'
            && preg_match('/^[A-Za-z0-9_.-]+\.(?:js|css|woff2)$/D', $file) !== 1) {
            throw new RuntimeException('Unsupported UI output path.');
        }
        if (!is_file($dist . '/' . $file)) {
            throw new RuntimeException('Missing UI output: ' . $file);
        }
    }
    return $files;
}
