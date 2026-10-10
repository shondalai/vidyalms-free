<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * The course total the learner achieved.
 *
 * It is a token like everything else, which keeps the element out of the
 * gradebook: an element that computed a total would be a second answer to a
 * question the progress context already owns, and the two would disagree the
 * first time a regrade landed.
 */
final class GradeElement extends TokenTextElement
{
    public function key(): string
    {
        return 'grade';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_OUTCOME;
    }

    public function order(): int
    {
        return 10;
    }

    protected function tokenName(): string
    {
        return 'course.grade';
    }

    protected function defaultSize(): int
    {
        return 14;
    }
}
