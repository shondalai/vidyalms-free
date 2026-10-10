<?php

/**
 * Joomla distribution headers for the CMS-neutral learning engine.
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license GNU General Public License version 3 or later; see LICENSE
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/**
 * The JED upload limit both Joomla packages are measured against, in bytes.
 *
 * Stated once here because both packagers print their package against it and
 * the release tests read the same number.
 */
const JOOMLA_JED_UPLOAD_LIMIT = 7_000_000;

/**
 * Whether a file belongs in a Joomla distribution at all.
 *
 * Source maps are a development aid: they reproduce the unminified sources
 * inside the package and roughly double the size of every bundle. The sources
 * are published in the public repository, which is where a reviewer reads
 * them, so no script or stylesheet map ships in either edition.
 *
 * Only those two kinds. php-font-lib's maps/*.map files are font encoding
 * tables the PDF engine reads at runtime, and the PDF runtime is a
 * checksummed third-party tree that is shipped exactly as packaged.
 */
function joomlaDistributionShips(string $entry): bool
{
    if (str_starts_with($entry, 'pdf-runtime/')) {
        return true;
    }

    return preg_match('~\.(?:m?js|css)\.map\z~i', $entry) !== 1;
}

/**
 * Drops the trailing sourceMappingURL comment from a shipped script or stylesheet.
 *
 * The map itself never ships (joomlaDistributionShips), so the comment would
 * only send a browser's developer tools after a file that answers 404. Only the
 * comment the bundler writes as the last line goes; every byte above it is kept.
 * The PDF runtime and Composer vendor trees are third-party files under their
 * own checksums and are returned untouched.
 */
function joomlaDistributionAsset(string $body, string $entry): string
{
    if (str_starts_with($entry, 'pdf-runtime/') || str_starts_with($entry, 'vendor/')) {
        return $body;
    }

    if (str_ends_with($entry, '.js')) {
        $pattern = '~\R?//[#@] sourceMappingURL=[^\r\n]*\R?\z~';
    } elseif (str_ends_with($entry, '.css')) {
        $pattern = '~\R?/\*[#@] sourceMappingURL=[^\r\n]*?\*/\R?\z~';
    } else {
        return $body;
    }

    $stripped = preg_replace($pattern, '', $body, 1);

    if (!\is_string($stripped)) {
        throw new RuntimeException('Cannot strip the source map comment from ' . $entry);
    }

    // The comment was the last line, so the file still ends with a newline.
    return $stripped === $body || $stripped === '' ? $stripped : $stripped . "\n";
}

/**
 * Transform only our Joomla-delivered PHP. The shared source remains usable in
 * WordPress and unit tests. PDF dependencies run in an isolated CLI worker and
 * ship only in the Pro library, with their original licenses and checksum
 * manifest.
 */
function joomlaDistributionPhp(string $body, string $entry): string
{
    // Composer dependencies retain upstream licensing and namespace structure.
    // Only our application PHP receives the Joomla distribution transform.
    if (!str_ends_with($entry, '.php') || str_starts_with($entry, 'pdf-runtime/') || str_starts_with($entry, 'vendor/')) {
        return $body;
    }

    $tokens = PhpToken::tokenize($body, TOKEN_PARSE);
    if (($tokens[0]->id ?? null) !== T_OPEN_TAG) {
        throw new RuntimeException('Expected a PHP opening tag in ' . $entry);
    }
    $openingEnd = strlen($tokens[0]->text);
    $position = $openingEnd;
    $index = 1;
    $header = '';
    // Only declarations, namespace and imports may precede the access guard.
    while (isset($tokens[$index])) {
        $token = $tokens[$index];
        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $header .= $token->text;
            $index++;
            continue;
        }
        if (!$token->is([T_DECLARE, T_NAMESPACE, T_USE])) {
            break;
        }
        $declaration = $token->id;
        do {
            $token = $tokens[$index++];
            if ($token->text === '{' && $declaration === T_NAMESPACE) {
                throw new RuntimeException('Bracketed namespaces are not supported by the Joomla distribution transform: ' . $entry);
            }
        } while ($token->text !== ';' && isset($tokens[$index]));
        if ($token->text !== ';') {
            throw new RuntimeException('Unterminated PHP declaration in ' . $entry);
        }
        $position = $token->pos + strlen($token->text);
    }

    $tail = substr($body, $tokens[$index]->pos ?? strlen($body));
    $guarded = preg_match('/^\\\\?defined\s*\(\s*([\'"])_JEXEC\1\s*\)\s*(?:or|\|\|)\s*(?:die|exit)\b/', $tail) === 1;
    if (!$guarded) {
        $body = substr_replace($body, "\n\ndefined('_JEXEC') or die;\n", $position, 0);
    }
    if (!preg_match('/@license\b|SPDX-License-Identifier:/i', $header)) {
        $notice = "\n/**\n * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.\n"
            . " * @license GNU General Public License version 3 or later; see LICENSE\n */\n";
        $body = substr_replace($body, $notice, $openingEnd, 0);
    } elseif (stripos($header, 'proprietary') !== false) {
        throw new RuntimeException('Conflicting license notice in ' . $entry);
    }
    // Never ship a transformed file whose namespace/declare order is invalid.
    PhpToken::tokenize($body, TOKEN_PARSE);
    return $body;
}
