<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * An importer that reports as it goes. RunImportJob prefers this method when
 * the provider implements it, and flushes every note to the job log the
 * moment it arrives, so a migration of two thousand questions is watchable
 * instead of silent for four minutes.
 *
 * There is no return value on purpose: everything the run produces has
 * already gone through the progress sink, so a failure halfway keeps exactly
 * what completed before it. Extend AbstractImporter to satisfy both this and
 * ImporterInterface with one method.
 */
interface ProgressiveImporterInterface extends ImporterInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void;
}
