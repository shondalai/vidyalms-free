<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A migration path in: Moodle backup (.mbz), LearnDash, Tutor LMS, LifterLMS,
 * Shika, Guru, OSCampus, Community Quiz. All importers are free on purpose.
 * An importer writes through the same use cases as the UI, so imported data
 * obeys the same rules. An importer that writes a table directly is a bug, no
 * matter how much faster it would be.
 *
 * ## What a source is
 *
 * The source is a string, and it is one of two things:
 *
 * - a private storage path, for importers that read an uploaded file:
 *   "imports/42/backup.mbz", readable through StoragePort::readPrivate().
 *   The bytes never travel through the API; the adapter takes the multipart
 *   upload, stores it, and passes the stored path, exactly as assignment
 *   submissions do.
 * - a scheme URI, for sources that are not files. "cms-db:" means the tables
 *   of the host CMS database the core already reaches through DatabasePort,
 *   which is how a Community Quiz or LearnDash importer reads data sitting in
 *   the same database. A scheme may carry a selector after the colon
 *   ("cms-db:categories=3,7"); its meaning belongs to the importer that
 *   declares it.
 *
 * supports() must answer for both without reading anything expensive: match
 * the scheme, or the extension and magic bytes of a stored file.
 *
 * ## What import() returns
 *
 * The serialised shape of Application\Platform\ImportResult:
 *
 *   {
 *     "totals":  {"courses": 1, "lessons": 12, "skipped": 2},
 *     "notes":   [{"level": "info|warning|error", "message": "..."}],
 *     "dry_run": false
 *   }
 *
 * Build an ImportResult and return its toArray(); RunImportJob reads it back
 * and serialises it onto the job. Importers that want their notes in the job
 * log while they still run implement ProgressiveImporterInterface instead,
 * and report through ImportProgress; extending AbstractImporter gives that
 * for free.
 *
 * ## Idempotency
 *
 * Record every created object through Application\Platform\ImportMap, and look
 * it up before creating anything. A second run of the same source must update
 * what the first run created, never duplicate it.
 *
 * A row's mapping is recorded **before the next row is read**, not batched to
 * the end of the file. A process is killed by an OOM, a deploy or a host that
 * recycles its workers, and the only thing that makes the re-run resume rather
 * than restart is that what it already wrote is already in the map. The
 * acceptance test is running one file three times and asserting created counts
 * of N, 0, 0 with unchanged counts of 0, N, N.
 *
 * ## A roster load is an importer, not a second pipeline
 *
 * `org-members-csv:in` and `oneroster-csv-1.2:in` register here like every
 * other format and inherit jobs, heartbeats, live progress, the job log, the
 * dry run and the declared-options screen for nothing. A parallel rostering
 * pipeline would have to reimplement all six and would then have its own
 * error model, which is the thing this contract exists to prevent.
 *
 * ## The organisation scoped source_key composition
 *
 * import_map's unique index is (importer, source_type, source_key) and it is
 * global across the site, while a foreign natural key is unique only inside
 * the system that issued it. Every source_key for one of the reserved
 * organisation source types is therefore minted as
 * '{organisation_id}:{foreign natural key}', through
 * ImportSourceKey::forOrganisation(), and the organisation comes from the run
 * context and never from the file.
 *
 * Stated here once rather than per mapper, because two districts exporting
 * `school-01` from the same student information system would otherwise share
 * one map row: organisation B's run would find organisation A's unit, treat it
 * as already created, and either write B's roster into A's tree or skip B's
 * row and report success. The reserved words, and the rule that a foreign key
 * is never an email address, are on ImportSourceKey; ImportMap refuses a
 * reserved type whose key does not carry the tenant, so a mapper cannot omit
 * it by accident. An importer declares which words it mints through the
 * DeclaresSourceTypes capability interface.
 *
 * ## Rows that go wrong
 *
 * A row an importer could not use is an Application\Platform\RowProblem with a
 * file, a 1 based row number including the header, a column, one of eight
 * published codes and an outcome, recorded against the job rather than written
 * into the job log. The log is rewritten whole on every progress flush, so ten
 * thousand bad rows in a forty thousand row file would make the flush
 * quadratic; the log gets one summary note per file instead. A malformed file
 * is a set of row problems and a completed_with_problems job, not a failed one.
 */
interface ImporterInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * Whether this source looks like this importer's format. Takes a private
     * storage path or a scheme URI, per the source convention above.
     *
     * This is detection for the admin screen that asks "which importer
     * handles this file?", not a gate: the pipeline runs the importer the
     * caller named. An importer that would fail on a source it does not
     * recognise should say so itself, at the top of import().
     */
    public function supports(string $sourcePath): bool;

    /**
     * Runs the import and returns the serialised ImportResult described
     * above: totals for the admin screen, notes for the job log.
     *
     * A run whose options carry "dry_run" => true analyses only: it counts
     * what it would create and writes nothing, neither through a use case nor
     * into the import map.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function import(string $sourcePath, array $options, Principal $principal): array;
}
