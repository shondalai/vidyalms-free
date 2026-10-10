<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * An importer whose source has parts, loaded in the order the runner keeps.
 *
 * A capability interface rather than a method on ImporterInterface, on the
 * usual terms: a single-file importer has no stages and should not have to say
 * so. What this buys is that the load order lives in one place. A mapper that
 * implements this declares which parts its source carries and how to load one;
 * it never decides which goes first, because {@see ImportLoadOrder} does, and
 * the runner sorts the declaration before it drives anything.
 *
 * The runner calls importStage() once per stage, in order, on the same
 * progress sink, so the totals, the notes and the row problems of a seven part
 * load are one run's rather than seven.
 */
interface StagedImporterInterface extends ImporterInterface
{
    /**
     * The ImportLoadOrder stages this source actually carries.
     *
     * Declared per source rather than per importer, because a OneRoster
     * package's manifest says which files are present and a package with no
     * orgs.csv genuinely has no organisations stage. Reading the manifest here
     * is expected; reading the data files is not.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    public function stages(string $sourcePath, array $options): array;

    /**
     * Loads one stage. Called in ImportLoadOrder order, never in declaration
     * order, and always with a stage this importer named.
     *
     * @param array<string, mixed> $options
     */
    public function importStage(
        string $stage,
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void;
}
