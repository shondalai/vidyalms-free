<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * A connection's ordered rule list, as one versioned stored shape.
 *
 * The order is the semantics: rules are evaluated in the order they were
 * written and the first one carrying stop ends the evaluation. So the list is
 * a list and never a map, and a save that reordered it would change what the
 * connection grants without changing a single rule.
 *
 * The stored envelope carries the version, on StoredShape's own terms. A row
 * written before the envelope existed reads as version 0 and grants nothing:
 * there is no such row today, and saying so here is cheaper than discovering
 * it later.
 */
final class MappingRuleSet
{
    /** The shape this build writes. */
    public const SHAPE_VERSION = 1;

    /**
     * The most rules one connection may carry.
     *
     * A bound rather than a preference. Evaluation runs on every assertion, so
     * an unbounded list is an unbounded cost on the login path, and a hundred
     * rules is already more than any administrator can reason about.
     */
    public const MAX_RULES = 100;

    /** @param list<MappingRule> $rules */
    private function __construct(public readonly array $rules, public readonly int $version)
    {
    }

    /** The empty set, which grants nothing and is what a connection starts with. */
    public static function empty(): self
    {
        return new self([], self::SHAPE_VERSION);
    }

    /**
     * The set an administrator saved, refused rather than repaired.
     *
     * @param list<array<string, mixed>> $rules
     */
    public static function of(array $rules): self
    {
        if (\count($rules) > self::MAX_RULES) {
            throw ValidationException::forField(
                'mapping_rules',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'A connection carries at most ' . self::MAX_RULES . ' rules.'
            );
        }

        $parsed = [];

        foreach (array_values($rules) as $index => $row) {
            $parsed[] = MappingRule::fromStored($row, $index);
        }

        return new self($parsed, self::SHAPE_VERSION);
    }

    /**
     * The set as stored, envelope and all.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $version = StoredShape::versionOf($stored);
        $body = StoredShape::bodyOf($stored);
        $rows = $body['rules'] ?? [];

        if ($version !== self::SHAPE_VERSION || !\is_array($rows)) {
            // A shape this build cannot read grants nothing, which is the
            // fail-closed direction the role registry already publishes. It is
            // not an exception: an unreadable rule set must not stop a person
            // signing in, it must stop them being granted anything by it.
            return new self([], $version);
        }

        $parsed = [];

        foreach (array_values($rows) as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }

            try {
                $parsed[] = MappingRule::fromStored($row, $index);
            } catch (ValidationException) {
                // Same direction, per rule: a row that no longer parses is
                // dropped from the set rather than making the whole set
                // unreadable, and the evaluator records the count.
                continue;
            }
        }

        return new self($parsed, $version);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return StoredShape::wrap([
            'rules' => array_map(static fn (MappingRule $rule): array => $rule->toStored(), $this->rules),
        ], self::SHAPE_VERSION);
    }

    /** Whether this build understands the shape that was stored. */
    public function isReadable(): bool
    {
        return $this->version === self::SHAPE_VERSION;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    public function count(): int
    {
        return \count($this->rules);
    }
}
