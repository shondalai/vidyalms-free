<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleInterface;

/**
 * A third-party role with something to say in its payload.
 *
 * `OrgRoleInterface` has declared `paramsSchema()` and `currentShape()` since
 * its first release and no route on any host could write one, so this is the
 * first thing that proves the column has a writer.
 */
final class ParameterisedTestRole implements OrgRoleInterface
{
    public const KEY = 'acme.group_lead';

    /** Above one, so the stored version can be told from the seam's own constant. */
    public const SHAPE = 3;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'acme.org_role.group_lead';
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
        return ['read'];
    }

    public function permits(string $action): bool
    {
        return $action === 'read';
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
            '$id' => 'https://acme.test/schema/org-role/group-lead/1',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['group_ids' => ['type' => 'array']],
        ];
    }

    public function currentShape(): int
    {
        return self::SHAPE;
    }

    public function group(): string
    {
        return 'organisation';
    }

    public function order(): int
    {
        return 90;
    }
}
