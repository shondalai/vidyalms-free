<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\RosterMapper;

use Shondalai\VidyaLMS\Core\Domain\Roster\RosterConformance;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterManifest;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterSource;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A roster format in one direction, keyed exactly as an item mapper is.
 *
 * keyFor() returns "{format}:{direction}", so a reader and a writer of one
 * format are two providers and never a duplicate key. Registering both
 * under one bare format would throw on a paid site, and because both
 * shipped adapters log and swallow a throwing kernel-ready listener,
 * everything that package had not yet registered would be lost with it.
 *
 * Miss direction: visible and incomplete: the importer refuses the format by name and writes nothing.
 *
 * ## What a mapper is, and what it is deliberately not
 *
 * It is bytes in, canonical records out, and nothing else. It holds no port,
 * opens no file, writes nothing, and takes no `Principal`: the importer reads
 * the source through StoragePort and hands over a {@see RosterSource}, and the
 * importer writes every record through the same use cases the console uses.
 * That is the anti-corruption boundary made literal, and it is what makes the
 * whole of a format's parsing testable from a string literal, with a file set
 * out of the specification's own examples as a fixture rather than a zip.
 *
 * The corollary is the rule the prohibition exists for: **nothing
 * protocol-shaped crosses this boundary.** A `sourcedId`, a `roleType`, a
 * `manifest.version` or a SCIM `PatchOp` stops here; what comes out is
 * `OrgRecord`, `PersonRecord`, `OrgMembershipRecord` and
 * `CourseAssignmentRecord`.
 *
 * ## Stages, and why the mapper declares them and does not order them
 *
 * A package's manifest says which files are present, so which stages a source
 * carries is the mapper's answer. Which order they load in is not: that is
 * {@see ImportLoadOrder}'s, stated once and enforced by the runner, because a
 * mapper that loads enrolments before people discovers it on a customer's
 * roster rather than in a test.
 */
interface RosterMapperInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * What this mapper reads and what it deliberately does not.
     *
     * Conformance is a stated non-goal, and it is stated here so an importer
     * can write it into the first line of every run's log and a catalogue route
     * can publish it. A customer has to be able to see the subset before they
     * upload anything.
     */
    public function conformance(): RosterConformance;

    /**
     * Whether a source path looks like this format, for the screen that asks
     * which importer handles a file.
     */
    public function supports(string $sourcePath): bool;

    /**
     * The manifest a package carries, or {@see RosterManifest::none()} for a
     * format that has none.
     *
     * Processing mode is declared per file, so this is what the importer reads
     * to know whether one file's rows are authoritative and another's are a
     * delta.
     */
    public function manifest(RosterSource $source): RosterManifest;

    /**
     * The ImportLoadOrder stages this source actually carries.
     *
     * Declared per source rather than per format, because a package with no
     * orgs.csv genuinely has no organisations stage.
     *
     * @return list<string>
     */
    public function stages(RosterSource $source): array;

    /**
     * The rows of one stage, in file order, each either a canonical record or
     * the row problem that says why it is not one.
     *
     * A generator is expected rather than an array: a forty thousand row file
     * that has to exist in memory as forty thousand objects before the first
     * one is written is a file that fails on the host's memory limit rather
     * than on anything about the roster.
     *
     * @return iterable<RosterRow>
     */
    public function read(RosterSource $source, string $stage): iterable;
}
