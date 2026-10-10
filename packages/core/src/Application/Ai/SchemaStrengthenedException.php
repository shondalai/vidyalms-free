<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * A narrowing that could reject a document the type's own schema accepts.
 *
 * This is the one thing AiSchemaNarrower refuses, and it is an exception
 * rather than a refusal value because it is programmer error: a bridge or a
 * caller that strengthened a definition schema has silently changed what the
 * authoring contract accepts, and there is no run outcome that describes it
 * honestly. A refusal would put a corrupted authoring rule on a review screen
 * as if it were a model outage.
 */
final class SchemaStrengthenedException extends \RuntimeException
{
    public static function at(string $path, string $reason): self
    {
        return new self(sprintf(
            'The narrowed schema is stronger than the original at %s: %s.',
            $path === '' ? 'the root' : $path,
            $reason,
        ));
    }

    public static function unknownKeyword(string $path, string $keyword): self
    {
        return new self(sprintf(
            'The schema at %s declares the keyword %s, which the definition checker does not understand, '
            . 'so no narrowing decision can be made about it.',
            $path === '' ? 'the root' : $path,
            $keyword,
        ));
    }
}
