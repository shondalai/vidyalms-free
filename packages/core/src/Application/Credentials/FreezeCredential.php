<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatRegistry;

/**
 * The machine-readable credential, built once and stored on the row.
 *
 * The rendered artefact was frozen and the credential was not, which is the
 * design's headline failure reproduced one layer up: the document a wallet
 * dereferences was rebuilt from live rows on every request, so a course
 * rename, an issuer settings edit or a package upgrade changed the bytes
 * served under a stable URL. For a signed format the consequence is worse
 * than an inefficiency, because a proof covers a specific document and a
 * wallet re-fetching a year later would get one that no longer verifies.
 *
 * It writes credential_json, credential_format and proof_type, which is what
 * those three columns exist for from day one: adding a signed format is then
 * a registration and not a migration, and the argument is backed by a writer
 * rather than asserted.
 *
 * A build that throws is not allowed to lose the certificate. The freeze runs
 * inside the issuance transaction, so a format provider that refuses (a
 * signed format with no key configured, say) would roll the row back; the
 * refusal is caught and the columns stay null, which is exactly what a row
 * issued before this existed looks like and which GetCredential already
 * handles by rebuilding and saying so.
 */
final class FreezeCredential
{
    /**
     * The settings row a site names its frozen format in. Absent, the build
     * chooses its own highest-ranked storable format.
     */
    public const SETTING_FORMAT = 'credentials.frozen_format';

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CredentialFormatRegistry $formats,
        private readonly CredentialAssembly $assembly,
        /**
         * Which format is frozen. Null means the build's own first choice,
         * which in the free build is the unsigned JSON. A site that installs
         * a signing package names its key here and every credential issued
         * from then on is frozen signed.
         */
        private readonly ?string $formatKey = null,
    ) {
    }

    public function execute(Certificate $certificate): void
    {
        $format = $this->format();

        if ($format === null) {
            return;
        }

        try {
            $artefact = $format->build(
                $this->assembly->recordFor($certificate),
                $this->assembly->contextFor($certificate),
            );

            if (!RenderedArtefact::isTextual($artefact->mediaType())) {
                // Belt and braces behind the selection: a format that
                // declares a textual type and returns bytes anyway would take
                // the whole issuance down with it.
                return;
            }

            $this->certificates->storeCredential(
                $certificate->id,
                $artefact->bytes(),
                $artefact->formatKey,
                $artefact->proofType,
            );
        } catch (\Throwable) {
            // A format that cannot build on this site, or a store that
            // refuses what it produced, leaves the columns null rather than
            // losing the certificate. The write is inside the try for the
            // same reason the build is: this runs inside the issuance
            // transaction, so an escaping driver error rolls the certificate
            // back, the outer handler finds no row, and every retry and every
            // backfill sweep takes the same path for ever. The read path
            // rebuilds and reports that it did.
            return;
        }
    }

    /**
     * Which format is frozen into credential_json.
     *
     * The column is MEDIUMTEXT, so only a format whose artefact is text can
     * be frozen at all. The registry's own highest preference is taken from
     * among those: two of the six shipped formats are binary and one of them
     * is a signed one, so a site that configured the PAdES key and not the
     * Ed25519 one had application/pdf as its highest-ranked available format
     * and wrote raw PDF bytes into a utf8mb4 column. A binary credential is
     * served by building on demand, never frozen.
     */
    private function format(): ?CredentialFormatInterface
    {
        if ($this->formatKey !== null && $this->formats->has($this->formatKey)) {
            $named = $this->formats->get($this->formatKey);

            return RenderedArtefact::isTextual($named->mimeType()) ? $named : null;
        }

        // The build's own highest-ranked storable format, not whichever
        // registered first: a site that installs a signing package freezes
        // signed.
        return $this->formats->preferredStorable();
    }
}
