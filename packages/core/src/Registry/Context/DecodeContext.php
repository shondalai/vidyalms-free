<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * The locale and the resolved shape a primitive decodes against.
 *
 * Decoding is locale sensitive and the earlier design handed the locale to
 * the client renderer only: a de-DE learner types "5,5" and a numeric mask
 * hardcoding a dot separator rejects a correctly written answer.
 */
final class DecodeContext
{
    private function __construct(
        public readonly ResponseShape $shape,
        public readonly string $locale,
    ) {
    }

    public static function create(ResponseShape $shape, string $locale = 'en-GB'): self
    {
        return new self($shape, $locale);
    }

    /**
     * The decimal separator of the context locale. Kept here rather than in
     * a type so every numeric-bearing primitive agrees.
     */
    public function decimalSeparator(): string
    {
        $language = strtolower(substr($this->locale, 0, 2));

        return \in_array($language, ['de', 'fr', 'es', 'it', 'pt', 'nl', 'ru', 'pl', 'tr', 'sv', 'da', 'fi', 'cs'], true)
            ? ','
            : '.';
    }
}
