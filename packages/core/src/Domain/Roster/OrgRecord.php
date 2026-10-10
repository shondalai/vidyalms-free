<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * One organisation or unit of the tree the source describes.
 *
 * It is the canonical shape of a OneRoster `orgs.csv` row and of whatever a
 * later source calls the same thing. The parent is named by the parent's own
 * foreign key rather than by an id, because the file is loaded before anything
 * exists here and the order inside the package is the exporter's choice.
 *
 * ## Which organisation this becomes
 *
 * None of them becomes an organisation. Every record here becomes an
 * **org_unit** inside the organisation the run context names, because the
 * organisation comes from the run context and never from the file. A record
 * with no parent is a unit at the top of that organisation's own tree, not a
 * second organisation beside it: a roster load that could create organisations
 * would be a roster load that could create tenants.
 */
final class OrgRecord
{
    private function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly ?string $type,
        public readonly ?string $parentExternalId,
        public readonly ?string $identifier,
    ) {
    }

    public static function of(
        string $externalId,
        string $name,
        ?string $type = null,
        ?string $parentExternalId = null,
        ?string $identifier = null,
    ): self {
        return new self(
            trim($externalId),
            trim($name),
            self::text($type),
            self::text($parentExternalId),
            self::text($identifier),
        );
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
