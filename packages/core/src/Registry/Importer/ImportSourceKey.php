<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * The reserved source_type vocabulary, and the one composition every
 * organisation scoped source_key is minted by.
 *
 * ## Why the composition cannot be left to each mapper
 *
 * import_map's unique index is (importer, source_type, source_key) and it is
 * global across the site, while a foreign natural key is unique only inside
 * the system that issued it. Two districts exporting from one student
 * information system, or two subsidiaries of one company, routinely carry
 * identical values: `school-01`, `emp-001`, `SIS-1`. Without the tenant in
 * the key, organisation B's run looks up
 * (oneroster-csv-1.2:in, org_unit, school-01), finds organisation A's row,
 * and concludes it has already created that unit. Under create_and_update it
 * then writes B's roster into A's tree; under create_only it silently skips
 * B's row and reports success. The idempotency acceptance test passes in both
 * cases, because the mapping it asserts on is exactly the one that is wrong.
 *
 * So the composition is stated once, here, in the importer contract, rather
 * than in each mapper's own head:
 *
 * ```
 * source_key = '{organisation_id}:{foreign natural key}'
 * ```
 *
 * ImportMap::remember() and ImportMap::lookup() refuse a reserved source_type
 * whose key does not carry it. A third-party roster mapper therefore cannot
 * get this wrong by omission, which is the only way anyone would get it
 * wrong: nobody omits a tenant prefix on purpose, they omit it because their
 * source system has one namespace and theirs is the only tenant they tested.
 *
 * ## What the foreign key may be
 *
 * A OneRoster `sourcedId`, a SCIM `externalId`, a Canvas `user_id`: the
 * source system's own permanent identity for the object. **Never an email
 * address**, because an address is reassignable and is optional in a
 * conformant users.csv. Where a source genuinely has no stable key the
 * importer derives one and says so in a warning, exactly as JsonCourseImporter
 * already does with its content digest.
 *
 * ## The vocabulary is reserved, not exhaustive
 *
 * The eight types below belong to the organisation surface and carry the
 * tenant rule. An importer whose objects are none of those keeps using its
 * own words (`cq_quiz`, `activity`, `question`) with no prefix and no change,
 * which is why the three shipped importers are untouched by this. Declaring
 * which types a mapper mints is what DeclaresSourceTypes is for.
 */
final class ImportSourceKey
{
    public const TYPE_ORG = 'org';
    public const TYPE_ORG_UNIT = 'org_unit';
    public const TYPE_ORG_MEMBER = 'org_member';
    public const TYPE_ORG_ROLE = 'org_role';
    public const TYPE_INVITATION = 'invitation';
    public const TYPE_SEAT = 'seat';
    public const TYPE_USER = 'user';
    public const TYPE_CATALOGUE_ENTRY = 'catalogue_entry';

    /**
     * The reserved vocabulary, in the order the spec publishes it. Every one
     * of them is organisation scoped, and every one of them therefore takes
     * the tenant prefix.
     *
     * @var list<string>
     */
    public const RESERVED_TYPES = [
        self::TYPE_ORG,
        self::TYPE_ORG_UNIT,
        self::TYPE_ORG_MEMBER,
        self::TYPE_ORG_ROLE,
        self::TYPE_INVITATION,
        self::TYPE_SEAT,
        self::TYPE_USER,
        self::TYPE_CATALOGUE_ENTRY,
    ];

    /** The separator between the tenant and the foreign key. */
    public const SEPARATOR = ':';

    private function __construct()
    {
    }

    /**
     * The source_key for one foreign object inside one organisation.
     *
     * @throws \InvalidArgumentException on a non-positive organisation or an empty foreign key
     */
    public static function forOrganisation(int $organisationId, string $foreignKey): string
    {
        if ($organisationId < 1) {
            throw new \InvalidArgumentException(
                'An organisation scoped source key needs the organisation it belongs to, and the run context is'
                    . ' where it comes from, never the file.',
            );
        }

        $foreignKey = trim($foreignKey);

        if ($foreignKey === '') {
            throw new \InvalidArgumentException(
                'An organisation scoped source key needs the source system\'s own identity for the object.',
            );
        }

        return $organisationId . self::SEPARATOR . $foreignKey;
    }

    /** Whether this source_type is one the organisation surface reserves. */
    public static function isReserved(string $sourceType): bool
    {
        return \in_array($sourceType, self::RESERVED_TYPES, true);
    }

    /**
     * Whether this key already carries a tenant prefix.
     *
     * A digit run, a colon and something after it. A key whose foreign part
     * happens to start with digits and a colon of its own is still correctly
     * prefixed, because the first colon is the one this composition writes
     * and everything after it is opaque.
     */
    public static function carriesTenant(string $sourceKey): bool
    {
        return self::organisationOf($sourceKey) !== null;
    }

    /**
     * The organisation a composed key belongs to, or null when it carries
     * none. Reading the key back is what lets a retention sweep prune an
     * organisation's mappings under that organisation's own policy.
     */
    public static function organisationOf(string $sourceKey): ?int
    {
        if (preg_match('/^([1-9]\d*):(.+)$/s', trim($sourceKey), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * The foreign key back out of a composed one, or the whole string when it
     * carries no tenant.
     */
    public static function foreignKeyOf(string $sourceKey): string
    {
        $sourceKey = trim($sourceKey);

        return preg_match('/^([1-9]\d*):(.+)$/s', $sourceKey, $matches) === 1 ? $matches[2] : $sourceKey;
    }
}
