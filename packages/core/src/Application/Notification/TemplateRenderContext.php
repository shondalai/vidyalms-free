<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;

/** Values used for a strict synthetic preview or a real delivery render. */
final class TemplateRenderContext
{
    /**
     * @param array<string, string> $syntheticValues
     */
    private function __construct(
        public readonly array $syntheticValues,
        public readonly ?TokenContext $tokenContext,
        public readonly bool $strict,
        public readonly string $locale,
    ) {
    }

    /** @param array<string, string> $values keyed by exact dotted or bare token */
    public static function synthetic(array $values, string $locale = 'en-GB'): self
    {
        return new self($values, null, true, $locale);
    }

    public static function real(TokenContext $context): self
    {
        return new self([], $context, false, $context->locale);
    }

    /** Publication preserves placeholders after a separate synthetic validation pass. */
    public static function publication(string $locale = 'en-GB'): self
    {
        return new self([], null, false, $locale);
    }

    public function preservesPlaceholders(): bool
    {
        return $this->tokenContext === null && !$this->strict;
    }
}
