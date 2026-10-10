<?php

declare(strict_types=1);

/** Build-only isolation of the two PSR interface packages used by WordPress. */
function vidyalmsScopeWordPressPsr(string $staged): int
{
    $prefix = 'Shondalai\\VidyaLMS\\WordPressVendor\\';
    $changed = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staged, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains(str_replace('\\', '/', $file->getPathname()), '/pdf-runtime/')) {
            continue; // The PDF runtime has its own PHP-Scoper isolation.
        }
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            throw new RuntimeException('Cannot read staged PHP for PSR isolation.');
        }
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
                continue;
            }
            [$kind, $text] = $token;
            if (in_array($kind, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && str_starts_with(ltrim($text, '\\'), 'Psr\\')) {
                $text = (str_starts_with($text, '\\') ? '\\' : '') . $prefix . ltrim($text, '\\');
            } elseif ($kind === T_CONSTANT_ENCAPSED_STRING) {
                // The generated class map and interface_exists guards contain
                // literal FQCNs. Only a complete PSR namespace at the start of
                // the string is eligible; prose and unrelated strings stay put.
                $quote = $text[0];
                $value = substr($text, 1, -1);
                if (str_starts_with($value, 'Psr\\')) {
                    $text = $quote . str_replace('\\', '\\\\', $prefix) . $value . $quote;
                }
            }
            $result .= $text;
        }
        if ($result !== $source) {
            if (file_put_contents($file->getPathname(), $result) === false) {
                throw new RuntimeException('Cannot write isolated PSR references.');
            }
            $changed++;
        }
    }
    return $changed;
}
