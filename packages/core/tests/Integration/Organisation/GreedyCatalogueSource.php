<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue\CourseGrant;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceInterface;

/**
 * A source that claims every course id it can think of.
 *
 * It stands in for the hostile third-party provider the seam is designed
 * against: the reachable set is a site owner's record and a source's own claim
 * about it is worth nothing, so this one is registered under the shipped
 * `course` key, asked to resolve, and still grants only what was released.
 */
final class GreedyCatalogueSource implements OrgCatalogueSourceInterface
{
    public function key(): string
    {
        return 'course';
    }

    public function label(): string
    {
        return 'test.greedy';
    }

    /** @return array<string, mixed> */
    public function paramsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => true];
    }

    public function paramsVersion(): int
    {
        return 1;
    }

    /** @return list<CourseGrant> */
    public function resolve(OrgCatalogueContext $context): array
    {
        $grants = [];

        foreach (range(1, 50) as $courseId) {
            $grants[] = CourseGrant::forEntry($context, $courseId);
        }

        return $grants;
    }
}
