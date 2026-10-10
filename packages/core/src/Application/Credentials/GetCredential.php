<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Provider\Credential\OpenBadges2AssertionFormat;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatRegistry;

/**
 * The machine-readable credential, under content negotiation.
 *
 * The URLs exist now even though the free build serves only unsigned formats,
 * so they are stable before anything embeds one in a QR code printed on
 * paper. A format this build cannot serve is absent from negotiation and
 * never an error inside a format: the caller answers with what it can serve,
 * which is the only honest response to a wallet asking a free site for a
 * signed document.
 *
 * **The stored credential wins.** A row that froze its credential at issue is
 * served those bytes when the negotiated format is the one it froze, so the
 * document a wallet dereferenced last year is the document it dereferences
 * today, whatever has happened to the course title or the issuer settings
 * since. A format that was not the one frozen is built on demand, and the
 * caller is told that it was: the record of what the holder was given is the
 * frozen one.
 */
final class GetCredential
{
    public const SETTING_ISSUER = CredentialAssembly::SETTING_ISSUER;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CredentialFormatRegistry $formats,
        private readonly CredentialAssembly $assembly,
    ) {
    }

    /**
     * @param list<string> $accepted the media types the caller asked for, in preference order
     *
     * @return CredentialArtefact|null null when this build serves none of the accepted types
     */
    public function execute(string $uuid, array $accepted = [], ?string $formatKey = null): ?CredentialArtefact
    {
        $certificate = $this->certificates->findByUuid($uuid);

        if ($certificate === null) {
            return null;
        }

        $format = $formatKey === null ? $this->negotiate($accepted, $certificate)
            : ($this->formats->has($formatKey) ? $this->formats->get($formatKey) : null);

        if ($format === null) {
            return null;
        }

        $context = $this->assembly->contextFor($certificate);
        $status = $certificate->statusAt($context->now);
        if (
            \in_array($status, [CredentialStatus::Suspended, CredentialStatus::NotYetValid], true)
            || ($format->key() !== OpenBadges2AssertionFormat::KEY
                && \in_array($status, [CredentialStatus::Revoked, CredentialStatus::Superseded], true))
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ConflictException::with('This credential is not currently valid.', ['credential_status' => $status->value]);
        }
        $stored = $this->storedArtefact($certificate, $format);

        if ($stored !== null) {
            return $stored;
        }

        return $format->build(
            $this->assembly->recordFor($certificate),
            $context,
        );
    }

    /**
     * Whether the bytes this certificate would be served came out of the
     * freeze rather than out of a build over live rows.
     *
     * An administrator has to be able to find the rows that answer from live
     * state, exactly as they can for the rendered artefact, because those are
     * the rows whose document can still change under a holder.
     */
    public function servesFrozenCredential(Certificate $certificate, ?CredentialFormatInterface $format = null): bool
    {
        $format ??= $this->negotiate([], $certificate);

        return $format !== null && $this->storedArtefact($certificate, $format) !== null;
    }

    /**
     * What this build can serve, which a 406 names rather than leaving the
     * caller to guess.
     *
     * @return list<string>
     */
    public function servableMediaTypes(): array
    {
        return $this->formats->mediaTypes();
    }

    public function findByUuid(string $uuid): ?Certificate
    {
        return $this->certificates->findByUuid($uuid);
    }

    /**
     * The frozen bytes, when they are the ones this caller asked for.
     */
    private function storedArtefact(Certificate $certificate, CredentialFormatInterface $format): ?CredentialArtefact
    {
        $json = $certificate->credentialJson;

        if ($json === null || $certificate->credentialFormat !== $format->key() || $format->key() === OpenBadges2AssertionFormat::KEY) {
            return null;
        }

        if ($format->key() === VidyaLmsJsonFormat::KEY) {
            // Also repair pre-fix frozen unsigned documents on read without
            // changing their stored issuance facts or signed credentials.
            $body = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (\is_array($body) && isset($body['credentialStatus'])) {
                unset($body['credentialStatus']);
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
        }

        return CredentialArtefact::of(
            RenderedArtefact::of(
                $json,
                $format->mimeType(),
                // The artefact shape's own table, not a fourth copy of it:
                // this one knew four media types and defaulted the rest to
                // json, so a baked SVG credential downloaded as .json.
                'credential-' . $certificate->code . '.' . RenderedArtefact::extensionFor($format->mimeType()),
            ),
            $format->key(),
            $certificate->proofType,
        );
    }

    /**
     * @param list<string> $accepted
     */
    private function negotiate(array $accepted, Certificate $certificate): ?CredentialFormatInterface
    {
        foreach ($accepted as $mediaType) {
            $format = $this->formats->forMediaType(trim($mediaType), $certificate->credentialFormat);

            if ($format !== null) {
                return $format;
            }
        }

        if ($accepted === [] || \in_array('*/*', array_map('trim', $accepted), true)) {
            // No preference is answered by the format this row froze, so a
            // caller that expresses none gets the document of record rather
            // than whichever provider registered first.
            $frozen = $certificate->credentialFormat;

            if ($frozen !== null && $this->formats->has($frozen)) {
                return $this->formats->get($frozen);
            }

            return $this->formats->preferred();
        }

        return null;
    }
}
