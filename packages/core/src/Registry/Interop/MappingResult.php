<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interop;

/**
 * What a mapper hands back: a payload and the warnings that go with it.
 *
 * Warnings rather than exceptions, because a lossy import that lands with a
 * visible warning list is far more useful to a migrating teacher than a hard
 * failure, and because a warning naming the size of the change is what tells
 * them a mark moved.
 */
final class MappingResult
{
    /** @var list<string> */
    public readonly array $warnings;

    /**
     * @param list<string> $warnings
     */
    private function __construct(
        public readonly ?CanonicalItem $item,
        public readonly mixed $payload,
        array $warnings,
    ) {
        $this->warnings = array_values($warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function canonical(CanonicalItem $item, array $warnings = []): self
    {
        return new self($item, null, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function foreign(mixed $payload, array $warnings = []): self
    {
        return new self(null, $payload, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function unmapped(array $warnings): self
    {
        return new self(null, null, $warnings);
    }

    public function succeeded(): bool
    {
        return $this->item !== null || $this->payload !== null;
    }
}
