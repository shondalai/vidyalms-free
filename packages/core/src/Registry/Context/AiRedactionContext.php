<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a redactor may read while it redacts.
 *
 * It carries **the keys** of the facts the task declared and never their
 * values. A redactor is already handed the resolved payload, which is where
 * the values live; handing them a second time through the context would put
 * the same learner data in two places and make the manifest ambiguous about
 * which copy left the site.
 *
 * The site reference is what a pseudonymising redactor salts with, so two
 * sites never mint the same pseudonym for the same person.
 */
final class AiRedactionContext
{
    /** @var list<string> */
    public readonly array $declaredFactKeys;

    /**
     * @param list<string> $declaredFactKeys the keys the task declared, never the values
     */
    private function __construct(
        public readonly string $redactionLevel,
        array $declaredFactKeys,
        public readonly string $siteRef,
        public readonly string $locale,
    ) {
        $this->declaredFactKeys = $declaredFactKeys;
    }

    /**
     * @param list<string> $declaredFactKeys
     */
    public static function create(
        string $redactionLevel,
        string $siteRef,
        array $declaredFactKeys = [],
        string $locale = 'en-GB',
    ): self {
        return new self($redactionLevel, $declaredFactKeys, $siteRef, $locale);
    }

    public function declares(string $factKey): bool
    {
        return \in_array($factKey, $this->declaredFactKeys, true);
    }
}
