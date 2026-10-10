<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an element reads while checking its own payload at save time.
 *
 * It carries the token names the site can actually resolve, so an element
 * holding {cohort.starts_at} on a build with no cohort resolver is a
 * save-time warning rather than a placeholder that survives to paper. It
 * deliberately cannot see its siblings: validate() is per element, which is
 * exactly why the rule that a template needs a readable verification
 * affordance is enforced by a template-level publication check and not hoped
 * for here.
 */
final class ElementValidationContext
{
    /**
     * @param list<string> $availableTokens full placeholder names, without braces
     */
    private function __construct(
        public readonly string $elementKey,
        public readonly ?int $templateId,
        public readonly ?int $pageId,
        public readonly array $availableTokens,
        public readonly string $locale,
    ) {
    }

    /**
     * @param list<string> $availableTokens
     */
    public static function create(
        string $elementKey,
        ?int $templateId = null,
        ?int $pageId = null,
        array $availableTokens = [],
        string $locale = 'en-GB',
    ): self {
        return new self($elementKey, $templateId, $pageId, $availableTokens, $locale);
    }

    /**
     * Every placeholder in the given text that no registered resolver owns.
     * An unknown token is never an error on its own, because the miss
     * direction for a token is to leave it intact; it is a warning, which is
     * what the designer draws.
     *
     * @return list<string>
     */
    public function unresolvedTokens(string $text): array
    {
        if (preg_match_all('/\{([a-z0-9_]+\.[a-z0-9_]+)\}/', $text, $matches) !== false) {
            $found = array_values(array_unique($matches[1]));

            return array_values(array_filter(
                $found,
                fn (string $token): bool => !\in_array($token, $this->availableTokens, true),
            ));
        }

        return [];
    }
}
