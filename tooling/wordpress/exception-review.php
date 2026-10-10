<?php

declare(strict_types=1);

/**
 * Audit typed exception arguments reported as HTML by WordPressCS.
 *
 * By default this tool never changes code or disables output checks. With --annotate it
 * documents only reported lines inside PHP throw statements, after verifying
 * them against the installed package and source. Future output on those lines
 * is still checked by EscapeOutput.OutputNotEscaped.
 *
 * @license GPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$repository = dirname(__DIR__, 2);

/** Comments may document an exception; they must never change executable code or strings. */
function executableTokens(string $source): array
{
    $result = [];
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $result[] = [$token[0], $token[1]];
        } else {
            $result[] = $token;
        }
    }
    return $result;
}
if (in_array('--verify', $argv, true)) {
    $count = 0;
    $files = 0;
    foreach (['packages/core/src', 'packages/pdf-runtime/src', 'packages/adapter-wordpress/vidyalms/src'] as $directory) {
        // The PDF runtime ships with Pro since 1.0.1 and is not part of the
        // public free source, so it is verified only where it is present. The
        // two folders the free plugin is built from must always exist.
        if ($directory === 'packages/pdf-runtime/src' && !is_dir($repository . '/' . $directory)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repository . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            if (!str_contains($text, 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped')) {
                continue;
            }
            $lines = explode("\n", str_replace("\r\n", "\n", $text));
            $throws = [];
            $outputs = [];
            $inThrow = false;
            $line = 1;
            foreach (token_get_all($text, TOKEN_PARSE) as $token) {
                $value = is_array($token) ? $token[1] : $token;
                if (is_array($token) && $token[0] === T_THROW) {
                    $inThrow = true;
                }
                if (is_array($token) && in_array($token[0], [T_ECHO, T_PRINT, T_INLINE_HTML], true)) {
                    $outputs[$line] = true;
                }
                if ($inThrow) {
                    for ($i = $line; $i <= $line + substr_count($value, "\n"); $i++) {
                        $throws[$i] = true;
                    }
                }
                if ($token === ';') {
                    $inThrow = false;
                }
                $line += substr_count($value, "\n");
            }
            foreach ($lines as $index => $textLine) {
                if (!str_contains($textLine, 'phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped')) {
                    continue;
                }
                $target = str_starts_with(ltrim($textLine), '//') ? $index + 2 : $index + 1;
                if (!isset($throws[$target]) || isset($outputs[$target]) || trim($lines[$target - 1] ?? '') === '') {
                    throw new RuntimeException('Exception annotation is not confined to a throw argument: ' . $file->getPathname() . ':' . $target);
                }
                $count++;
            }
            $files++;
        }
    }
    echo json_encode(['verified_exception_annotations' => $count, 'files' => $files, 'output_suppression' => false], JSON_PRETTY_PRINT), "\n";
    exit(0);
}
$report = $argv[1] ?? '';
$annotate = in_array('--annotate', $argv, true);
$findings = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
$sources = [];
$mapped = 0;
foreach ($findings as $finding) {
    if ($finding['code'] !== 'WordPress.Security.EscapeOutput.ExceptionNotEscaped') {
        continue;
    }
    $installed = str_replace('\\', '/', $finding['file']);
    $relative = explode('/plugins/vidyalms/', $installed, 2)[1] ?? '';
    $source = match (true) {
        str_starts_with($relative, 'core/') => $repository . '/packages/core/src/' . substr($relative, 5),
        str_starts_with($relative, 'pdf-runtime/src/') => $repository . '/packages/pdf-runtime/src/' . substr($relative, 16),
        str_starts_with($relative, 'src/') => $repository . '/packages/adapter-wordpress/vidyalms/' . $relative,
        default => throw new RuntimeException('Unrecognized exception source: ' . $relative),
    };
    if (!isset($sources[$source])) {
        $text = (string) file_get_contents($source);
        $sources[$source] = ['text' => $text, 'lines' => explode("\n", str_replace("\r\n", "\n", $text)), 'reviewed' => []];
    }
    $originalLines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($installed)));
    $original = trim($originalLines[$finding['line'] - 1]);
    $candidates = [];
    foreach ($sources[$source]['lines'] as $index => $line) {
        if (trim($line) === $original) {
            $candidates[] = $index;
        }
    }
    if ($candidates === []) {
        throw new RuntimeException('Source changed; rescan before annotating: ' . $relative . ':' . $finding['line']);
    }
    usort($candidates, static fn (int $a, int $b): int => abs($a + 1 - $finding['line']) <=> abs($b + 1 - $finding['line']));
    $sources[$source]['reviewed'][$candidates[0] + 1] = true;
    $mapped++;
}

$lineCount = 0;
foreach ($sources as $file => &$source) {
    $throwLines = [];
    $inThrow = false;
    $line = 1;
    foreach (token_get_all($source['text'], TOKEN_PARSE) as $token) {
        $value = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_THROW) {
            $inThrow = true;
        }
        if ($inThrow && !is_array($token) && $token === ';') {
            $throwLines[$line] = true;
            $inThrow = false;
        } elseif ($inThrow) {
            if (is_array($token) && in_array($token[0], [T_ECHO, T_PRINT, T_INLINE_HTML], true)) {
                throw new RuntimeException('Unexpected output inside exception expression: ' . $file);
            }
            for ($i = $line; $i <= $line + substr_count($value, "\n"); $i++) {
                $throwLines[$i] = true;
            }
        }
        $line += substr_count($value, "\n");
    }
    foreach (array_keys($source['reviewed']) as $line) {
        if (!isset($throwLines[$line]) || str_contains($source['lines'][$line - 1], '//')) {
            throw new RuntimeException('Manual inspection required: ' . $file . ':' . $line);
        }
        preg_match('/^[ \t]*/', $source['lines'][$line - 1], $indent);
        $source['lines'][$line - 1] = $indent[0] . '// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.' . "\n"
            . $source['lines'][$line - 1];
        $lineCount++;
    }
    // Do not write until every changed file still parses, including multiline strings.
    $source['result'] = implode("\n", $source['lines']);
    if (str_contains($source['text'], "\r\n")) {
        $source['result'] = str_replace("\n", "\r\n", $source['result']);
    }
    token_get_all($source['result'], TOKEN_PARSE);
    if (executableTokens($source['text']) !== executableTokens($source['result'])) {
        throw new RuntimeException('Annotation changed executable code or string data: ' . $file);
    }
}
unset($source);
if ($annotate) {
    foreach ($sources as $file => $source) {
        file_put_contents($file, $source['result']);
    }
}
echo json_encode(['diagnostics' => $mapped, 'throw_argument_lines' => $lineCount, 'files' => count($sources), 'annotated' => $annotate], JSON_PRETTY_PRINT), "\n";
