<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleInterface;

/**
 * One of the free build's organisation roles.
 *
 * The four differ only in the rights they carry, so they are one parameterised
 * provider rather than four near-identical classes. The owner extends this and
 * adds the ownership marker, because owning is a capability interface probed
 * with instanceof rather than a flag every third-party role must answer.
 *
 * All four operate under one host capability and nothing else, and that is the
 * whole reason the four shipped site-wide use cases refuse an organisation
 * administrator without a single core edit: a null resource resolves to the
 * component asset, so a second capability granted there would make an org
 * admin indistinguishable from a site-wide holder of it.
 */
class CoreOrgRole implements OrgRoleInterface
{
    /** The family every core role sits in, for the picker's ordering. */
    public const GROUP = 'organisation';

    /** @param list<string> $actions */
    public function __construct(
        private readonly string $name,
        private readonly array $actions,
        private readonly int $position,
        private readonly bool $mappable,
    ) {
    }

    /** Manages people, seats, catalogue entries and reports, and nothing above them. */
    public static function administrator(): self
    {
        return new self('org_admin', ['events', 'learning', 'people', 'read', 'seats'], 20, true);
    }

    /** Reads reports and the event history, and writes nothing at all. */
    public static function reporter(): self
    {
        return new self('org_reporter', ['events', 'read'], 30, true);
    }

    /**
     * Membership and nothing beyond it.
     *
     * It exists as a role rather than as an absence so that a membership row
     * always names a role, which gives an unmapped provisioning assertion a
     * safe landing instead of a null.
     */
    public static function member(): self
    {
        return new self('org_member', [], 40, true);
    }

    public function key(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'org.role.' . $this->name;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        // A role carrying no right inside the organisation operates under no
        // capability either: the holder is a member and not a delegated
        // administrator of anything.
        return $this->actions === [] ? [] : Capability::organisationSurface();
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function permits(string $action): bool
    {
        return \in_array($action, $this->actions, true);
    }

    public function grantableByMappingRule(): bool
    {
        return $this->mappable;
    }

    /**
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        // The free four take no parameters. The schema is still declared and
        // still closed, because a provider that accepts anything is how an
        // unvalidated payload reaches a stored column.
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function group(): string
    {
        return self::GROUP;
    }

    public function order(): int
    {
        return $this->position;
    }
}
