<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ImportMapEntry;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportMapRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportSourceKey;

/**
 * The memory that makes an import repeatable. Before creating anything, an
 * importer asks lookup() whether it already brought that source object in; if
 * it did, it updates the local object it finds instead of creating a second
 * one. After creating or updating, it calls remember().
 *
 * That single discipline buys three things: a re-run of the same source
 * updates rather than duplicates, an import that died halfway resumes by
 * skipping what it already created, and an admin can be told where a course
 * came from.
 *
 * Keys are the source system's own identity for the object: a Moodle module
 * id, a Community Quiz quiz id, a path inside an archive. Anything stable
 * across runs of the same source will do; anything derived from position in
 * a file will not, because reordering the file then re-creates everything.
 *
 * Source keys longer than the column takes are folded to a digest by
 * normaliseKey(), so callers may pass a long natural key without truncating
 * it themselves. Both remember() and lookup() fold, so the pair stays
 * consistent.
 */
final class ImportMap
{
    /** The stored width of source_key in schema/tables.php. */
    private const KEY_LIMIT = 190;

    /**
     * The organisation the run in progress belongs to, or null outside a run
     * and inside a site-wide one.
     *
     * ImportRunner sets it for the length of one run and clears it afterwards,
     * which is what lets the tenant rule compare a key's prefix against the
     * run's own organisation rather than merely noticing that the key has
     * digits and a colon in front of it. It is deliberately not a constructor
     * argument: the map is one shared service and a run is a scope inside it.
     */
    private ?int $runOrganisationId = null;

    public function __construct(
        private readonly ImportMapRepository $entries,
    ) {
    }

    /**
     * Runs $body with this map narrowed to one organisation, and restores
     * whatever was in force afterwards.
     *
     * The tenant rule used to be shape-only: carriesTenant() asks whether a
     * key starts with a digit run and a colon, which "2024:cohort-a" does,
     * with no tenant in it at all, and which "9:school-01" does on a job
     * stamped organisation 7. Both were accepted, and the second is the exact
     * cross-tenant collision the prefix exists to prevent, wearing the prefix.
     * Comparing the prefix to the run's own organisation is the check that was
     * meant; knowing the run's organisation is what it needed.
     *
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    public function duringRun(?int $organisationId, callable $body): mixed
    {
        $previous = $this->runOrganisationId;
        $this->runOrganisationId = $organisationId !== null && $organisationId > 0 ? $organisationId : null;

        try {
            return $body();
        } finally {
            $this->runOrganisationId = $previous;
        }
    }

    /**
     * Records that this importer's source object is now that local object.
     * Calling it again for the same source object re-points the existing row.
     *
     * @param string $importer   the importer key, matching the provider key
     * @param string $sourceType what the source system calls it ("course",
     *                           "activity", "cq_quiz")
     * @param string $sourceKey  the source system's stable identity
     * @param string $localType  the core object type ("course", "module",
     *                           "lesson", "quiz", "question", "entitlement")
     * @param int    $localId    the core row id
     * @param int    $jobId      the import job that did it, 0 outside a job
     */
    public function remember(
        string $importer,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
        int $jobId = 0,
    ): void {
        $importer = $this->requireShort('importer', $importer, 50);
        $sourceType = $this->requireShort('source_type', $sourceType, 50);
        $localType = $this->requireShort('local_type', $localType, 50);

        if (trim($sourceKey) === '') {
            throw ValidationException::forField('source_key', 'A source key is required.');
        }

        $this->requireTenant($sourceType, $sourceKey);

        if ($localId < 1) {
            throw ValidationException::forField('local_id', 'A positive local id is required.');
        }

        if ($jobId < 0) {
            throw ValidationException::forField('job_id', 'A job id may not be negative.');
        }

        $this->entries->remember(
            $importer,
            $sourceType,
            $this->normaliseKey($sourceKey),
            $localType,
            $localId,
            $jobId,
        );
    }

