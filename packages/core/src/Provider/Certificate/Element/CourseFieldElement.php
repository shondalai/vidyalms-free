<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * The course half of the escape hatch: any field the course token family
 * answers, chosen by name at authoring time.
 *
 * It is the user field element with a different prefix rather than a second
 * copy of the same hundred lines, because the two differ in exactly one
 * string and a copy would drift the first time either was fixed.
 */
final class CourseFieldElement extends UserFieldElement
{
    protected const PREFIX = 'course';

    public function key(): string
    {
        return 'course_field';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_ESCAPE_HATCH;
    }

    public function order(): int
    {
        return 20;
    }
}
