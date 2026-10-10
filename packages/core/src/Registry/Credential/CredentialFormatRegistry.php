<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credential;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;

/**
 * @extends AbstractProviderRegistry<CredentialFormatInterface>
 */
final class CredentialFormatRegistry extends AbstractProviderRegistry
{
    /**
     * What a format that declares no preference is worth. The free baseline
     * sits here, and a securing mechanism declares something higher.
     */
    public const BASELINE_PREFERENCE = 0;

    protected function providerInterface(): string
    {
        return CredentialFormatInterface::class;
    }

    /**
     * The format whose media type an Accept header asks for, or null when
     * this build serves none of them.
     *
     * Three shipped formats share `application/ld+json`, because a signed
     * Open Badges 3.0 credential and an unsigned one really are the same
     * media type. First match wins meant registration order was the whole
     * selection policy, and the free build registers first, so installing a
     * signing package changed nothing a relying party could observe: the
     * paid formats were unreachable under the one Accept header a wallet
     * sends. So the highest declared preference wins, and the row's own
     * frozen format wins over that, because a credential is served as the
     * document it was issued as.
     *
     * A miss is not an error here: the caller answers 406 naming what it can
     * serve, which is the only honest answer to a wallet asking for a signed
     * shape from a build that cannot sign.
     */
    public function forMediaType(string $mediaType, ?string $preferKey = null): ?CredentialFormatInterface
    {
        $best = null;
        $bestPreference = PHP_INT_MIN;

        foreach ($this->all() as $format) {
            if (strcasecmp($format->mimeType(), $mediaType) !== 0 || !self::isAvailable($format)) {
                continue;
            }

            if ($preferKey !== null && $format->key() === $preferKey) {
                return $format;
            }

            $preference = self::preferenceOf($format);

            if ($preference > $bestPreference) {
                $best = $format;
                $bestPreference = $preference;
            }
        }

        return $best;
    }

    /**
     * The format this build answers with when the caller expresses no
     * preference at all: the highest declared preference, and registration
     * order among equals.
     */
    public function preferred(): ?CredentialFormatInterface
    {
        $best = null;
        $bestPreference = PHP_INT_MIN;

        foreach ($this->all() as $format) {
            if (!self::isAvailable($format)) {
                continue;
            }

            $preference = self::preferenceOf($format);

            if ($preference > $bestPreference) {
                $best = $format;
                $bestPreference = $preference;
            }
        }

        return $best;
    }

    /**
     * The highest-preference available format whose artefact is text, which
     * is the only kind credential_json can hold.
     *
     * It is a separate question from preferred(): two of the six shipped
     * formats are binary, so the best format to serve is not always a format
     * that can be frozen into a MEDIUMTEXT column.
     */
    public function preferredStorable(): ?CredentialFormatInterface
    {
        $best = null;
        $bestPreference = PHP_INT_MIN;

        foreach ($this->all() as $format) {
            if (!self::isAvailable($format) || !RenderedArtefact::isTextual($format->mimeType())) {
                continue;
            }

            $preference = self::preferenceOf($format);

            if ($preference > $bestPreference) {
                $best = $format;
                $bestPreference = $preference;
            }
        }

        return $best;
    }

    /**
     * Every media type this build can serve, each named once. Three formats
     * under one media type is one thing a caller can ask for, and a 406 body
     * listing it three times reads as a defect.
     *
     * @return list<string>
     */
    public function mediaTypes(): array
    {
        return array_values(array_unique(array_map(
            static fn (CredentialFormatInterface $format): string => $format->mimeType(),
            array_values(array_filter($this->all(), static fn (CredentialFormatInterface $f): bool
                => self::isAvailable($f))),
        )));
    }

    private static function preferenceOf(CredentialFormatInterface $format): int
    {
        return $format instanceof DeclaresFormatPreference
            ? $format->preference()
            : self::BASELINE_PREFERENCE;
    }

    /**
     * Whether this format can serve on this site right now.
     *
     * A signing format with no key configured used to throw out of build(),
     * escape the use case and reach the caller as a 500, so a site with the
     * package installed and no key was worse off than a free site, which
     * correctly answered 406. A format that cannot serve is a negotiation
     * miss, exactly like one that is not installed.
     */
    private static function isAvailable(CredentialFormatInterface $format): bool
    {
        return !$format instanceof ReportsAvailability || $format->isAvailable();
    }
}
