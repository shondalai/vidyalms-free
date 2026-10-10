<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * Who taught it. The token resolves the course instructors as one presentable
 * string, because an element that formatted a list itself would be a second
 * answer to a question the token layer already answers.
 */
final class InstructorNameElement extends TokenTextElement
{
    public function key(): string
    {
        return 'instructor_name';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_IDENTITY;
    }

    public function order(): int
    {
        return 40;
    }

    protected function tokenName(): string
    {
        return 'course.instructors';
    }

    protected function defaultSize(): int
    {
        return 14;
    }
}
