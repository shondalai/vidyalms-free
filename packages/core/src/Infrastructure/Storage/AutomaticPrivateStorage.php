<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Storage;

/** A server-signed location lets web and CLI workers use the same external directory. */
final class AutomaticPrivateStorage
{
    public const SETTING = 'vidyalms_automatic_private_storage';

    /**
     * @param list<string> $publicRoots
     * @return array{path: string, proof: string}|null
     */
    public static function resolve(string $siteRoot, array $publicRoots, string $identity, string $secret,
        mixed $record, bool $discover): ?array
    {
        if ($secret === '' || $identity === '') {
            return null;
        }
        if ($record !== null && $record !== false) {
            if (!is_array($record) || !is_string($record['path'] ?? null) || !is_string($record['proof'] ?? null)
                || !hash_equals(self::proof($record['path'], $identity, $secret), $record['proof'])) {
                throw new \RuntimeException('Automatic private storage belongs to a different site or configuration.');
            }
            return ['path' => LocalStorageSafety::privateRoot($record['path'], $publicRoots), 'proof' => $record['proof']];
        }
        // CLI cannot infer a web server's document root. Its first request waits
        // for web setup, or uses the owner's explicit VIDYALMS_PRIVATE_DIR.
        if (!$discover) {
            return null;
        }
        $parent = dirname(LocalStorageSafety::absolute($siteRoot));
        $name = '.vidyalms-private-' . substr(hash_hmac('sha256', $identity, $secret), 0, 32);
        for (;;) {
            $candidate = rtrim(str_replace('\\', '/', $parent), '/') . '/' . $name;
            try {
                $candidate = LocalStorageSafety::privateRoot($candidate, $publicRoots);
                if (!LocalStorageSafety::writableDirectory($candidate)) {
                    return null;
                }
                return ['path' => $candidate, 'proof' => self::proof($candidate, $identity, $secret)];
            } catch (\RuntimeException) {
                $next = dirname($parent);
                if ($next === $parent) {
                    return null;
                }
                $parent = $next;
            }
        }
    }

    private static function proof(string $path, string $identity, string $secret): string
    {
        return hash_hmac('sha256', "vidyalms.private-root.v1\0" . $identity . "\0" . $path, $secret);
    }
}