    /**
     * The local id this importer already created for that source object, or
     * null when it has not seen it.
     */
    public function lookup(string $importer, string $sourceType, string $sourceKey): ?int
    {
        return $this->entry($importer, $sourceType, $sourceKey)?->localId;
    }

    /**
     * The whole mapping row, for callers that need the local type as well as
     * the id.
     */
    public function entry(string $importer, string $sourceType, string $sourceKey): ?ImportMapEntry
    {
        if (trim($sourceKey) === '') {
            return null;
        }

        $this->requireTenant($sourceType, $sourceKey);

        return $this->entries->find($importer, $sourceType, $this->normaliseKey($sourceKey));
    }

    /**
     * Everything one job created or re-pointed, oldest first. The admin
     * screen shows this as the manifest of a run.
     *
     * @return list<ImportMapEntry>
     */
    public function forJob(int $jobId): array
    {
        return $jobId < 1 ? [] : $this->entries->forJob($jobId);
    }

    /**
     * Where a local object came from, or null when it was authored here.
     */
    public function provenanceOf(string $localType, int $localId): ?ImportMapEntry
    {
        return $localId < 1 ? null : $this->entries->forLocal($localType, $localId);
    }

    /**
     * Folds an over-long source key onto a stable digest so it still fits the
     * column. Short keys pass through unchanged, which keeps stored keys
     * readable for the common case.
     */
    public function normaliseKey(string $sourceKey): string
    {
        $sourceKey = trim($sourceKey);

        if (\strlen($sourceKey) <= self::KEY_LIMIT) {
            return $sourceKey;
        }

        return substr($sourceKey, 0, self::KEY_LIMIT - 41) . ':' . sha1($sourceKey);
    }

    /**
     * Refuses an organisation scoped mapping whose key does not name its
     * organisation, on the way in and on the way out.
     *
     * The unique index is (importer, source_type, source_key) and it is global
     * across the site, so an unprefixed `school-01` under source_type org_unit
     * is one row for every district that exports that sourcedId. The lookup
     * side is guarded as well as the write side, and that is the half that
     * matters: a mapper that looked up an unprefixed key would be answered
     * with another tenant's local id and would then update that tenant's row
     * without ever calling remember() with a bad key at all.
     *
     * Only the reserved organisation vocabulary is guarded. An importer whose
     * objects are courses, activities and questions keeps its own words and is
     * untouched by this.
     *
     * @throws ValidationException when a reserved type carries no tenant prefix
     */
    private function requireTenant(string $sourceType, string $sourceKey): void
    {
        if (!ImportSourceKey::isReserved($sourceType)) {
            return;
        }

        $carried = ImportSourceKey::organisationOf($sourceKey);

        // A reserved type outside a run that names an organisation has no
        // tenant to be checked against, and accepting one on the shape of its
        // prefix is how "2024:cohort-a" became organisation 2024. The runner
        // already refuses to start a DeclaresSourceTypes importer with no
        // organisation; this is the same rule for the mapper that did not
        // declare.
        if ($this->runOrganisationId === null) {
            throw ValidationException::forField('source_key', sprintf(
                'The "%s" source type is organisation scoped and this run names no organisation, so there is no'
                    . ' tenant to compose its key with. Start it from the organisation\'s own import screen,'
                    . ' where the organisation comes from the run context and never from the file.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $sourceType,
            ));
        }

        if ($carried === $this->runOrganisationId) {
            return;
        }

        throw ValidationException::forField('source_key', sprintf(
            'The "%s" source type is organisation scoped, so its key is minted as "%d:{foreign key}" through'
                . ' ImportSourceKey::forOrganisation() with the organisation this run belongs to. The'
                . ' organisation comes from the run context and never from the file.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $sourceType,
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $this->runOrganisationId,
        ));
    }

    private function requireShort(string $field, string $value, int $limit): string
    {
        $value = trim($value);

        if ($value === '' || \strlen($value) > $limit) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('A non-empty value of at most %d characters is required.', $limit),
            );
        }

        return $value;
    }
}
