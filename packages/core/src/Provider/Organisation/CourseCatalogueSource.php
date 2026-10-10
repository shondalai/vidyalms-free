<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue\CourseGrant;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceInterface;

/**
 * One released course, named explicitly.
 *
 * The simplest source there is, and the one that proves the seam is a seam:
 * the entry's `course_id` column carries the course, the provider reads it off
 * the context, and the caller still runs the release policy over the answer.
 * A provider that returned the whole site catalogue here would grant exactly
 * nothing, because reachability is decided by the site owner's release record
 * and never by what a source says.
 */
final class CourseCatalogueSource implements OrgCatalogueSourceInterface
{
    public const KEY = 'course';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.catalogue_source.course';
    }

    /**
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://vidyalms.org/schema/org-catalogue/course/1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['course_id'],
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
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
        $courseId = $context->integerParam('course_id');

        if ($courseId === null) {
            return [];
        }

        // The published check and nothing else. Whether this organisation may
        // reach the course is the caller's question, asked through
        // CourseReleasePolicy over every grant this returns, and asking it
        // here as well would be the same rule with two implementations.
        return $context->reader->publishedCourseIds([$courseId]) === []
            ? []
            : [CourseGrant::forEntry($context, $courseId)];
    }
}
