<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interop;

/**
 * The definition a binding wrote, and what it could not carry into it.
 *
 * The policy travels beside the definition rather than inside it because the
 * caller decides whether the type supports it: a source's own rule may be one
 * this type refuses, and the importer downgrades it and says so rather than
 * writing a definition the type will reject.
 */
final class BindingResult
{
    /** @var array<string, mixed> */
    public readonly array $definition;

    /** @var list<string> */
    public readonly array $warnings;

    /**
     * @param array<string, mixed>      $definition
     * @param list<string>              $warnings
     * @param array<string, mixed>|null $policy
     */
    private function __construct(
        array $definition,
        array $warnings,
        public readonly ?array $policy,
        public readonly ?float $points,
    ) {
        $this->definition = $definition;
        $this->warnings = array_values($warnings);
    }

    /**
     * @param array<string, mixed>      $definition
     * @param list<string>              $warnings
     * @param array<string, mixed>|null $policy
     */
    public static function of(
        array $definition,
        array $warnings = [],
        ?array $policy = null,
        ?float $points = null,
    ): self {
        return new self($definition, $warnings, $policy, $points);
    }

    /**
     * @param list<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self($this->definition, [...$this->warnings, ...$warnings], $this->policy, $this->points);
    }
}
