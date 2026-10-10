<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Storage;

/**
 * File storage split into two visibilities. Public assets (course images) go
 * to the CMS media area. Private files (assignment submissions, certificate
 * PDFs, SCORM packages) go to a protected area, and the core only ever hands
 * them out as a signed, expiring URL.
 *
 * Both local host adapters require private storage outside known public roots
 * and fail closed before private I/O when deployment cannot provide it. Server
 * deny files are defense in depth only. The operator must not expose the root
 * through an additional virtual host or alias. Nothing in the core emits a
 * direct URL to a private file; callers must authorize readPrivate().
 *
 * Paths are relative, forward-slash, and logical ("submissions/42/report.pdf");
 * the adapter decides the physical location.
 */
interface StoragePort
{
    public function putPublic(string $path, string $contents): void;

    public function putPrivate(string $path, string $contents): void;

    public function readPrivate(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function publicUrl(string $path): string;

    /**
     * A URL to a private file that expires after the given number of seconds.
     */
    public function signedUrl(string $path, int $expiresInSeconds): string;
}
