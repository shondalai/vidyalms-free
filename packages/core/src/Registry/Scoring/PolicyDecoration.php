<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * The vocabulary a decorating policy is written in, in one place both sides
 * read.
 *
 * A decorator wraps a base policy named in its own parameters, declares shape
 * `any` so it is applicable wherever that base is, and scales or adjusts what
 * the base produced. The parameter name and the depth bound are contract
 * rather than implementation: a type building a ScoringKey has to know which
 * base a decorator resolves to, so the literal `base` appearing in two
 * packages is how the two drift into disagreeing about which key shape to
 * build, and handing a policy the wrong shape grades every response as not
 * scored rather than failing.
 */
final class PolicyDecoration
{
    /** Where a decorator names the policy it wraps inside policy.params. */
    public const PARAM_BASE = 'base';

    /**
     * How deep a chain of decorators may run before it is treated as a cycle.
     * A base naming another decorator is legitimate; two naming each other is
     * a stack overflow in a grading endpoint rather than a wrong mark.
     */
    public const MAX_DEPTH = 4;

    private function __construct()
    {
    }

    /**
     * The base key a decorator's parameters name, or its own default.
     *
     * @param array<string, mixed> $params
     */
    public static function baseKeyIn(array $params, string $default): string
    {
        $base = $params[self::PARAM_BASE] ?? null;

        if (\is_array($base) && \is_string($base['key'] ?? null) && $base['key'] !== '') {
            return $base['key'];
        }

        return $default;
    }
}
