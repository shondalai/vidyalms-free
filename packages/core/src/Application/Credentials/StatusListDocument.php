<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusList;

/**
 * The BitstringStatusListCredential a verifier actually dereferences.
 *
 * The published verifier algorithm is: dereference statusListCredential,
 * verify its proofs, confirm the retrieved list's statusPurpose matches the
 * entry's, decompress, read the bit. A document with no issuer, no id and no
 * VerifiableCredential type fails at step one, and that is what the route
 * served: the conformant document was built inside the paid publisher and no
 * caller anywhere resolved it, so the route answered the shorter shape
 * whether the package was installed or not.
 *
 * So the builder is here, in the core, where the route is. It needs the row
 * plus two base URLs and the issuer, all of which the kernel already holds.
 * A publisher fills in encodedList; this decides how the bytes are dressed.
 */
final class StatusListDocument
{
    public const TYPE_CREDENTIAL = 'BitstringStatusListCredential';
    public const TYPE_LIST = 'BitstringStatusList';
    public const CONTEXT_VC_V2 = 'https://www.w3.org/ns/credentials/v2';

    /**
     * The issuer identifier a document falls back to when the site has
     * configured none. A URN is not dereferenceable and says so, which is
     * better than an empty string that is not an IRI at all.
     */
    public const FALLBACK_ISSUER = 'urn:vidyalms:issuer';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $proof the securing mechanism a publisher produced, when it has one
     *
     * @return array<string, mixed>
     */
    public static function build(
        CredentialStatusList $list,
        string $statusListBaseUrl,
        string $issuerUrl,
        ?array $proof = null,
    ): array {
        $id = $statusListBaseUrl === ''
            ? 'urn:vidyalms:status-list:' . $list->listUuid
            : rtrim($statusListBaseUrl, '/') . '/' . rawurlencode($list->listUuid);

        $document = [
            '@context' => [self::CONTEXT_VC_V2],
            'id' => $id,
            // VerifiableCredential is claimed only when a securing mechanism
            // is actually attached. The verifier algorithm's second step is
            // "verify its proofs", and an unsigned document that says it is a
            // VerifiableCredential invites a wallet to attempt a verification
            // it must then report as failed, which is our fault rather than
            // the wallet's. That is the same decision vidyalms.json already
            // makes about its own type, applied to the document the
            // credential points at.
            'type' => $proof === null
                ? [self::TYPE_CREDENTIAL]
                : ['VerifiableCredential', self::TYPE_CREDENTIAL],
            'issuer' => $issuerUrl === '' ? self::FALLBACK_ISSUER : $issuerUrl,
            'validFrom' => ($list->publishedAt ?? new \DateTimeImmutable('@0'))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'credentialSubject' => [
                'id' => $id . '#list',
                'type' => self::TYPE_LIST,
                'statusPurpose' => $list->purpose,
                'encodedList' => $list->encodedList ?? '',
            ],
            'ttl' => $list->ttlMs,
        ];

        if ($proof !== null) {
            $document['proof'] = $proof;
        }

        return $document;
    }
}
