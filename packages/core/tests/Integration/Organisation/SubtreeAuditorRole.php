<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\ReachesUnits;

/**
 * A role that declares the events right as one it exercises over a subtree.
 *
 * This is the shape the Pro unit manager has, written here so the core's own
 * suite holds the case rather than borrowing a package's. It is what turned
 * three organisation-wide reads behind the `events` right from safe-by-accident
 * into a leak: while the free reach literal excluded that right, nothing with a
 * unit anchor could ask for them at all.
 */
final class SubtreeAuditorRole implements OrgRoleInterface, ReachesUnits
{
    public const KEY = 'acme.subtree_auditor';

    /** @var list<string> */
    private const ACTIONS = ['events', 'read'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'acme.org_role.subtree_auditor';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return Capability::organisationSurface();
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return self::ACTIONS;
    }

    public function permits(string $action): bool
    {
        return \in_array($action, self::ACTIONS, true);
    }

    /**
     * @return list<string>
     */
    public function unitScopedActions(): array
    {
        return self::ACTIONS;
    }

    public function grantableByMappingRule(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://acme.test/schema/org-role/subtree-auditor/1',
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
        return 'organisation';
    }

    public function order(): int
    {
        return 95;
    }
}
