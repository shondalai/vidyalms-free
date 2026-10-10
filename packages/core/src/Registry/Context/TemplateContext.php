<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an element reads when it is asked whether it may be offered on this
 * template.
 *
 * This is mod_customcert's can_add(), and it exists so a fixed palette is not
 * a lie: the credit statement has nothing to say on a template attached to no
 * credit scheme, and offering it there produces an element that renders an
 * empty line on every certificate the site ever issues.
 */
final class TemplateContext
{
    /**
     * @param list<string> $existingElements the element keys already on this template
     * @param list<string> $creditSchemes    the schemes any course using this template carries credit under
     */
    private function __construct(
        public readonly ?int $templateId,
        public readonly string $format,
        public readonly string $renderer,
        public readonly string $achievementType,
        public readonly ?string $locale,
        public readonly array $existingElements,
        public readonly array $creditSchemes,
    ) {
    }

    /**
     * @param list<string> $existingElements
     * @param list<string> $creditSchemes
     */
    public static function create(
        ?int $templateId = null,
        string $format = 'html',
        string $renderer = 'html',
        string $achievementType = 'CertificateOfCompletion',
        ?string $locale = null,
        array $existingElements = [],
        array $creditSchemes = [],
    ): self {
        return new self(
            $templateId,
            $format,
            $renderer,
            $achievementType,
            $locale,
            $existingElements,
            $creditSchemes,
        );
    }

    public function hasElement(string $key): bool
    {
        return \in_array($key, $this->existingElements, true);
    }

    public function hasAnyCreditScheme(): bool
    {
        return $this->creditSchemes !== [];
    }
}
