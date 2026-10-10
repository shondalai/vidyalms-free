<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;

/**
 * The course category, which is what a compliance programme usually calls its
 * subject area.
 */
final class CategoryNameElement extends TokenTextElement
{
    public function key(): string
    {
        return 'category_name';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_IDENTITY;
    }

    public function order(): int
    {
        return 30;
    }

    protected function tokenName(): string
    {
        return 'course.category';
    }

    protected function defaultSize(): int
    {
        return 14;
    }
}
