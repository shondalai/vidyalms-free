<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interop;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;

/**
 * The neutral item a reader parses a foreign format into, and an exporter
 * writes back out of.
 *
 * No question type ever sees Moodle XML, GIFT, Canvas, D2L, Blackboard or
 * Community Quiz. The mapper layer is the only place in the codebase where
 * those words may appear, and this is the boundary it hands across.
 */
final class CanonicalItem
{
    /**
     * @param array<string, mixed>       $constraints
     * @param array<string, mixed>       $key
     * @param array<string, mixed>       $mapping
     * @param list<array<string, mixed>> $feedback
     * @param list<string>               $layoutClasses
     */
    public function __construct(
        public readonly string $primitive,
        public readonly Surface $surface,
        public readonly ResponseShape $shape,
        public readonly string $prompt,
        public readonly array $constraints = [],
        public readonly array $key = [],
        public readonly array $mapping = [],
        public readonly array $feedback = [],
        public readonly array $layoutClasses = [],
    ) {
    }
}
