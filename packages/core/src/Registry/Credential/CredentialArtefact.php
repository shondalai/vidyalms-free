<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credential;

use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;

/**
 * What a credential format produced.
 *
 * It is an artefact and not a string from the first release, because two of
 * the six formats are binary and one of those two is free: a baked PNG is
 * image/png and a PAdES document is application/pdf, and a build(): string
 * would have to lie about both. There is no compatibility cost today,
 * because no implementation exists outside this package, and the signature is
 * load-bearing in two packages the day one does.
 *
 * It reuses the rendered artefact shape rather than defining a second one, so
 * a certificate document and a credential document are stored, checksummed
 * and served by the same code.
 */
final class CredentialArtefact
{
    private function __construct(
        public readonly RenderedArtefact $artefact,
        public readonly string $formatKey,
        public readonly ?string $proofType,
    ) {
    }

    public static function of(RenderedArtefact $artefact, string $formatKey, ?string $proofType = null): self
    {
        return new self($artefact, $formatKey, $proofType);
    }

    public function bytes(): string
    {
        return $this->artefact->bytes;
    }

    public function mediaType(): string
    {
        return $this->artefact->mediaType;
    }

    /**
     * True when the format carries no securing mechanism, which neither free
     * format does. It is asked rather than inferred, because an unsigned
     * document that claims to be verifiable is worse than one that claims
     * nothing.
     */
    public function isUnsigned(): bool
    {
        return $this->proofType === null;
    }
}
