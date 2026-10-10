<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\IssuerIdentity;
use Shondalai\VidyaLMS\Core\Application\Credentials\VerifyCertificate;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /certificates/{code}/verify
 *
 * Public by design: guests may verify, and an unknown code is an ordinary
 * negative answer, never an error, so probing reveals nothing structural.
 *
 * Verification is not validation, and this route must not imply otherwise.
 * VC 2.0 defines verification as checking that the document conforms, that
 * the securing mechanism is satisfied and that the status check succeeds, and
 * says plainly that it does not imply evaluation of the truth of the claims.
 * Whether a completion means the holder can do the job is the relying party's
 * problem, and the payload says so in as many words.
 *
 * status leads with the six-value enumeration under the published precedence,
 * while revoked and expired stay independent booleans beside it: the two axes
 * really are independent, and a certificate revoked in 2026 whose expiry
 * passes in 2028 has to report both facts without either overwriting the
 * other.
 */
final class VerifyCertificateHandler implements HandlerInterface
{
    public function __construct(
        private readonly VerifyCertificate $verifyCertificate,
        private readonly ?Settings $settings = null,
        /**
         * Who issued it, resolved from the settings record over the host
         * facts. Absent, the raw settings record answers and an unconfigured
         * site reports no issuer at all rather than an empty one.
         */
        private readonly ?IssuerIdentity $issuer = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $verification = $this->verifyCertificate->execute($request->principal, $request->pathParam('code'));

        return ApiResponse::ok([
            'status' => $verification->statusValue(),
            'valid' => $verification->valid,
            'reason' => $verification->reason,
            'revoked' => $verification->revoked,
            'expired' => $verification->expired,
            // RFC 3339 rather than the product's own "Y-m-d H:i:s". This is
            // the one endpoint a relying party parses without our SDK, and
            // the credential document about the same subject already uses
            // validFrom and validUntil in RFC 3339, so one credential was
            // being described two ways. The rest of the contract is
            // unchanged: NullableDateTime stays what every authenticated
            // route answers.
            'revoked_at' => Presenter::instant($verification->revokedAt),
            'course_title' => $verification->courseTitle,
            'learner_name' => $verification->learnerName,
            'issued_at' => Presenter::instant($verification->issuedAt),
            'completed_at' => Presenter::instant($verification->completedAt),
            'valid_from' => Presenter::instant($verification->validFrom),
            'expires_at' => Presenter::instant($verification->expiresAt),
            'issuer' => $this->issuer(),
            'credits' => array_map(
                static fn (CertificateCredit $credit): array => [
                    'scheme' => $credit->scheme,
                    'amount' => $credit->amount,
                    'unit' => $credit->unit,
                    'field_of_study' => $credit->fieldOfStudy,
                ],
                $verification->credits,
            ),
            'credential_url' => $verification->credentialUrl,
            'superseded_by_url' => $verification->supersededByUrl,
        ]);
    }

    /**
     * Who issued it, which is a site fact and one of the six things this page
     * is allowed to disclose. Never an email, a user id, an enrolment id or
     * the internal certificate id.
     *
     * The contract declares this an object with name and url required, or
     * null. It used to be a bare PHP array, which json_encode writes as `[]`
     * when it is empty, so an unconfigured site answered `"issuer": []` and
     * contradicted its own schema and the generated SDK's type on every
     * request. Now the identity always resolves a name, so the object is
     * always an object and `url` is present as null when the site has
     * published no address.
     *
     * @return array<string, mixed>|null
     */
    private function issuer(): ?array
    {
        if ($this->issuer !== null) {
            $resolved = $this->issuer->resolve();

            return ['name' => $resolved['name'], 'url' => $resolved['url'] === '' ? null : $resolved['url']];
        }

        $issuer = $this->settings?->getArray('certificates.issuer', []) ?? [];
        $name = $issuer['name'] ?? null;

        if (!\is_string($name) || $name === '') {
            return null;
        }

        $url = $issuer['url'] ?? null;

        return ['name' => $name, 'url' => \is_string($url) && $url !== '' ? $url : null];
    }
}
