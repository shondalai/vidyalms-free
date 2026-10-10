<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * A reason key plus its parameters, never a rendered sentence.
 *
 * The core owns no locale, so the React apps and both adapters translate.
 * That is the anti-corruption boundary between a paid condition and the UI,
 * and it is why this is a value object rather than a string.
 */
final class AvailabilityReason
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly string $key,
        public readonly array $parameters = [],
    ) {
    }

    /**
     * @return array{key: string, parameters: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'parameters' => $this->parameters];
    }
}
