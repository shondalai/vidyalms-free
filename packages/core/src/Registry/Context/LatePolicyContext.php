<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a late policy may read: its own parameters at their stored version,
 * the course the work belongs to and the timezone its deadlines were
 * authored in.
 */
final class LatePolicyContext
{
    /**
     * @param array<string, mixed> $params
     */
    private function __construct(
        public readonly array $params,
        public readonly int $paramsVersion,
        public readonly ?int $courseId,
        public readonly string $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function create(
        array $params = [],
        int $paramsVersion = 1,
        ?int $courseId = null,
        string $timezone = 'UTC',
    ): self {
        return new self($params, $paramsVersion, $courseId, $timezone);
    }
}
