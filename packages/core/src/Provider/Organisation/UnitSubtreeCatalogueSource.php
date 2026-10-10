<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue\CourseGrant;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceInterface;

/**
 * Every course assigned to a unit and its descendants.
 *
 * A department's own catalogue rolled up to whoever needs to see it, which is
 * what an organisation chart is for. The unit's courses are the entries
 * already written against that unit, so an administrator maintains one list
 * per department and a subtree entry above them reaches all of it without
 * anybody restating a course in two places.
 *
 * **The subtree is resolved by the caller and handed over, never walked here.**
 * The reach of a unit is a tenancy question, and a provider that walked the
 * closure table itself would be a provider deciding how far its own entry
 * reaches. What arrives on the context is already bounded by this
 * organisation, so the worst a hostile source can do with it is reach less.
 *
 * **It does not resolve through another subtree entry.** The reader is asked
 * to exclude this source's own key, so two subtree entries pointing at each
 * other's units resolve to their courses and stop, rather than to each other
 * forever. One level of indirection is the whole feature; a second is a cycle.
 */
final class UnitSubtreeCatalogueSource implements OrgCatalogueSourceInterface
{
    public const KEY = 'unit-subtree';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.catalogue_source.unit_subtree';
    }

    /**
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://vidyalms.org/schema/org-catalogue/unit-subtree/1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['org_unit_id'],
            'properties' => [
                'org_unit_id' => ['type' => 'integer', 'minimum' => 1],
                'include_descendants' => ['type' => 'boolean', 'default' => true],
            ],
        ];
    }

    public function paramsVersion(): int
    {
        return 1;
    }

    /**
     * @return list<CourseGrant>
     */
    public function resolve(OrgCatalogueContext $context): array
    {
        $rootUnitId = $context->integerParam('org_unit_id');

        if ($rootUnitId === null || !\in_array($rootUnitId, $context->unitSubtree, true)) {
            // A unit that is not in the subtree the caller resolved is a unit
            // of somebody else's organisation, or one that has since been
            // removed. Either way the entry reaches nothing, which is the
            // fail-closed direction rather than a refusal a screen has to
            // catch.
            return [];
        }

        $units = $context->booleanParam('include_descendants', true)
            ? $context->unitSubtree
            : [$rootUnitId];

        $grants = [];
        $seen = [];

        foreach ($context->reader->courseIdsAssignedToUnits($units, [self::KEY]) as $row) {
            if (isset($seen[$row['course_id']])) {
                continue;
            }

            $seen[$row['course_id']] = true;
            $grants[] = CourseGrant::forEntry($context, $row['course_id'], $row['unit_id']);
        }

        return $grants;
    }
}
