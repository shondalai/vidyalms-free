<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\ItemContentFact;

/**
 * Reaches the recursive secret-key removal ItemContentFact does its redaction
 * with, so the rule can be exercised over a definition shape no shipped
 * question type produces.
 *
 * The rule is about a REGISTERED type nesting its correctness key, a per-option
 * `correct` or a per-blank `accepted`, and the shipped six all keep theirs at
 * the top level. Building a whole stub type to reach one pure function would
 * be testing the harness rather than the rule.
 */
final class ItemContentFactProbe
{
    /**
     * @param array<array-key, mixed> $definition
     * @param list<string>            $keys
     *
     * @return array<array-key, mixed>
     */
    public static function withoutKeys(array $definition, array $keys): array
    {
        $method = new \ReflectionMethod(ItemContentFact::class, 'withoutKeys');
        $method->setAccessible(true);

        /** @var array<array-key, mixed> */
        return $method->invoke(null, $definition, $keys);
    }
}
