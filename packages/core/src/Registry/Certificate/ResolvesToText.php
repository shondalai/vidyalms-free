<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * The element is a resolved string, so a text element can reuse it and the
 * credential builder can read it without rendering markup.
 *
 * Default when absent: the element cannot be reused inside a text element.
 */
interface ResolvesToText
{
    /**
     * @param array<string, mixed> $payload
     */
    public function resolveText(array $payload, ElementRenderContext $context): ?string;
}
